<?php
/**
 * generate-insights.php  (Gemini version — for free testing)
 * --------------------------------------------------------------
 * Same pipeline as before, just swapped the LLM call to Google's
 * Gemini API instead of Claude, since Gemini currently offers a
 * genuinely free tier (no card required) via Google AI Studio.
 *
 * To switch back to Claude later, only the "CALL THE LLM" section
 * below needs to change — the data aggregation and DB storage stay
 * exactly the same either way.
 * --------------------------------------------------------------
 */

session_start();
require_once __DIR__ . '/../database/env.php';
require_once __DIR__ . '/../database/db.php';

// ---- Gemini API key: get one free at https://aistudio.google.com/app/apikey ----
$GEMINI_API_KEY = getenv('GEMINI_API_KEY') ?: '';

header('Content-Type: application/json');

// ---------------------------------------------------------------
// Resolve restro_key: from session (button click) or CLI arg (cron)
// ---------------------------------------------------------------
$restro_key = null;
if (php_sapi_name() === 'cli') {
    $opts = getopt('', ['restro_key:']);
    $restro_key = $opts['restro_key'] ?? null;
} else {
    if (empty($_SESSION['restro_key'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $restro_key = $_SESSION['restro_key'];
    session_write_close();
}

if (!$restro_key) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'restro_key missing']);
    exit;
}

if (!$GEMINI_API_KEY) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'GEMINI_API_KEY not configured on server']);
    exit;
}

// ---------------------------------------------------------------
// 1. AGGREGATE DATA (30-day window — adjust to your real table/column
//    names if they differ from analytics.php's schema)
// ---------------------------------------------------------------

$WINDOW_DAYS = 30;

// Revenue by day-of-week
$stmt = mysqli_prepare($conn, "
    SELECT DAYNAME(ordered_at) AS dow, SUM(total_amount) AS revenue, COUNT(*) AS orders
    FROM orders
    WHERE restro_key = ? AND ordered_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY DAYNAME(ordered_at), DAYOFWEEK(ordered_at)
    ORDER BY DAYOFWEEK(ordered_at)
");
mysqli_stmt_bind_param($stmt, 'si', $restro_key, $WINDOW_DAYS);
mysqli_stmt_execute($stmt);
$revenue_by_day = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Orders by hour bucket
$hour_buckets = [
    '12-2 PM' => [12, 13],
    '2-5 PM'  => [14, 15, 16],
    '5-7 PM'  => [17, 18],
    '7-9 PM'  => [19, 20],
    '9-11 PM' => [21, 22],
];
$bucket_counts = array_fill_keys(array_keys($hour_buckets), 0);

$stmt = mysqli_prepare($conn, "
    SELECT HOUR(ordered_at) AS hr, COUNT(*) AS cnt
    FROM orders
    WHERE restro_key = ? AND ordered_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY HOUR(ordered_at)
");
mysqli_stmt_bind_param($stmt, 'si', $restro_key, $WINDOW_DAYS);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $hr = (int) $row['hr'];
    foreach ($hour_buckets as $label => $hours) {
        if (in_array($hr, $hours, true)) {
            $bucket_counts[$label] += (int) $row['cnt'];
            break;
        }
    }
}
mysqli_stmt_close($stmt);

// All menu items with performance in the window (not just top 5 —
// this lets the LLM also spot LOW performers, for "drop this dish" style cards)
$stmt = mysqli_prepare($conn, "
    SELECT oi.item_name,
           SUM(oi.quantity)   AS units_sold,
           SUM(oi.line_total) AS revenue
    FROM order_items oi
    JOIN orders o ON o.order_key = oi.order_key AND o.restro_key = oi.restro_key
    WHERE oi.restro_key = ? AND o.ordered_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY oi.item_name
    ORDER BY revenue DESC
");
mysqli_stmt_bind_param($stmt, 'si', $restro_key, $WINDOW_DAYS);
mysqli_stmt_execute($stmt);
$all_items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Overall totals for the window
$stmt = mysqli_prepare($conn, "
    SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue
    FROM orders
    WHERE restro_key = ? AND ordered_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
");
mysqli_stmt_bind_param($stmt, 'si', $restro_key, $WINDOW_DAYS);
mysqli_stmt_execute($stmt);
$totals = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$total_orders  = (int) $totals['orders'];
$total_revenue = (float) $totals['revenue'];
$avg_order     = $total_orders > 0 ? round($total_revenue / $total_orders) : 0;

// ---------------------------------------------------------------
// Holiday / event calendar — maintain this list yourself and keep it updated.
// ---------------------------------------------------------------
$holidays = [
    "2026-08-15" => "Independence Day",
    "2026-08-28" => "Raksha Bandhan",
    "2026-10-20" => "Diwali",
    "2026-11-05" => "Bhai Dooj",
];

$today = new DateTime();
$upcoming_holidays = [];
foreach ($holidays as $date => $name) {
    $d = new DateTime($date);
    $diff = (int) $today->diff($d)->format('%r%a');
    if ($diff >= 0 && $diff <= 45) {
        $upcoming_holidays[$date] = $name;
    }
}

// ---------------------------------------------------------------
// 2. BUILD COMPACT SUMMARY FOR THE LLM
// ---------------------------------------------------------------
$summary = [
    'window_days'        => $WINDOW_DAYS,
    'total_orders'       => $total_orders,
    'total_revenue_inr'  => $total_revenue,
    'avg_order_value_inr'=> $avg_order,
    'revenue_by_day_of_week' => $revenue_by_day,
    'orders_by_hour_bucket'  => $bucket_counts,
    'menu_items'         => $all_items,
    'upcoming_holidays'  => $upcoming_holidays,
];

$data_json = json_encode($summary, JSON_PRETTY_PRINT);

// ---------------------------------------------------------------
// 3. CALL GEMINI API
// ---------------------------------------------------------------

$system_prompt = <<<PROMPT
You are a restaurant business analyst generating insight cards for a restaurant
owner's dashboard. You will be given aggregated sales data as JSON.

IMPORTANT HONESTY RULES — follow strictly:
- If total_orders is below 50, do NOT make specific price-change or demand-elasticity
  claims. Data that thin cannot support those conclusions. Instead give only
  descriptive/directional insights (e.g. "your best day so far is X, consider
  promoting it") and say the data volume is still building.
- Never invent numbers, trends, or patterns not supported by the data you were given.
- Never suggest a specific price increase unless total_orders is at least 200 AND
  the item in question has at least 30 orders in the window. Even then, phrase it
  as a suggestion for the owner to test, not a certainty.
- If upcoming_holidays is non-empty, consider it when relevant (e.g. promotions,
  staffing, or combo suggestions tied to that date).
- Prioritize insights that are useful and low-risk over speculative ones.

Return ONLY a JSON array (no markdown fences, no preamble, no explanation text
outside the array) of 4 to 6 insight card objects, each with exactly these fields:
  "icon": a single emoji
  "title": short imperative headline, max 8 words
  "description": one sentence explaining the reasoning, max 20 words
  "impact": short predicted outcome string, e.g. "Expected orders +15%" or
            "Reduce waste ~9%" — omit specific numeric predictions if data is thin,
            use qualitative phrasing instead (e.g. "Likely to improve turnout")
  "card_type": "warn" if it flags a problem/risk, otherwise "normal"
PROMPT;

// Gemini's REST API shape differs from Claude's:
// - system prompt goes in "systemInstruction", not a top-level "system" field
// - user content goes in "contents" -> "parts" -> "text"
// - generationConfig.responseMimeType forces pure JSON output (Gemini-specific
//   feature — this helps avoid the markdown-fence problem entirely)
$payload = [
    "systemInstruction" => [
        "parts" => [["text" => $system_prompt]]
    ],
    "contents" => [
        [
            "role" => "user",
            "parts" => [["text" => "Sales data:\n" . $data_json]]
        ]
    ],
    "generationConfig" => [
        "temperature" => 0.4,
        "maxOutputTokens" => 1500,
        "responseMimeType" => "application/json"
    ]
];

// Use a model available to new Google AI Studio keys (2.5-flash-lite returns 404).
$models_to_try = ['gemini-3.1-flash-lite', 'gemini-flash-lite-latest', 'gemini-3-flash-preview'];
$model = $models_to_try[0];
$response = null;
$http_code = 0;
$curl_err = '';
$last_error_body = '';

foreach ($models_to_try as $try_model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$try_model}:generateContent?key={$GEMINI_API_KEY}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        break;
    }
    if ($http_code === 200) {
        $model = $try_model;
        break;
    }
    $last_error_body = $response;
    // Try next model on 404/503; stop on auth/quota errors.
    if (!in_array($http_code, [404, 503], true)) {
        break;
    }
}

if ($curl_err) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'API request failed: ' . $curl_err]);
    exit;
}

