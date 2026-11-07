<?php
/**
 * MITCH COSMETICS SHOP — Database Connection (PDO)
 * Compatible with InfinityFree / PHP 7.4+
 */
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

        $dsn = 'mysql:host=' . DB_HOST
            . ';port='      . DB_PORT
            . ';dbname='    . DB_NAME
            . ';charset='   . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Log the real error — never expose to users
        error_log('MITCH COSMETICS — DB connection failed: ' . $e->getMessage()
            . ' | host=' . DB_HOST . ' | db=' . DB_NAME . ' | user=' . DB_USER);

        // Show developer hint on localhost only
        $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

        $hint = '';
        if ($isLocal) {
            $hint = '<p style="font-size:13px;color:#555;margin-top:16px;padding:12px;'
                  . 'background:#fffbe6;border-radius:6px;border:1px solid #ffe08a">'
                  . '<strong>Developer hint (localhost only):</strong><br>'
                  . 'Check MySQL is running in XAMPP Control Panel.<br>'
                  . 'host=<strong>' . htmlspecialchars(DB_HOST) . '</strong>'
                  . ' &nbsp; db=<strong>'   . htmlspecialchars(DB_NAME) . '</strong>'
                  . ' &nbsp; user=<strong>' . htmlspecialchars(DB_USER) . '</strong>'
                  . '</p>';
        }

        die('<!DOCTYPE html><html lang="en"><head>'
          . '<meta charset="UTF-8">'
          . '<meta name="viewport" content="width=device-width,initial-scale=1">'
          . '<title>Service Unavailable</title>'
          . '<style>'
          . '*{box-sizing:border-box;margin:0;padding:0}'
          . 'body{font-family:system-ui,sans-serif;background:#f5f5f5;'
          . 'display:flex;align-items:center;justify-content:center;'
          . 'min-height:100vh;padding:20px}'
          . '.box{background:#fff;border-radius:12px;padding:40px 36px;'
          . 'max-width:500px;width:100%;'
          . 'box-shadow:0 4px 24px rgba(0,0,0,.1);text-align:center}'
          . 'h2{color:#c0007a;font-size:1.3rem;margin-bottom:12px}'
          . 'p{color:#555;line-height:1.6;font-size:.92rem}'
          . 'a{color:#c0007a;font-weight:600}'
          . '</style></head><body>'
          . '<div class="box">'
          . '<h2>&#128683; Service Temporarily Unavailable</h2>'
          . '<p>The website cannot connect to the database right now.</p>'
          . '<p style="margin-top:10px">Please try again shortly, or '
          . '<a href="mailto:mitchfredick@gmail.com">contact the administrator</a>.</p>'
          . $hint
          . '</div></body></html>');
    }
}
