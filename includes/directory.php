<?php
/**
 * Shared helpers for the public restaurant directory.
 *
 * The directory is the only part of Dinetous meant to be crawled by
 * Google, so everything here is about stable URLs and clean metadata:
 * a slug per restaurant (/r/spice-bazaar) and a normalized city slug
 * (/restaurants/bikaner) that groups reliably even though `city` was
 * historically free text.
 */

/** Absolute base URL of the install, e.g. http://localhost/restai */
function site_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Walk up from whichever script is running to the project root.
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    foreach (['/Restro', '/admin', '/waiter_panel'] as $sub) {
        if (substr($script, -strlen($sub)) === $sub) {
            $script = substr($script, 0, -strlen($sub));
            break;
        }
    }
    return $scheme . '://' . $host . rtrim($script, '/');
}

/** Lowercase, hyphenated, ASCII-safe slug. */
function slugify(string $text): string {
    $text = trim($text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = strtolower($text);
    // Drop apostrophes rather than turning them into separators, so
    // "Nitin's Kitchen" becomes nitins-kitchen, not nitin-s-kitchen.
    $text = str_replace(["'", '’', '`'], '', $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Cities we let restaurants pick from. Free-text city names can't be
 * grouped (Jodhpur vs jodhpur vs "Jodhpur, Rajasthan"), and a directory
 * lives or dies on that grouping.
 */
function directory_cities(): array {
    return [
        'Agra', 'Ahmedabad', 'Ajmer', 'Alwar', 'Amritsar', 'Aurangabad',
        'Bengaluru', 'Bhopal', 'Bhubaneswar', 'Bikaner', 'Chandigarh', 'Chennai',
        'Coimbatore', 'Dehradun', 'Delhi', 'Faridabad', 'Ghaziabad', 'Goa',
        'Gurugram', 'Guwahati', 'Gwalior', 'Hyderabad', 'Indore', 'Jaipur',
        'Jaisalmer', 'Jalandhar', 'Jammu', 'Jodhpur', 'Kanpur', 'Kochi',
        'Kolkata', 'Kota', 'Lucknow', 'Ludhiana', 'Madurai', 'Mangaluru',
        'Mumbai', 'Mysuru', 'Nagpur', 'Nashik', 'Noida', 'Patna',
        'Pilani', 'Prayagraj', 'Pune', 'Raipur', 'Rajkot', 'Ranchi',
        'Rishikesh', 'Sikar', 'Srinagar', 'Surat', 'Thiruvananthapuram', 'Udaipur',
        'Vadodara', 'Varanasi', 'Vijayawada', 'Visakhapatnam',
    ];
}

/**
 * Clean up a typed city name.
 *
 * City is a free-text field, so this is what keeps the directory
 * usable: "bikaner", "Bikaner" and "BIKANER " all resolve to the same
 * canonical name and therefore the same /restaurants/bikaner page.
 * Known cities get the list's exact spelling; anything else is
 * title-cased and kept, so small towns still get a working page.
 */
function normalize_city(?string $city): ?string {
    $city = trim((string) $city);
    $slug = slugify($city);
    if ($slug === '') {
        return null;
    }
    foreach (directory_cities() as $known) {
        if (slugify($known) === $slug) {
            return $known;
        }
    }
    // Not on the list — keep what they typed, tidied up.
    return mb_convert_case(preg_replace('/\s+/', ' ', $city), MB_CASE_TITLE, 'UTF-8');
}

/**
 * A slug unique across restaurants. Falls back to appending a counter
 * when two restaurants share a name.
 */
function unique_restaurant_slug(mysqli $conn, string $name, string $restro_key): string {
    $base = slugify($name);
    if ($base === '') {
        $base = 'restaurant';
    }
    $slug = $base;
    $n = 1;
    while (true) {
        $stmt = mysqli_prepare($conn, "SELECT restro_key FROM restaurants WHERE slug = ? AND restro_key <> ?");
        mysqli_stmt_bind_param($stmt, 'ss', $slug, $restro_key);
        mysqli_stmt_execute($stmt);
        $taken = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$taken) {
            return $slug;
        }
        $slug = $base . '-' . (++$n);
    }
}

/** Keep slug + city_slug in step with the restaurant's current details. */
function sync_restaurant_directory_fields(mysqli $conn, string $restro_key): void {
    $stmt = mysqli_prepare($conn, "SELECT restaurant_name, city, slug FROM restaurants WHERE restro_key = ?");
    mysqli_stmt_bind_param($stmt, 's', $restro_key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) {
        return;
    }

    // Never change an existing slug — old URLs and their search ranking
    // would break. Only fill it in the first time.
    $slug = $row['slug'] ?: unique_restaurant_slug($conn, $row['restaurant_name'], $restro_key);
    $city = normalize_city($row['city']);
    $city_slug = $city ? slugify($city) : null;
    // Store the canonical spelling too, so "pilani" displays as "Pilani".
    $city_display = $city ?: $row['city'];

    $upd = mysqli_prepare($conn, "UPDATE restaurants SET slug = ?, city = ?, city_slug = ? WHERE restro_key = ?");
    mysqli_stmt_bind_param($upd, 'ssss', $slug, $city_display, $city_slug, $restro_key);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
}

/** Public URL for one restaurant. */
function restaurant_url(string $slug): string {
    return site_base_url() . '/r/' . rawurlencode($slug);
}

/** Public URL for a city listing (or the directory index). */
function city_url(?string $city_slug = null): string {
    return site_base_url() . '/restaurants' . ($city_slug ? '/' . rawurlencode($city_slug) : '');
}

/**
 * Build a title that survives Google's ~60-character display limit.
 * Adds the brand suffix only when it actually fits, so the brand is
 * either fully visible or cleanly absent — never cut mid-word.
 */
function seo_title(string $core, string $brand = 'Dinetous'): string {
    $core = trim(preg_replace('/\s+/', ' ', $core));
    $withBrand = $core . ' | ' . $brand;
    if (mb_strlen($withBrand) <= 60) {
        return $withBrand;
    }
    if (mb_strlen($core) <= 60) {
        return $core;
    }
    return mb_substr($core, 0, 59) . '…';
}

/** Trim a description to Google's ~155-character snippet window. */
function seo_description(string $text, int $limit = 155): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    $cut = mb_substr($text, 0, $limit);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp ? mb_substr($cut, 0, $sp) : $cut, ' ,.;—-') . '…';
}

