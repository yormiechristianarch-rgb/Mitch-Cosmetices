<?php
/**
 * MITCH COSMETICS SHOP — Central Configuration
 * ─────────────────────────────────────────────────────────────────────────
 *
 *  HOW TO SET YOUR DATABASE CREDENTIALS
 *  ─────────────────────────────────────
 *  Option A — Edit the 4 lines in the EDIT HERE block below directly.
 *
 *  Option B — Create  htdocs/.env  and set credentials there.
 *             The .env values override the EDIT HERE block automatically.
 *
 *  InfinityFree credentials:
 *    Control Panel → MySQL Databases → click your database row
 *
 * ─────────────────────────────────────────────────────────────────────────
 */

// ── PHP runtime settings ──────────────────────────────────────────────────
// (done here because InfinityFree ignores php_flag in .htaccess)
error_reporting(E_ALL);
@ini_set('display_errors',          '0');
@ini_set('log_errors',              '1');
@ini_set('upload_max_filesize',     '10M');
@ini_set('post_max_size',           '12M');
@ini_set('max_execution_time',      '60');
@ini_set('session.cookie_httponly', '1');

// ── Timezone ──────────────────────────────────────────────────────────────
date_default_timezone_set('Africa/Monrovia');

// ── Step 1: Load .env into $_ENV BEFORE defining constants ────────────────
// This ensures .env values are picked up by the define() calls below.
(function () {
    $envFile = __DIR__ . '/../.env';
    if (!is_readable($envFile)) return;

    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;

        $eqPos = strpos($line, '=');
        $key   = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        if ($key === '' || !preg_match('/^[A-Za-z0-9_]+$/', $key)) continue;

        // Strip inline comment:  value  # comment
        if (($pos = strpos($value, ' #')) !== false) {
            $value = trim(substr($value, 0, $pos));
        }
        // Strip surrounding quotes
        $len = strlen($value);
        if ($len >= 2
            && (($value[0] === '"'  && $value[$len - 1] === '"')
             || ($value[0] === '\'' && $value[$len - 1] === '\''))) {
            $value = substr($value, 1, $len - 2);
        }

        // Write to $_ENV and putenv so getenv() also works
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
})();

// ── Helper: read from $_ENV or fall back to $default ─────────────────────
function env(string $key, string $default = ''): string {
    if (array_key_exists($key, $_ENV)) return (string) $_ENV[$key];
    $v = getenv($key);
    if ($v !== false) return (string) $v;
    return $default;
}

// ════════════════════════════════════════════════════════════════════════
//  ★  FALLBACK CREDENTIALS (used when no .env file is present)
//
//  If you are deploying to InfinityFree:
//    Option A — Edit these 4 lines directly (simplest)
//    Option B — Create .env and set DB_* there (recommended)
//
//  InfinityFree credentials:
//    Control Panel → MySQL Databases → click your database row
//
//  Example InfinityFree values:
//    DB_HOST  →  sql309.infinityfree.net
//    DB_NAME  →  if0_12345678_mitch_cosmetics
//    DB_USER  →  if0_12345678
//    DB_PASS  →  yourpassword
// ════════════════════════════════════════════════════════════════════════
define('DB_HOST',    env('DB_HOST',    'localhost'));                    // Local XAMPP host
define('DB_NAME',    env('DB_NAME',    'mitch_cosmetics'));             // Local XAMPP database
define('DB_USER',    env('DB_USER',    'root'));                       // Local XAMPP username
define('DB_PASS',    env('DB_PASS',    ''));                          // Local XAMPP password
define('DB_PORT',    env('DB_PORT',    '3307'));                       // Local XAMPP MySQL port
define('DB_CHARSET', 'utf8mb4');

// ── Application constants ─────────────────────────────────────────────────
define('APP_NAME',                'MITCH COSMETICS SHOP');
define('APP_ADDRESS',             'Paynesville, Montserrado County, Liberia');
define('APP_PHONE',               '+231 77 549 6208');
define('APP_EMAIL',               'mitchfredick@gmail.com');
define('APP_CURRENCY',            '$');
define('APP_LOW_STOCK_THRESHOLD',  10);
define('SESSION_TIMEOUT',          3600);

// ── Base URL (auto-detected; override with APP_BASE_URL in .env) ──────────
(function () {
    $fromEnv = env('APP_BASE_URL', '');
    if ($fromEnv !== '') {
        define('BASE_URL', rtrim($fromEnv, '/'));
        return;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptPath = '/' . ltrim(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/');
    $subfolders = ['admin', 'staff', 'customer', 'reports', 'includes', 'auth', 'public'];

    $slash = strrpos($scriptPath, '/');
    $dir = $slash === false ? '' : substr($scriptPath, 0, $slash);
    $dir = '/' . ltrim(rtrim($dir, '/'), '/');
    if ($dir === '/') $dir = '';

    $parts = explode('/', trim($dir, '/'));
    if (!empty($parts) && in_array(end($parts), $subfolders, true)) {
        $lastSlash = strrpos($dir, '/');
        $dir = $lastSlash === false ? '' : substr($dir, 0, $lastSlash);
    }

    $dir = '/' . ltrim(rtrim($dir, '/'), '/');
    if ($dir === '/') $dir = '';

    define('BASE_URL', rtrim($scheme . '://' . $host . $dir, '/'));
})();

// ── Upload paths ──────────────────────────────────────────────────────────
define('UPLOAD_PATH', __DIR__ . '/../assets/images/products/');
define('VIDEO_PATH',  __DIR__ . '/../assets/videos/');
define('UPLOAD_URL',  rtrim(BASE_URL, '/') . '/assets/images/products/');
define('VIDEO_URL',   rtrim(BASE_URL, '/') . '/assets/videos/');
