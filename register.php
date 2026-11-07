<?php
/**
 * MITCH COSMETICS SHOP — Registration Page
 *
 * Public registration creates Customer accounts only.
 * Admin and Staff accounts are created by an Administrator
 * through the protected admin panel.
 *
 * Fix: INSERT now explicitly sets status='active' so new accounts
 *      are immediately usable regardless of DB column defaults.
 */
require_once __DIR__ . '/includes/functions.php';

// Already logged in → redirect
if (isLoggedIn()) {
    $r = $_SESSION['role'] ?? 'customer';
    if ($r === 'admin')     redirect(BASE_URL . '/admin/dashboard.php');
    elseif ($r === 'staff') redirect(BASE_URL . '/staff/dashboard.php');
    else                    redirect(BASE_URL . '/customer/dashboard.php');
}

$error   = '';
$success = '';

// Only customers can self-register
const PUBLIC_ROLES = ['customer'];
const ALL_ROLES    = ['customer', 'staff', 'admin'];

$roleLabels = [
    'customer' => 'Customer',
    'staff'    => 'Staff',
    'admin'    => 'Administrator',
];
$roleIcons = [
    'customer' => 'bi-person',
    'staff'    => 'bi-person-badge',
    'admin'    => 'bi-shield-lock',
];
$roleDesc = [
    'customer' => 'Browse products & place orders',
    'staff'    => 'Manage sales, POS & orders',
    'admin'    => 'Full system access',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = sanitize($_POST['name']             ?? '');
    $email    = strtolower(trim($_POST['email']     ?? ''));
    $phone    = sanitize($_POST['phone']            ?? '');
    $password = $_POST['password']                  ?? '';
    $confirm  = $_POST['confirm_password']          ?? '';
    // The hidden roleFallback field carries the actual value
    // (radio inputs for non-customer are disabled and don't submit)
    $role     = strtolower(trim($_POST['role']      ?? 'customer'));

    if (empty($role) || !in_array($role, ALL_ROLES, true)) {
        $error = 'Please select an account type.';
    } elseif (!in_array($role, PUBLIC_ROLES, true)) {
        $error = ucfirst($role) . ' accounts can only be created by an Administrator. '
               . 'Please ask your administrator to create your account, '
               . 'or register as a Customer.';
    } elseif (empty($name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $db    = getDB();
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'Email already registered. Please login instead.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            // Explicitly set status = 'active' so the account works immediately
            // regardless of whether the column has a default on this DB instance
            try {
                $db->prepare(
                    "INSERT INTO users (name, email, phone, password, role, status)
                     VALUES (?, ?, ?, ?, ?, 'active')"
                )->execute([$name, $email, $phone, $hash, $role]);
                error_log('MITCH REGISTER: new customer registered email=' . $email);
                $success = 'Account created successfully! You can now log in.';
            } catch (PDOException $e) {
                // Fallback: try without status column if it truly doesn't exist
                error_log('MITCH REGISTER: insert with status failed, trying without. '
                        . $e->getMessage());
                try {
                    $db->prepare(
                        "INSERT INTO users (name, email, phone, password, role)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([$name, $email, $phone, $hash, $role]);
                    $success = 'Account created successfully! You can now log in.';
                } catch (PDOException $e2) {
                    error_log('MITCH REGISTER: insert failed entirely. ' . $e2->getMessage());
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
}

$selectedRole = $_POST['role'] ?? 'customer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account — <?= APP_NAME ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<div class="auth-wrapper">
  <div class="auth-card" style="max-width:500px">

    <!-- Brand -->
    <div class="text-center mb-4">
      <div class="auth-icon-wrap">
        <i class="bi bi-person-plus"></i>
      </div>
      <div class="auth-logo"><span>MITCH</span> COSMETICS</div>
      <p class="text-muted mt-1 mb-0" style="font-size:.82rem">Create your account</p>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3">
      <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success py-2 mb-3">
      <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
      <a href="<?= BASE_URL ?>/login.php" class="fw-bold ms-1">Sign in now →</a>
    </div>
    <?php endif; ?>

    <form method="POST" novalidate id="registerForm">

      <!-- Account type selector — only customer is enabled publicly -->
      <div class="mb-4">
        <label class="form-label">Account Type <span class="text-danger">*</span></label>
        <div class="role-cards" id="roleCards">
          <?php foreach ($roleLabels as $val => $label):
            $isRestricted = !in_array($val, PUBLIC_ROLES, true);
            $isSelected   = ($selectedRole === $val || (!$selectedRole && $val === 'customer'));
          ?>
          <label class="role-card <?= $isSelected ? 'selected' : '' ?> <?= $isRestricted ? 'restricted' : '' ?>"
                 id="roleCard_<?= $val ?>"
                 for="role_<?= $val ?>">
            <input type="radio"
                   name="role_radio"
                   id="role_<?= $val ?>"
                   value="<?= $val ?>"
                   <?= $isSelected   ? 'checked'  : '' ?>
                   <?= $isRestricted ? 'disabled' : '' ?>
                   onchange="selectRole('<?= $val ?>')">
            <div class="role-icon"><i class="bi <?= $roleIcons[$val] ?>"></i></div>
            <div class="role-name"><?= $label ?></div>
            <div class="role-desc"><?= $roleDesc[$val] ?></div>
            <?php if ($isRestricted): ?>
            <span class="role-lock" title="Admin only">
              <i class="bi bi-lock-fill"></i>
            </span>
            <?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="form-text mt-2">
          <i class="bi bi-info-circle me-1"></i>
          Staff and Administrator accounts are created by an Administrator only.
        </div>
        <!-- Single hidden field carries the actual submitted role value -->
        <input type="hidden" name="role" id="roleHidden" value="<?= htmlspecialchars($selectedRole) ?>">
      </div>

      <!-- Form fields -->
      <div class="mb-3">
        <label class="form-label" for="regName">Full Name <span class="text-danger">*</span></label>
        <input type="text" name="name" id="regName" class="form-control"
               placeholder="Your full name"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
               required autocomplete="name">
      </div>

      <div class="mb-3">
        <label class="form-label" for="regEmail">Email Address <span class="text-danger">*</span></label>
        <input type="email" name="email" id="regEmail" class="form-control"
               placeholder="you@example.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               required autocomplete="email">
      </div>

      <div class="mb-3">
        <label class="form-label" for="regPhone">Phone Number</label>
        <input type="tel" name="phone" id="regPhone" class="form-control"
               placeholder="+231 770 000 000"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
               autocomplete="tel">
      </div>

      <div class="mb-3">
        <label class="form-label" for="regPwd">Password <span class="text-danger">*</span></label>
        <input type="password" name="password" id="regPwd" class="form-control"
               placeholder="Min. 6 characters"
               minlength="6" required autocomplete="new-password">
        <div class="form-text">Minimum 6 characters</div>
      </div>

      <div class="mb-4">
        <label class="form-label" for="regConfirm">Confirm Password <span class="text-danger">*</span></label>
        <input type="password" name="confirm_password" id="regConfirm" class="form-control"
               placeholder="Repeat your password"
               required autocomplete="new-password">
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2">
        <i class="bi bi-person-plus me-2"></i>Create Account
      </button>
    </form>

    <p class="text-center mt-3 mb-3" style="font-size:.88rem;color:var(--text-muted)">
      Already have an account?
      <a href="<?= BASE_URL ?>/login.php" class="fw-bold">Sign in</a>
    </p>

    <div class="or-divider mb-3"><hr><span>or</span><hr></div>
    <div class="d-flex justify-content-center">
      <a href="<?= BASE_URL ?>/" class="btn btn-outline-secondary px-4 py-2">
        <i class="bi bi-shop me-2"></i>Continue Browsing the Store
      </a>
    </div>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Ensure Customer is selected by default
  var hidden = document.getElementById('roleHidden');
  if (!hidden.value) selectRole('customer');
});

function selectRole(val) {
  document.querySelectorAll('.role-card').forEach(function(card) {
    card.classList.remove('selected');
  });
  var card = document.getElementById('roleCard_' + val);
  if (card) card.classList.add('selected');
  // Update the hidden field (this is what gets submitted)
  document.getElementById('roleHidden').value = val;
  // Also check the radio if not disabled
  var radio = document.getElementById('role_' + val);
  if (radio && !radio.disabled) radio.checked = true;
}

document.getElementById('registerForm').addEventListener('submit', function(e) {
  var role = document.getElementById('roleHidden').value;
  if (!role) {
    e.preventDefault();
    alert('Please select an account type.');
    return;
  }
  // Double-check: block admin/staff on client side too
  if (role === 'admin' || role === 'staff') {
    e.preventDefault();
    alert('Staff and Admin accounts can only be created by an administrator.');
  }
});
</script>
</body>
</html>
