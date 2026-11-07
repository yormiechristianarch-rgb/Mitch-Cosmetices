<?php
/**
 * MITCH COSMETICS SHOP — Login Page
 *
 * Handles all three roles: admin, staff, customer.
 * Architecture:
 *   - Single users table with role ENUM('admin','staff','customer')
 *   - Passwords stored as bcrypt via password_hash()
 *   - Session set on success; role-based redirect
 *   - status column guard: works even if live DB is missing the column
 */
require_once __DIR__ . '/includes/functions.php';

// Already logged in → redirect to correct dashboard
if (isLoggedIn()) {
    $role = $_SESSION['role'] ?? 'customer';
    if ($role === 'admin')     redirect(BASE_URL . '/admin/dashboard.php');
    elseif ($role === 'staff') redirect(BASE_URL . '/staff/dashboard.php');
    else                       redirect(BASE_URL . '/customer/dashboard.php');
}

$error   = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email']    ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db   = getDB();
        $user = null;

        // Try with status = 'active' first; fall back if the column doesn't exist
        try {
            $stmt = $db->prepare(
                "SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        } catch (PDOException $e) {
            // status column missing on this DB — query without it
            error_log('MITCH LOGIN: status column missing, falling back. ' . $e->getMessage());
            try {
                $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
            } catch (PDOException $e2) {
                error_log('MITCH LOGIN: user lookup failed. ' . $e2->getMessage());
                $error = 'A database error occurred. Please try again.';
            }
        }

        if (empty($error)) {
            if ($user && password_verify($password, $user['password'])) {
                // ── Login success ─────────────────────────────────────────
                session_regenerate_id(true);

                $_SESSION['user_id']       = (int)$user['id'];
                $_SESSION['user_name']     = $user['name'];
                $_SESSION['user_email']    = $user['email'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['avatar']        = $user['avatar']  ?? '';
                $_SESSION['last_activity'] = time();
                $_SESSION['last_regen']    = time();
                $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

                error_log('MITCH LOGIN: success — email=' . $email
                        . ' role=' . $user['role'] . ' id=' . $user['id']);

                // Role-based redirect
                if ($user['role'] === 'admin') {
                    redirect(BASE_URL . '/admin/dashboard.php');
                } elseif ($user['role'] === 'staff') {
                    redirect(BASE_URL . '/staff/dashboard.php');
                } else {
                    redirect(BASE_URL . '/customer/dashboard.php');
                }

            } else {
                // ── Login failure ─────────────────────────────────────────
                if (!$user) {
                    error_log('MITCH LOGIN: no active user for email=' . $email);
                } else {
                    error_log('MITCH LOGIN: wrong password for email=' . $email
                            . ' role=' . $user['role']);
                }
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — <?= APP_NAME ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<div class="auth-wrapper">
  <div class="auth-card">

    <!-- Logo / Brand -->
    <div class="text-center mb-4">
      <div class="auth-icon-wrap">
        <i class="bi bi-shop"></i>
      </div>
      <div class="auth-logo"><span>MITCH</span> COSMETICS</div>
      <p class="text-muted mt-1 mb-0" style="font-size:.82rem">Sign in to your account</p>
    </div>

    <!-- Session timeout alert -->
    <?php if ($timeout): ?>
    <div class="alert alert-warning py-2 mb-3">
      <i class="bi bi-clock me-2"></i>Session expired. Please sign in again.
    </div>
    <?php endif; ?>

    <!-- Error alert -->
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3">
      <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Login form -->
    <form method="POST" novalidate>
      <div class="mb-3">
        <label class="form-label" for="loginEmail">Email Address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope"></i></span>
          <input type="email" name="email" id="loginEmail"
                 class="form-control"
                 placeholder="you@example.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 required autofocus autocomplete="email">
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label" for="pwdField">Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" name="password" id="pwdField"
                 class="form-control"
                 placeholder="••••••••"
                 required autocomplete="current-password">
          <button class="btn btn-outline-secondary" type="button"
                  onclick="togglePwd()" aria-label="Toggle password visibility">
            <i class="bi bi-eye" id="pwdEye"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
      </button>
    </form>

    <div class="or-divider mt-4 mb-3"><hr><span>or</span><hr></div>

    <p class="text-center mb-0" style="font-size:.88rem;color:var(--text-muted)">
      Don't have an account?
      <a href="<?= BASE_URL ?>/register.php" class="fw-bold">Create one</a>
    </p>

    <div class="text-center mt-3">
      <a href="<?= BASE_URL ?>/" class="text-muted" style="font-size:.8rem">
        <i class="bi bi-arrow-left me-1"></i>Back to Storefront
      </a>
    </div>

  </div>
</div>

<script>
function togglePwd() {
  var f = document.getElementById('pwdField');
  var e = document.getElementById('pwdEye');
  if (f.type === 'password') {
    f.type = 'text';
    e.className = 'bi bi-eye-slash';
  } else {
    f.type = 'password';
    e.className = 'bi bi-eye';
  }
}
</script>
</body>
</html>
