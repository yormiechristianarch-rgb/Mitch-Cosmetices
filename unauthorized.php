<?php
/**
 * MITCH COSMETICS SHOP — Unauthorized (403) page
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session.php';

http_response_code(403);   // Always send a real 403 status
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unauthorized — <?= APP_NAME ?></title>
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card text-center">
    <i class="bi bi-shield-exclamation" style="font-size:4rem;color:var(--primary)"></i>
    <h3 class="mt-3">Access Denied</h3>
    <p class="text-muted">You don't have permission to view this page.</p>
    <a href="<?= BASE_URL ?>/index.php"  class="btn btn-primary">Go to Dashboard</a>
    <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-secondary ms-2">Logout</a>
  </div>
</div>
</body>
</html>
