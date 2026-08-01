<?php
session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (empty($_SESSION['restro_key'])) {
    header('Location: sign-in.php');
    exit;
}

$restro_key = $_SESSION['restro_key'];
session_write_close();

extract(restro_load_nav($conn, $restro_key));
$current_page = 'ai-insights';

// Load stored insights + when they were last generated
$stmt = mysqli_prepare($conn, "
    SELECT icon, title, description, impact, card_type, created_at
    FROM ai_insights
    WHERE restro_key = ?
    ORDER BY sort_order ASC
");
mysqli_stmt_bind_param($stmt, 's', $restro_key);
mysqli_stmt_execute($stmt);
$insights = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$last_generated = null;
if (!empty($insights)) {
    $last_generated = $insights[0]['created_at'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Insights — RestroAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
  .refresh-btn {
    background: linear-gradient(135deg, #ff5a1f, #ff1f4c);
    color: #fff; border: none; border-radius: 8px;
    padding: 8px 16px; font-family: 'Inter', sans-serif; font-size: 13px;
    font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    transition: opacity 0.2s;
  }
  .refresh-btn:disabled { opacity: 0.5; cursor: not-allowed; }
  .insights-meta {
    font-family: 'IBM Plex Mono', monospace; font-size: 11.5px; color: #8a8a8a;
    margin-top: 6px;
  }
  .empty-insights {
    padding: 48px 20px; text-align: center; color: #8a8a8a; font-family: 'Inter', sans-serif;
  }
</style>
</head>
<body>

<div id="bg-glow"></div>

<div class="shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div><h1>AI Insights</h1><div class="sub">Recommendations generated from your live order data</div></div>
      <div class="topbar-right">
        <div class="search-box">🔍 Search orders, dishes…</div>
        <div class="icon-btn">🔔<span class="dot"></span></div>
        <div class="restaurant-pill"><span class="dot"></span>Open — Dine-in</div>
      </div>
    </div>

    <div class="content">

      <div class="panel">
        <div class="panel-inner-head">
          <div class="title">AI Restaurant Brain</div>
          <div style="display:flex; align-items:center; gap:12px;">
            <button class="refresh-btn" id="refreshBtn" onclick="refreshInsights()">↻ Refresh Insights</button>
            <div class="live-pill" id="statusPill">ANALYZING</div>
          </div>
        </div>

        <?php if ($last_generated): ?>
        <div class="insights-meta" style="padding: 0 20px;">Last generated: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($last_generated))); ?></div>
        <?php endif; ?>

        <div id="insightsGrid" class="ai-panel-body" style="display:grid;grid-template-columns:repeat(2,1fr);gap:0 8px;">
          <?php if (empty($insights)): ?>
            <div class="empty-insights" style="grid-column: 1 / -1;">
              No insights generated yet. Click "Refresh Insights" once you have some order history.
            </div>
          <?php else: ?>
            <?php foreach ($insights as $card): ?>
            <div class="ai-card<?php echo $card['card_type'] === 'warn' ? ' warn' : ''; ?>">
              <span class="kicker"><?php echo htmlspecialchars($card['icon']); ?></span>
              <h4><?php echo htmlspecialchars($card['title']); ?></h4>
              <p><?php echo htmlspecialchars($card['description']); ?></p>
              <span class="impact"><?php echo htmlspecialchars($card['impact']); ?></span>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
async function refreshInsights() {
  const btn = document.getElementById('refreshBtn');
  const pill = document.getElementById('statusPill');
  const grid = document.getElementById('insightsGrid');

  btn.disabled = true;
  btn.textContent = '↻ Generating…';
  pill.textContent = 'ANALYZING';

  try {
    const res = await fetch('generate-insights.php', { method: 'POST' });
    const data = await res.json();

    if (data.success) {
      // Reload the page to show freshly stored insights from the DB
      window.location.reload();
    } else {
      pill.textContent = 'ERROR';
      alert('Could not generate insights: ' + (data.error || 'Unknown error'));
      btn.disabled = false;
      btn.textContent = '↻ Refresh Insights';
    }
  } catch (err) {
    pill.textContent = 'ERROR';
    alert('Request failed: ' + err.message);
    btn.disabled = false;
    btn.textContent = '↻ Refresh Insights';
  }
}
</script>

</body>
</html>