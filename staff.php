<?php
/**
 * MITCH COSMETICS SHOP — Staff Management
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Staff Management';
$activeMenu = 'staff';
$db = getDB();

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = sanitize($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $pwd   = $_POST['password'] ?? '';

        if (empty($name) || empty($email) || empty($pwd)) {
            setFlash('error', 'Name, email and password are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Invalid email address.');
        } elseif (strlen($pwd) < 6) {
            setFlash('error', 'Password must be at least 6 characters.');
        } else {
            $check = $db->prepare("SELECT id FROM users WHERE email=?"); $check->execute([$email]);
            if ($check->fetch()) { setFlash('error', 'Email already exists.'); }
            else {
                $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12]);
                try {
                    $db->prepare("INSERT INTO users (name,email,phone,password,role,status) VALUES (?,?,?,?,'staff','active')")
                       ->execute([$name,$email,$phone,$hash]);
                } catch (PDOException $e) {
                    // Fallback: status column may not exist yet
                    $db->prepare("INSERT INTO users (name,email,phone,password,role) VALUES (?,?,?,?,'staff')")
                       ->execute([$name,$email,$phone,$hash]);
                }
                setFlash('success', 'Staff member added.');
            }
        }
    } elseif ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $status = in_array($_POST['status']??'',['active','inactive']) ? $_POST['status'] : 'active';
        $pwd   = $_POST['password'] ?? '';
        $db->prepare("UPDATE users SET name=?,phone=?,status=? WHERE id=? AND role='staff'")
           ->execute([$name,$phone,$status,$id]);
        if (!empty($pwd) && strlen($pwd)>=6) {
            $db->prepare("UPDATE users SET password=? WHERE id=?")
               ->execute([password_hash($pwd,PASSWORD_BCRYPT,['cost'=>12]),$id]);
        }
        setFlash('success', 'Staff updated.');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id == $_SESSION['user_id']) { setFlash('error', 'Cannot delete your own account.'); }
        else { $db->prepare("DELETE FROM users WHERE id=? AND role='staff'")->execute([$id]); setFlash('success','Staff deleted.'); }
    }
    redirect(BASE_URL . '/admin/staff.php');
}

$search = sanitize($_GET['q'] ?? '');
$where  = "role='staff'";
$params = [];
if ($search) { $where .= " AND (name LIKE ? OR email LIKE ?)"; $params = ["%$search%","%$search%"]; }

$stmt = $db->prepare("SELECT * FROM users WHERE $where ORDER BY created_at DESC");
$stmt->execute($params);
$staffList = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <form method="GET" class="d-flex gap-2">
    <input type="text" name="q" class="form-control" style="width:240px" placeholder="Search staff..."
           value="<?= htmlspecialchars($search) ?>">
    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
    <i class="bi bi-person-plus me-1"></i>Add Staff
  </button>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-person-badge me-2"></i>Staff Members
      <span class="badge bg-primary ms-2"><?= count($staffList) ?></span>
    </span>
    <input type="text" id="staffTableSearch" class="form-control form-control-sm"
           style="max-width:200px" placeholder="Filter list…">
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="staffTable">
        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($staffList as $i => $s): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold"
                     style="width:36px;height:36px;background:var(--accent);color:var(--primary);flex-shrink:0">
                  <?= strtoupper(substr($s['name'],0,1)) ?>
                </div>
                <span class="fw-semibold"><?= htmlspecialchars($s['name']) ?></span>
              </div>
            </td>
            <td><?= htmlspecialchars($s['email']) ?></td>
            <td><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
            <td><?= statusBadge($s['status']) ?></td>
            <td><small class="text-muted"><?= date('M d, Y', strtotime($s['created_at'])) ?></small></td>
            <td>
              <button class="btn btn-sm btn-outline-primary me-1"
                      onclick='openEditStaff(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this staff member?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($staffList)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">No staff found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Staff Modal -->
<div class="modal fade" id="addStaffModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Staff Member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-control"></div>
          <div class="mb-3"><label class="form-label">Password * <small class="text-muted">(min 6 chars)</small></label><input type="password" name="password" class="form-control" minlength="6" required></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Staff</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Staff Modal -->
<div class="modal fade" id="editStaffModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit Staff</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editStaffId">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Full Name *</label><input type="text" name="name" id="editStaffName" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Phone</label><input type="tel" name="phone" id="editStaffPhone" class="form-control"></div>
          <div class="mb-3"><label class="form-label">Status</label>
            <select name="status" id="editStaffStatus" class="form-select">
              <option value="active">Active</option><option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="mb-3"><label class="form-label">New Password <small class="text-muted">(leave blank to keep)</small></label><input type="password" name="password" class="form-control" minlength="6"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditStaff(s) {
  document.getElementById('editStaffId').value     = s.id;
  document.getElementById('editStaffName').value   = s.name;
  document.getElementById('editStaffPhone').value  = s.phone || '';
  document.getElementById('editStaffStatus').value = s.status;
  new bootstrap.Modal(document.getElementById('editStaffModal')).show();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
