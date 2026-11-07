<?php
// Generate a secure PHP password hash.
$hash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');

    if ($password === '') {
        $error = 'Please enter a password.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Hash Generator</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; }
        input, textarea, button { width: 100%; box-sizing: border-box; padding: 12px; margin-bottom: 12px; }
        textarea { min-height: 90px; resize: vertical; }
        button { cursor: pointer; }
        .error { color: #b00020; }
        .warning { color: #8a5a00; }
    </style>
</head>
<body>
    <h1>Password Hash Generator</h1>
    <p class="warning">Use this page only on a trusted local server. Delete it after generating the hash.</p>

    <form method="post">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
        <button type="submit">Generate Hash</button>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($hash !== ''): ?>
        <label for="hash">Generated Hash</label>
        <textarea id="hash" readonly><?= htmlspecialchars($hash) ?></textarea>
    <?php endif; ?>
</body>
</html>
