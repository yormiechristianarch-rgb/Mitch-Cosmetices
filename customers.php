<?php
/**
 * MITCH COSMETICS SHOP — Customer Management (Admin)
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Customers';
$activeMenu = 'customers';
$db = getDB();

// Toggle status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id     = (int)($_POST['user_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['active','inactive'], true)
              ? $_POST['status'] : 'inactive';
    $db->prepare("UPDATE users SET status=? WHERE id=? AND role='customer'")->execute([$status, $id]);
    setFlash('success', 'Customer status updated.');
    redirect(BASE_URL . '/admin/customers.php');
}

$search = sanitize($_GET['q'] ?? '');
$where  = "u.role='customer'";
$params = [];
if ($search) { $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }

$stmt = $db->prepare("
    SELECT u.*,
           COUNT(DISTINCT o.id) AS order_count,
           COALESCE(SUM(o.total_amount),0) AS total_spent
    FROM users u
    LEFT JOIN orders o ON o.customer_id = u.id
    WHERE $where
    GROUP BY u.id ORDER BY u.created_at DESC
");
$stmt->execute($params);
$customers = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-md-5">
        <input type="text" name="q" class="form-control" placeholder="Search by name, email or phone..."
               value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button>
        <a href="<?= BASE_URL ?>/admin/customers.php" class="btn btn-outline-secondary ms-1">Reset</a>
      </div>
      <div class="col-auto ms-auto">
        <span class="badge bg-primary fs-6"><?= count($customers) ?> customers</span>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-people me-2"></i>Customer List</span>
    <input type="text" id="customersTableSearch" class="form-control form-control-sm"
           style="max-width:200px" placeholder="Filter list…">
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="customersTable">
        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Orders</th><th>Spent</th><th>Status</th><th>Joined</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($customers as $i => $c): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold"
                     style="width:36px;height:36px;background:var(--accent);color:var(--primary);font-size:.85rem;flex-shrink:0">
                  <?= strtoupper(substr($c['name'],0,1)) ?>
                </div>
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
                  <small class="text-muted"><?= htmlspecialchars($c['address'] ?? '') ?></small>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($c['email']) ?></td>
            <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
            <td><span class="badge bg-primary"><?= $c['order_count'] ?></span></td>
            <td class="fw-bold text-success"><?= money($c['total_spent']) ?></td>
            <td><?= statusBadge($c['status']) ?></td>
            <td><small class="text-muted"><?= date('M d, Y', strtotime($c['created_at'])) ?></small></td>
            <td>
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="user_id" value="<?= $c['id'] ?>">
                <input type="hidden" name="status" value="<?= $c['status']==='active' ? 'inactive' : 'active' ?>">
                <button type="submit" class="btn btn-sm <?= $c['status']==='active' ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                  <?= $c['status']==='active' ? '<i class="bi bi-pause-circle"></i>' : '<i class="bi bi-play-circle"></i>' ?>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($customers)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">No customers found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
