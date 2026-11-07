<?php
/**
 * MITCH COSMETICS SHOP — Admin/Staff Profile
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');

$pageTitle  = 'My Profile';
$activeMenu = '';
$db  = getDB();
$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name    = sanitize($_POST['name'] ?? '');
    $phone   = sanitize($_POST['phone'] ?? '');
    $newPwd  = $_POST['new_password'] ?? '';
    $confPwd = $_POST['confirm_password'] ?? '';

    if (empty($name)) { setFlash('error', 'Name is required.'); redirect(BASE_URL . '/admin/profile.php'); }

    $db->prepare("UPDATE users SET name=?, phone=? WHERE id=?")->execute([$name, $phone, $uid]);
    $_SESSION['user_name'] = $name;

    if (!empty($newPwd)) {
        if (strlen($newPwd) < 6) { setFlash('error', 'Password must be at least 6 characters.'); redirect(BASE_URL . '/admin/profile.php'); }
        if ($newPwd !== $confPwd) { setFlash('error', 'Passwords do not match.'); redirect(BASE_URL . '/admin/profile.php'); }
        // Verify current password before allowing the change
        $curPwd = $_POST['current_password'] ?? '';
        $row    = $db->prepare("SELECT password FROM users WHERE id=?");
        $row->execute([$uid]);
        $row = $row->fetch();
        if (!$row || !password_verify($curPwd, $row['password'])) {
            setFlash('error', 'Current password is incorrect.'); redirect(BASE_URL . '/admin/profile.php');
        }
        $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPwd,PASSWORD_BCRYPT,['cost'=>12]),$uid]);
    }
    setFlash('success', 'Profile updated.');
    redirect(BASE_URL . '/admin/profile.php');
}

$user = $db->prepare("SELECT * FROM users WHERE id=?");
$user->execute([$uid]);
$user = $user->fetch();
if (!$user) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/login.php');
}
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-person-circle me-2"></i>My Profile — <?= ucfirst($user['role']) ?></div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="mb-3"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required></div>
          <div class="mb-3"><label class="form-label">Email</label><input class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled></div>
          <div class="mb-3"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>"></div>
          <hr>
          <h6>Change Password <small class="text-muted">(leave blank to keep)</small></h6>
          <div class="mb-3"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" placeholder="Required to change password" autocomplete="current-password"></div>
          <div class="mb-3"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" minlength="6" autocomplete="new-password"></div>
          <div class="mb-4"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" autocomplete="new-password"></div>
          <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-check-circle me-1"></i>Save Changes</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
