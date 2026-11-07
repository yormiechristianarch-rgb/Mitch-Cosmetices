<?php
require_once __DIR__ . '/includes/functions.php';

$filename = isset($_GET['image']) && is_string($_GET['image']) ? $_GET['image'] : null;
$image = productImageFile($filename);

if ($image === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $image['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
readfile($image['path']);
