<?php
/**
 * MITCH COSMETICS SHOP — Order Management
 * Fix: eliminated N+1 query in the orders list. Item counts are now fetched
 *      in a single subquery so the loop touches the DB zero extra times.
 *
 * Schema resilience:
 *  - orders.staff_id      — optional, detected at runtime
 *  - orders.stock_deducted / stock_restored — optional, detected at runtime
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');

// ── Detect optional columns once per request ──────────────────────────────
function ordersHasColumn(string $column): bool {
    static $cache = [];
    if (isset($cache[$column])) return $cache[$column];
    $db = getDB();
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'orders'
               AND COLUMN_NAME  = ?"
        );
        $stmt->execute([$column]);
        $cache[$column] = ((int)$stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        $cache[$column] = false;
    }
    return $cache[$column];
}

$pageTitle  = 'Orders';
$activeMenu = 'orders';
$db = getDB();

// ── Handle Status Update ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $status  = $_POST['status'] ?? '';
    $allowed = ['pending', 'processing', 'completed', 'cancelled'];

    if ($orderId && in_array($status, $allowed, true)) {

        $hasStockCols = ordersHasColumn('stock_deducted');

        // ── Fetch the current order to check flags ─────────────────────────
        if ($hasStockCols) {
            $orderStmt = $db->prepare(
                "SELECT id, status, stock_deducted, stock_restored FROM orders WHERE id = ? LIMIT 1"
            );
        } else {
            $orderStmt = $db->prepare(
                "SELECT id, status FROM orders WHERE id = ? LIMIT 1"
            );
        }
        $orderStmt->execute([$orderId]);
        $orderRow = $orderStmt->fetch();

        if ($orderRow) {
          if (isStaff() && $orderRow['status'] === 'cancelled') {
            setFlash('error', 'Cancelled orders cannot be reopened by staff.');
            redirect(BASE_URL . '/admin/orders.php');
          }

            // ── Cancellation: restore stock once if it was previously deducted ──
            $didRestore = false;

            if ($status === 'cancelled'
                && $hasStockCols
                && (int)($orderRow['stock_deducted'] ?? 0)  === 1
                && (int)($orderRow['stock_restored'] ?? 0) === 0) {

                // Fetch the items for this order
                $itemsStmt = $db->prepare("
                    SELECT oi.product_id, oi.quantity AS qty, p.name
                    FROM   order_items oi
                    LEFT JOIN products p ON p.id = oi.product_id
                    WHERE  oi.order_id = ?
                ");
                $itemsStmt->execute([$orderId]);
                $items = $itemsStmt->fetchAll();

                $actorId   = (int)($_SESSION['user_id']   ?? 0);
                $actorName = $_SESSION['user_name'] ?? 'Admin';

                // ── Wrap restore + status update in one transaction ────────
                try {
                    $db->beginTransaction();

                    if (!empty($items)) {
                        foreach ($items as $item) {
                            $result = recordStockMovement(
                                (int)$item['product_id'],
                                (int)$item['qty'],
                                'cancelled_order',
                                $actorId,
                                $actorName,
                                $orderId,
                                'order',
                                '',
                                $db  // pass existing transaction
                            );
                            if (!$result['success']) {
                                $db->rollBack();
                                setFlash('error', 'Stock restore failed: ' . $result['message']);
                                redirect(BASE_URL . '/admin/orders.php');
                            }
                        }
                    }

                    // Update status AND set stock_restored = 1 atomically
                    $db->prepare("UPDATE orders SET status = ?, stock_restored = 1 WHERE id = ?")
                       ->execute([$status, $orderId]);

                    $db->commit();
                    $didRestore = true;

                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    setFlash('error', 'Could not cancel order: ' . $e->getMessage());
                    redirect(BASE_URL . '/admin/orders.php');
                }

            } else {
                // No stock change needed — just update status
              if ($hasStockCols && $status === 'completed') {
                $db->prepare("UPDATE orders SET status = ?, stock_deducted = 1 WHERE id = ?")
                   ->execute([$status, $orderId]);
              } else {
                $db->prepare("UPDATE orders SET status = ? WHERE id = ?")
                   ->execute([$status, $orderId]);
              }
            }

            setFlash('success', 'Order status updated.' . ($didRestore ? ' Stock has been restored.' : ''));
        }
    }

    redirect(BASE_URL . '/admin/orders.php');
}

// ── Filters ────────────────────────────────────────────────────────────────
$statusFilter = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['q']      ?? '');
$viewId       = (int) ($_GET['view']     ?? 0);

// ── Single Order View ──────────────────────────────────────────────────────
$viewOrder = null;
$viewItems = [];
if ($viewId) {
    // Build SELECT based on which columns exist
    $hasStaffCol = ordersHasColumn('staff_id');
    $staffJoin   = $hasStaffCol
        ? "LEFT JOIN users s ON s.id = o.staff_id"
        : "";
    $staffSelect = $hasStaffCol
        ? ", s.name AS staff_name"
        : ", NULL AS staff_name";

    $stmt = $db->prepare("
        SELECT o.*, u.name AS customer_name {$staffSelect}
        FROM   orders o
        LEFT JOIN users u ON u.id = o.customer_id
        {$staffJoin}
        WHERE  o.id = ?
    ");
    $stmt->execute([$viewId]);
    $viewOrder = $stmt->fetch();

    if ($viewOrder) {
        $iStmt = $db->prepare("
            SELECT oi.*, p.name AS product_name, p.sku
            FROM   order_items oi
            JOIN   products    p  ON p.id = oi.product_id
            WHERE  oi.order_id = ?
        ");
        $iStmt->execute([$viewId]);
        $viewItems = $iStmt->fetchAll();
    }
}

// ── Orders List (with item counts — single query, no N+1) ─────────────────
$where  = '1=1';
$params = [];
if ($statusFilter) {
    $where   .= ' AND o.status = ?';
    $params[] = $statusFilter;
}
if ($search) {
    $where   .= ' AND (o.order_no LIKE ? OR u.name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$hasStaffCol = ordersHasColumn('staff_id');
$staffJoin   = $hasStaffCol ? "LEFT JOIN users s ON s.id = o.staff_id"  : "";
$staffSelect = $hasStaffCol ? ", s.name AS staff_name"                   : ", NULL AS staff_name";

$stmt = $db->prepare("
    SELECT o.*,
           u.name AS customer_name,
           COUNT(oi.id) AS item_count
           {$staffSelect}
    FROM   orders o
    LEFT JOIN users       u  ON u.id  = o.customer_id
    {$staffJoin}
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE  $where
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Status badge counts (single query)
$statusCounts = [];
foreach ($db->query("SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status")->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['cnt'];
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($viewOrder): ?>
<!-- ── Single Order Detail ─────────────────────────────────────────────── -->
<div class="mb-3 d-flex justify-content-between align-items-center">
  <a href="<?= BASE_URL ?>/admin/orders.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back to Orders
  </a>
  <button onclick="printSection('orderDetail')" class="btn btn-outline-primary">
    <i class="bi bi-printer me-1"></i>Print Order
  </button>
</div>

<div class="row g-3" id="orderDetail">
  <div class="col-md-8">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-receipt me-2"></i>Order — <?= htmlspecialchars($viewOrder['order_no']) ?>
      </div>
      <div class="card-body">
        <div class="row mb-3">
          <div class="col-6">
            <small class="text-muted d-block">Customer</small>
            <strong><?= htmlspecialchars($viewOrder['customer_name'] ?? 'Walk-in') ?></strong>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">Staff</small>
            <strong><?= htmlspecialchars($viewOrder['staff_name'] ?? '—') ?></strong>
          </div>
          <div class="col-6 mt-2">
            <small class="text-muted d-block">Date</small>
            <strong><?= niceDate($viewOrder['created_at']) ?></strong>
          </div>
          <div class="col-6 mt-2">
            <small class="text-muted d-block">Payment</small>
            <strong><?= ucfirst(str_replace('_', ' ', $viewOrder['payment_method'])) ?></strong>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered mb-0">
            <thead>
              <tr><th>Product</th><th>SKU</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>
            </thead>
            <tbody>
              <?php foreach ($viewItems as $oi): ?>
              <tr>
                <td><?= htmlspecialchars($oi['product_name']) ?></td>
                <td><small class="font-monospace"><?= htmlspecialchars($oi['sku']) ?></small></td>
                <td><?= (int) $oi['quantity'] ?></td>
                <td><?= money($oi['unit_price']) ?></td>
                <td class="fw-bold"><?= money($oi['total']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="text-end">Subtotal</td>
                <td><?= money($viewOrder['subtotal']) ?></td>
              </tr>
              <?php if (($viewOrder['discount'] ?? 0) > 0): ?>
              <tr>
                <td colspan="4" class="text-end">Discount</td>
                <td class="text-danger">- <?= money($viewOrder['discount']) ?></td>
              </tr>
              <?php endif; ?>
              <tr class="table-success">
                <td colspan="4" class="text-end fw-bold">Grand Total</td>
                <td class="fw-bold"><?= money($viewOrder['total_amount']) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card">
      <div class="card-header">Order Status</div>
      <div class="card-body">
        <div class="mb-3 text-center" style="font-size:1.1rem">
          <?= statusBadge($viewOrder['status']) ?>
        </div>
        <?php if (isAdmin()): ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="order_id"   value="<?= (int) $viewOrder['id'] ?>">
          <div class="mb-3">
            <label class="form-label">Update Status</label>
            <select name="status" class="form-select">
              <?php foreach (['pending', 'processing', 'completed', 'cancelled'] as $s): ?>
              <option value="<?= $s ?>" <?= $viewOrder['status'] === $s ? 'selected' : '' ?>>
                <?= ucfirst($s) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary w-100">Update Status</button>
        </form>
        <?php elseif (isStaff() && in_array($viewOrder['status'], ['pending', 'processing'], true)): ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="order_id" value="<?= (int)$viewOrder['id'] ?>">
          <input type="hidden" name="status" value="completed">
          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-check-circle me-1"></i>Confirm Sale
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">Summary</div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <span>Order #</span>
          <strong><?= htmlspecialchars($viewOrder['order_no']) ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span>Items</span>
          <strong><?= count($viewItems) ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span>Total</span>
          <strong class="text-success"><?= money($viewOrder['total_amount']) ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Orders List ─────────────────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control"
               placeholder="Search order # or customer..."
               value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-3">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['pending', 'processing', 'completed', 'cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
            <?= ucfirst($s) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-primary me-2">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="<?= BASE_URL ?>/admin/orders.php" class="btn btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Status Tab Buttons -->
<div class="mb-3 d-flex gap-2 flex-wrap">
  <?php
  $allStatuses = ['pending', 'processing', 'completed', 'cancelled'];
  $colors      = ['pending' => 'warning', 'processing' => 'info',
                  'completed' => 'success', 'cancelled' => 'danger'];
  $totalAll    = array_sum($statusCounts);
  ?>
  <a href="<?= BASE_URL ?>/admin/orders.php"
     class="btn btn-sm <?= !$statusFilter ? 'btn-dark' : 'btn-outline-secondary' ?>">
    All <span class="badge bg-light text-dark ms-1"><?= $totalAll ?></span>
  </a>
  <?php foreach ($allStatuses as $s):
    $c = $colors[$s] ?? 'secondary';
  ?>
  <a href="<?= BASE_URL ?>/admin/orders.php?status=<?= $s ?>"
     class="btn btn-sm <?= $statusFilter === $s ? "btn-$c" : "btn-outline-$c" ?>">
    <?= ucfirst($s) ?>
    <span class="badge bg-light text-dark ms-1"><?= $statusCounts[$s] ?? 0 ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">
    <i class="bi bi-receipt me-2"></i>Orders
    <span class="badge bg-primary ms-2"><?= count($orders) ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="ordersTable">
        <thead>
          <tr>
            <th>Order #</th><th>Customer</th><th>Staff</th><th>Items</th>
            <th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
          <tr>
            <td>
              <a href="<?= BASE_URL ?>/admin/orders.php?view=<?= (int) $o['id'] ?>"
                 class="fw-semibold">
                <?= htmlspecialchars($o['order_no']) ?>
              </a>
            </td>
            <td><?= htmlspecialchars($o['customer_name'] ?? 'Walk-in') ?></td>
            <td><?= htmlspecialchars($o['staff_name']    ?? '—') ?></td>
            <td><span class="badge bg-secondary"><?= (int) $o['item_count'] ?></span></td>
            <td class="fw-bold text-success"><?= money($o['total_amount']) ?></td>
            <td>
              <span class="badge bg-light text-dark border">
                <?= ucfirst(str_replace('_', ' ', $o['payment_method'])) ?>
              </span>
            </td>
            <td>
              <?php if (isAdmin() || (isStaff() && $o['status'] !== 'cancelled')): ?>
              <form method="POST" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <select name="status" class="form-select form-select-sm" aria-label="Status for <?= htmlspecialchars($o['order_no']) ?>">
                  <?php foreach (['pending', 'processing', 'completed', 'cancelled'] as $s): ?>
                  <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
              </form>
              <?php else: ?>
              <?= statusBadge($o['status']) ?>
              <?php endif; ?>
            </td>
            <td><small class="text-muted"><?= niceDate($o['created_at']) ?></small></td>
            <td>
              <a href="<?= BASE_URL ?>/admin/orders.php?view=<?= (int) $o['id'] ?>"
                 class="btn btn-sm btn-outline-primary">
                <i class="bi bi-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($orders)): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-5">
              <i class="bi bi-receipt" style="font-size:2rem"></i><br>No orders found
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
