<?php
// Directory access guard — redirect to homepage
require_once __DIR__ . '/../includes/config.php';
header('Location: ' . BASE_URL . '/');
exit;