/**
 * Render the <head> metadata every public page needs to rank:
 * title, description, canonical, Open Graph, Twitter and JSON-LD.
 */
function seo_head(array $o): void {
    $title = $o['title'] ?? 'Dinetous';
    $desc = trim(preg_replace('/\s+/', ' ', $o['description'] ?? ''));
    if (mb_strlen($desc) > 158) {
        $desc = mb_substr($desc, 0, 155) . '…';
    }
    $canonical = $o['canonical'] ?? '';
    $image = $o['image'] ?? '';
    $type = $o['og_type'] ?? 'website';
    $noindex = !empty($o['noindex']);

    echo '<title>' . htmlspecialchars($title) . "</title>\n";
    echo '<meta name="description" content="' . htmlspecialchars($desc) . "\">\n";
    if ($noindex) {
        echo "<meta name=\"robots\" content=\"noindex,follow\">\n";
    } else {
        echo "<meta name=\"robots\" content=\"index,follow,max-image-preview:large\">\n";
    }
    if ($canonical !== '') {
        echo '<link rel="canonical" href="' . htmlspecialchars($canonical) . "\">\n";
    }
    echo '<meta property="og:site_name" content="Dinetous">' . "\n";
    echo '<meta property="og:type" content="' . htmlspecialchars($type) . "\">\n";
    echo '<meta property="og:title" content="' . htmlspecialchars($title) . "\">\n";
    echo '<meta property="og:description" content="' . htmlspecialchars($desc) . "\">\n";
    if ($canonical !== '') {
        echo '<meta property="og:url" content="' . htmlspecialchars($canonical) . "\">\n";
    }
    if ($image !== '') {
        echo '<meta property="og:image" content="' . htmlspecialchars($image) . "\">\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:image" content="' . htmlspecialchars($image) . "\">\n";
    } else {
        echo '<meta name="twitter:card" content="summary">' . "\n";
    }
    echo '<meta name="twitter:title" content="' . htmlspecialchars($title) . "\">\n";
    echo '<meta name="twitter:description" content="' . htmlspecialchars($desc) . "\">\n";

    if (!empty($o['jsonld'])) {
        echo '<script type="application/ld+json">'
            . json_encode($o['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "</script>\n";
    }
}

/** "12:00 PM" -> "12:00" for schema.org openingHours; null if unparseable. */
function schema_time(?string $t): ?string {
    $t = trim((string) $t);
    if ($t === '') {
        return null;
    }
    $ts = strtotime($t);
    return $ts === false ? null : date('H:i', $ts);
}
