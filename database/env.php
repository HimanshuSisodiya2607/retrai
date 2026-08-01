<?php
/**
 * Load key=value pairs from the project root .env into getenv()/$_ENV.
 * Skips comments and lines without '='. Does not overwrite existing env vars.
 */
$envFile = dirname(__DIR__) . '/.env';
if (!is_readable($envFile)) {
    return;
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $pos = strpos($line, '=');
    if ($pos === false) {
        continue;
    }
    $key = trim(substr($line, 0, $pos));
    $value = trim(substr($line, $pos + 1));
    if ($key === '' || getenv($key) !== false) {
        continue;
    }
    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
