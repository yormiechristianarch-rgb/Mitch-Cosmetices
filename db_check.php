<?php
/**
 * MITCH COSMETICS SHOP — Database Connection Checker
 * ────────────────────────────────────────────────────
 * Visit: http://yourdomain.com/db_check.php
 * DELETE THIS FILE once the database is confirmed working.
 */
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DB Check — Mitch Cosmetics</title>
<style>
*{box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:#f5f0f7;padding:24px;color:#1a1a2e}
.wrap{max-width:720px;margin:0 auto}
h1{color:#c0007a;font-size:1.4rem;margin-bottom:24px}
h2{font-size:1rem;margin:24px 0 8px;color:#3d0030}
.ok{color:#059669;font-weight:700}
.fail{color:#dc2626;font-weight:700}
.warn{color:#d97706;font-weight:700}
table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:.9rem}
th,td{border:1px solid #ddd;padding:8px 12px;text-align:left}
th{background:#f8f0f5;font-size:.78rem;text-transform:uppercase;letter-spacing:.5px}
.box{background:#fff;border-radius:12px;padding:24px;margin-bottom:20px;
     box-shadow:0 2px 12px rgba(192,0,122,.08)}
.step{background:#fffbe6;border:1px solid #ffe08a;border-radius:8px;
      padding:16px;font-size:.88rem;line-height:1.7;margin-top:12px}
.step code{background:#f0e0e8;padding:1px 5px;border-radius:3px;font-size:.85rem}
pre{background:#f4f4f4;padding:12px;border-radius:6px;font-size:.82rem;
    overflow-x:auto;white-space:pre-wrap}
</style>
</head>
<body>
<div class="wrap">
<h1>&#128270; Mitch Cosmetics — Database Checker</h1>

<?php
// ── 1. .env file status ───────────────────────────────────────────────────
$envPath = __DIR__ . '/.env';
echo '<div class="box">';
echo '<h2>1. Configuration File (.env)</h2>';
if (file_exists($envPath)) {
    $readable = is_readable($envPath);
    echo '<p>' . ($readable ? '<span class="ok">&#10003; .env found and readable</span>' : '<span class="fail">&#10007; .env found but NOT readable — check file permissions (should be 644)</span>') . '</p>';
} else {
    echo '<p><span class="fail">&#10007; .env file does not exist at: ' . htmlspecialchars($envPath) . '</span></p>';
    echo '<div class="step"><strong>Fix:</strong> Create a file named <code>.env</code> in your <code>htdocs/</code> folder with your database credentials. See <code>.env.example</code> for the template.</div>';
}
echo '</div>';

// ── 2. Loaded credentials ─────────────────────────────────────────────────
echo '<div class="box">';
echo '<h2>2. Active Database Credentials</h2>';
echo '<table>
<tr><th>Setting</th><th>Value</th><th>Status</th></tr>
<tr><td>DB_HOST</td><td>' . htmlspecialchars(DB_HOST) . '</td><td>' . (DB_HOST !== '' ? '<span class="ok">Set</span>' : '<span class="fail">Empty</span>') . '</td></tr>
<tr><td>DB_NAME</td><td>' . htmlspecialchars(DB_NAME) . '</td><td>' . (DB_NAME !== '' ? '<span class="ok">Set</span>' : '<span class="fail">Empty</span>') . '</td></tr>
<tr><td>DB_USER</td><td>' . htmlspecialchars(DB_USER) . '</td><td>' . (DB_USER !== '' ? '<span class="ok">Set</span>' : '<span class="fail">Empty</span>') . '</td></tr>
<tr><td>DB_PASS</td><td>' . (DB_PASS !== '' ? '<em>(set — ' . strlen(DB_PASS) . ' chars)</em>' : '<em>(empty)</em>') . '</td><td>' . '<span class="ok">OK</span>' . '</td></tr>
</table>';

// InfinityFree warning
if (DB_HOST === 'localhost' && DB_USER === 'root') {
    echo '<div class="step">&#9888; <strong>You appear to be using XAMPP/local credentials.</strong><br>
    If this is the <strong>live InfinityFree site</strong>, you need to update your credentials.<br>
    Get the real values from: <strong>InfinityFree Control Panel → MySQL Databases</strong><br><br>
    Then edit <code>htdocs/.env</code> (or <code>htdocs/includes/config.php</code>) and set:<br>
    <code>DB_HOST=sqlXXX.infinityfree.net</code><br>
    <code>DB_NAME=if0_XXXXXXXX_yourdbname</code><br>
    <code>DB_USER=if0_XXXXXXXX</code><br>
    <code>DB_PASS=your_password</code>
    </div>';
}
echo '</div>';

// ── 3. Connection test ────────────────────────────────────────────────────
echo '<div class="box">';
echo '<h2>3. Connection Test</h2>';

if (DB_HOST === '' || DB_NAME === '' || DB_USER === '') {
    echo '<p><span class="fail">Cannot test — DB_HOST, DB_NAME or DB_USER is empty.</span></p>';
} else {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo '<p><span class="ok">&#10003; Connected successfully!</span><br>
              Host: <strong>' . htmlspecialchars(DB_HOST) . '</strong> &nbsp;
              Database: <strong>' . htmlspecialchars(DB_NAME) . '</strong></p>';

        // ── 4. Table check ────────────────────────────────────────────────
        echo '</div><div class="box"><h2>4. Required Tables</h2>';
        echo '<table><tr><th>Table</th><th>Status</th></tr>';
        $required = [
            'users','categories','products','orders','order_items',
            'sales','sale_items','attendance','staff_payments',
            'attendance_settings','site_settings','stock_movements'
        ];
        $missing = [];
        foreach ($required as $t) {
            $exists = $pdo->query("SHOW TABLES LIKE '{$t}'")->rowCount() > 0;
            if (!$exists) $missing[] = $t;
            echo '<tr><td><code>' . $t . '</code></td><td>'
               . ($exists
                  ? '<span class="ok">&#10003; Exists</span>'
                  : '<span class="warn">&#9888; Missing</span>')
               . '</td></tr>';
        }
        echo '</table>';

        if (!empty($missing)) {
            echo '<div class="step">&#9888; <strong>Missing tables detected.</strong><br>
            Import <code>mitch_cosmetics.sql</code> in phpMyAdmin to create all tables.<br>
            If you already have data, import only the patch file for missing tables:<br>
            <code>patch_inventory.sql</code> (if it exists) — adds inventory tracking columns</div>';
        } else {
            echo '<p><span class="ok">&#10003; All required tables exist.</span></p>';
        }

    } catch (PDOException $e) {
        echo '<p><span class="fail">&#10007; Connection FAILED</span></p>';
        echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';

        echo '<div class="step"><strong>Common causes and fixes:</strong><br><br>
        <strong>1. "Access denied"</strong> → Wrong DB_USER or DB_PASS. Check InfinityFree Control Panel → MySQL Databases.<br><br>
        <strong>2. "Unknown database"</strong> → DB_NAME is wrong. InfinityFree names look like <code>if0_XXXXXXXX_name</code>.<br><br>
        <strong>3. "getaddrinfo failed" / "Can\'t connect"</strong> → DB_HOST is wrong. Use the exact hostname from InfinityFree (e.g. <code>sql123.infinityfree.net</code>).<br><br>
        <strong>4. MySQL not running (local only)</strong> → Open XAMPP Control Panel → Start MySQL.<br><br>
        <strong>5. Database not created yet</strong> → Create a new database in InfinityFree Control Panel, then import <code>mitch_cosmetics.sql</code>.
        </div>';
    }
}
echo '</div>';

// ── 5. BASE_URL check ─────────────────────────────────────────────────────
echo '<div class="box">';
echo '<h2>5. BASE_URL</h2>';
echo '<p>Detected: <strong>' . htmlspecialchars(BASE_URL) . '</strong></p>';
echo '</div>';
?>

<div class="box" style="background:#fff3f3;border:1px solid #fcc">
  <strong>&#9888; Security reminder:</strong>
  Delete <code>db_check.php</code> from your server once everything is working.
  This page reveals your database host and name.
</div>

</div><!-- /.wrap -->
</body>
</html>