if ($http_code !== 200) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Gemini API returned HTTP ' . $http_code, 'model_tried' => $model, 'raw' => $last_error_body ?: $response]);
    exit;
}

$data = json_decode($response, true);

// Gemini's response shape: candidates[0].content.parts[0].text
$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

// Defensive cleanup — strip markdown fences just in case, even though
// responseMimeType=application/json should prevent this
$text = trim($text);
$text = preg_replace('/^```json\s*|\s*```$/', '', $text);
$text = preg_replace('/^```\s*|\s*```$/', '', $text);

$cards = json_decode($text, true);

if (!is_array($cards)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Could not parse LLM response as JSON', 'raw_text' => $text, 'raw_response' => $response]);
    exit;
}

// ---------------------------------------------------------------
// 4. STORE IN DATABASE (replace old insights for this restaurant)
// ---------------------------------------------------------------
$stmt = mysqli_prepare($conn, "DELETE FROM ai_insights WHERE restro_key = ?");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$insert_stmt = mysqli_prepare($conn, "
    INSERT INTO ai_insights (restro_key, icon, title, description, impact, card_type, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$order = 0;
$saved = 0;
foreach ($cards as $card) {
    if (!isset($card['title'], $card['description'], $card['impact'])) {
        continue; // skip malformed entries rather than failing the whole batch
    }
    $icon        = $card['icon'] ?? '💡';
    $title       = mb_substr($card['title'], 0, 150);
    $description = mb_substr($card['description'], 0, 300);
    $impact      = mb_substr($card['impact'], 0, 100);
    $card_type   = ($card['card_type'] ?? 'normal') === 'warn' ? 'warn' : 'normal';

    mysqli_stmt_bind_param(
        $insert_stmt, 'ssssssi',
        $restro_key, $icon, $title, $description, $impact, $card_type, $order
    );
    mysqli_stmt_execute($insert_stmt);
    $order++;
    $saved++;
}
mysqli_stmt_close($insert_stmt);

echo json_encode([
    'success' => true,
    'cards_saved' => $saved,
    'total_orders_analyzed' => $total_orders,
    'data_note' => $total_orders < 50
        ? 'Low order volume — insights are directional only, not statistical predictions.'
        : 'ok'
]);