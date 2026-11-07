<?php
/**
 * MITCH COSMETICS SHOP — Logout
 * Works for all three roles: admin, staff, customer.
 * Destroys the session completely and redirects to login.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session.php';

// Save BASE_URL before destroying session
$redirect = BASE_URL . '/login.php';

// 1. Clear all session variables
$_SESSION = [];

// 2. Destroy the server-side session
if (session_status() === PHP_SESSION_ACTIVE) {
    // Delete the session cookie in the browser
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 86400,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
    session_destroy();
}

// 3. Redirect to login — no cache
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . $redirect);
exit;
