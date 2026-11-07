<?php
/**
 * MITCH COSMETICS SHOP — Session Management & Authorization
 *
 * Supports all three roles: admin, staff, customer.
 * requireRole() accepts one or more allowed roles.
 *
 * Fix history:
 *  - timeout check now runs BEFORE session_regenerate_id()
 *  - secure cookie flag auto-detects HTTPS
 *  - status guard: requireRole() does not depend on DB; role comes from session
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,               // browser-session cookie (not persistent)
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Session ID regeneration (anti-fixation) ────────────────────────────────
function regenerateSession(): void {
    if (!isset($_SESSION['last_regen'])) {
        $_SESSION['last_regen'] = time();
        return;
    }
    if (time() - $_SESSION['last_regen'] > 300) {   // every 5 minutes
        session_regenerate_id(true);
        $_SESSION['last_regen'] = time();
    }
}

// ── Auth checks ────────────────────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function hasRole(string $role): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function isAdmin():    bool { return hasRole('admin');    }
function isStaff():    bool { return hasRole('staff');    }
function isCustomer(): bool { return hasRole('customer'); }

/**
 * Enforce authentication and role-based access.
 * Pass one or more allowed roles:
 *   requireRole('admin')
 *   requireRole('admin', 'staff')
 *   requireRole('customer')
 *
 * Check order:
 *   1. Is the user logged in at all?          → redirect to login
 *   2. Has the session timed out?             → destroy & redirect
 *   3. Does the user have an allowed role?    → redirect to unauthorized
 *   4. Regenerate session ID periodically
 *   5. Update last-activity timestamp
 */
function requireRole(string ...$roles): void {
    // 1. Not logged in
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    // 2. Session timeout — check BEFORE touching the session further
    if (isset($_SESSION['last_activity'])
        && (time() - (int)$_SESSION['last_activity']) > SESSION_TIMEOUT) {
        // Wipe session
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 86400,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
            session_destroy();
        }
        header('Location: ' . BASE_URL . '/login.php?timeout=1');
        exit;
    }

    // 3. Role check
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        header('Location: ' . BASE_URL . '/unauthorized.php');
        exit;
    }

    // 4. Periodic session ID regeneration (safe — session is valid here)
    regenerateSession();

    // 5. Keep activity timestamp fresh
    $_SESSION['last_activity'] = time();
}

// ── CSRF ───────────────────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    $stored    = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submitted) || !is_string($stored)
        || $stored === '' || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:20px;color:red">
             Invalid or missing CSRF token. Please go back and try again.</p>');
    }
}

// ── Flash Messages ─────────────────────────────────────────────────────────
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ── Current User ───────────────────────────────────────────────────────────
function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'     => $_SESSION['user_id'],
        'name'   => $_SESSION['user_name']  ?? '',
        'email'  => $_SESSION['user_email'] ?? '',
        'role'   => $_SESSION['role']       ?? '',
        'avatar' => $_SESSION['avatar']     ?? '',
    ];
}

// ── Input Sanitization ─────────────────────────────────────────────────────
function sanitize(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}
