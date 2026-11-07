<?php
/**
 * MITCH COSMETICS SHOP — Inventory Dashboard
 * Shows full inventory status + complete stock movement history.
 * Admin-only.
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Inventory';
$activeMenu = 'inventory';
$db = getDB();

$currentUserId   = (int)($_SESSION['user_id']   ?? 0);
$currentUserName = $_SESSION['user_name'] ?? 'Admin';

// ── POST: quick restock or adjustment from this page ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_stock') {
        $pid   = (int)($_POST['product_id'] ?? 0);
        $qty   = abs((int)($_POST['add_qty'] ?? 0));
        $notes = sanitize($_POST['notes'] ?? '');
        if ($pid > 0 && $qty > 0) {
            $res = recordStockMovement(
                $pid, $qty, 'stock_added',
                $currentUserId, $currentUserName,
                null, null, $notes ?: 'Restock via Inventory page'
            );
            setFlash($res['success'] ? 'success' : 'error',
                     $res['success'] ? "Stock updated. New qty: {$res['qty_after']}." : $res['message']);
        }
    } elseif ($action === 'adjust') {
        $pid      = (int)($_POST['product_id'] ?? 0);
        $adjType  = $_POST['adj_type'] ?? 'remove';
        $adjQty   = abs((int)($_POST['adj_qty'] ?? 0));
        $moveType = sanitize($_POST['movement_type'] ?? 'manual_adjustment');
        $notes    = sanitize($_POST['adj_reason'] ?? '');
        $allowed  = ['manual_adjustment','damaged','expired','lost','correction','return'];
        if (!in_array($moveType, $allowed, true)) $moveType = 'manual_adjustment';
        if ($pid > 0 && $adjQty > 0) {
            $delta = ($adjType === 'remove') ? -$adjQty : +$adjQty;
            $res   = recordStockMovement(
                $pid, $delta, $moveType,
                $currentUserId, $currentUserName,
                null, null, $notes ?: 'Manual adjustment'
            );
            setFlash($res['success'] ? 'success' : 'error',
                     $res['success'] ? "Adjustment saved. New qty: {$res['qty_after']}." : $res['message']);
        }
    }
    redirect(BASE_URL . '/admin/inventory.php');
}

// ── Summary stats ──────────────────────────────────────────────────────────
$totalTypes = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalUnits = (int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM products")->fetchColumn();

// Detect low_stock_threshold column — may not exist on older InfinityFree schema
$invHasLST = false;
try {
    $lstCheck = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'products'
           AND COLUMN_NAME  = 'low_stock_threshold'"
    );
    $lstCheck->execute();
    $invHasLST = ((int)$lstCheck->fetchColumn() > 0);
} catch (Exception $e) { $invHasLST = false; }

$sysThresh = (int)APP_LOW_STOCK_THRESHOLD;
if ($invHasLST) {
    $totalLow = (int)$db->query(
        "SELECT COUNT(*) FROM products WHERE quantity > 0
         AND quantity <= GREATEST(low_stock_threshold, $sysThresh)"
    )->fetchColumn();
} else {
    $totalLow = (int)$db->query(
        "SELECT COUNT(*) FROM products WHERE quantity > 0 AND quantity <= $sysThresh"
    )->fetchColumn();
}
$totalOut   = (int)$db->query("SELECT COUNT(*) FROM products WHERE quantity=0")->fetchColumn();

// ── Inventory list ─────────────────────────────────────────────────────────
$invFilter = $_GET['inv_filter'] ?? '';
$invSearch = sanitize($_GET['q'] ?? '');
$invCat    = (int)($_GET['cat'] ?? 0);

$where  = '1=1';
$params = [];
if ($invSearch) { $where .= ' AND (p.name LIKE ? OR p.sku LIKE ?)'; $params[] = "%$invSearch%"; $params[] = "%$invSearch%"; }
if ($invCat)    { $where .= ' AND p.category_id = ?'; $params[] = $invCat; }
if ($invFilter === 'low') {
    if ($invHasLST) {
        $where .= " AND p.quantity > 0 AND p.quantity <= GREATEST(p.low_stock_threshold, $sysThresh)";
    } else {
        $where .= " AND p.quantity > 0 AND p.quantity <= $sysThresh";
    }
}
if ($invFilter === 'out')   { $where .= ' AND p.quantity = 0'; }
if ($invFilter === 'ok') {
    if ($invHasLST) {
        $where .= " AND p.quantity > GREATEST(p.low_stock_threshold, $sysThresh)";
    } else {
        $where .= " AND p.quantity > $sysThresh";
    }
}

$invStmt = $db->prepare("
    SELECT p.*, c.name AS category,
           (SELECT sm.created_at FROM stock_movements sm
            WHERE sm.product_id = p.id
            ORDER BY sm.created_at DESC LIMIT 1) AS last_movement
    FROM   products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE  $where
    ORDER  BY p.name
");
$invStmt->execute($params);
$inventory = $invStmt->fetchAll();

// ── Movement history (most recent 200) ────────────────────────────────────
$histSearch  = sanitize($_GET['hq'] ?? '');
$histType    = sanitize($_GET['htype'] ?? '');
$histProduct = (int)($_GET['hpid'] ?? 0);
$histFrom    = sanitize($_GET['hfrom'] ?? date('Y-m-01'));
$histTo      = sanitize($_GET['hto']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $histFrom)) $histFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $histTo))   $histTo   = date('Y-m-d');

$hWhere  = "DATE(sm.created_at) BETWEEN ? AND ?";
$hParams = [$histFrom, $histTo];
if ($histSearch)  { $hWhere .= ' AND (sm.product_name LIKE ? OR sm.sku LIKE ? OR sm.notes LIKE ?)'; $hParams[] = "%$histSearch%"; $hParams[] = "%$histSearch%"; $hParams[] = "%$histSearch%"; }
if ($histType)    { $hWhere .= ' AND sm.movement_type = ?'; $hParams[] = $histType; }
if ($histProduct) { $hWhere .= ' AND sm.product_id = ?'; $hParams[] = $histProduct; }

$histStmt = $db->prepare("
    SELECT sm.*, u.name AS actor
    FROM   stock_movements sm
    LEFT JOIN users u ON u.id = sm.user_id
    WHERE  $hWhere
    ORDER  BY sm.created_at DESC
    LIMIT  300
");
$histStmt->execute($hParams);
$movements = $histStmt->fetchAll();

$categories   = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$movementTypes = [
    'initial_stock'    => 'Initial Stock',
    'stock_added'      => 'Stock Added',
    'sale'             => 'Sale (POS)',
    'order_placed'     => 'Order Placed',
    'cancelled_order'  => 'Cancelled Order',
    'return'           => 'Return',
    'manual_adjustment'=> 'Manual Adjustment',
    'damaged'          => 'Damaged',
    'expired'          => 'Expired',
    'lost'             => 'Lost / Theft',
    'correction'       => 'Stock Correction',
];

// All active products for quick-access dropdowns
$allProducts = $db->query("SELECT id, name, quantity FROM products ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- ── Summary Cards ──────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-blue">
      <i class="bi bi-box-seam stat-icon"></i>
      <div class="stat-value"><?= number_format($totalTypes) ?></div>
      <div class="stat-label">Product Types</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-green">
      <i class="bi bi-stack stat-icon"></i>
      <div class="stat-value"><?= number_format($totalUnits) ?></div>
      <div class="stat-label">Total Units</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-orange">
      <i class="bi bi-exclamation-triangle stat-icon"></i>
      <div class="stat-value"><?= number_format($totalLow) ?></div>
      <div class="stat-label">Low Stock Items</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-red">
      <i class="bi bi-x-circle stat-icon"></i>
      <div class="stat-value"><?= number_format($totalOut) ?></div>
      <div class="stat-label">Out of Stock</div>
    </div>
  </div>
</div>

<!-- ── Inventory Table ────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-table me-2"></i>Current Inventory</span>
    <div class="d-flex gap-2 no-print">
      <a href="<?= BASE_URL ?>/reports/index.php?type=inventory" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-printer me-1"></i>Print
      </a>
    </div>
  </div>

  <!-- Inventory filters -->
  <div class="card-body border-bottom py-2 no-print">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Search name or SKU…" value="<?= htmlspecialchars($invSearch) ?>">
      </div>
      <div class="col-md-2">
        <select name="cat" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $invCat==$c['id']?'selected':'' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="inv_filter" class="form-select form-select-sm">
          <option value=""   <?= $invFilter===''   ?'selected':'' ?>>All Stock</option>
          <option value="ok" <?= $invFilter==='ok' ?'selected':'' ?>>In Stock</option>
          <option value="low"<?= $invFilter==='low'?'selected':'' ?>>Low Stock</option>
          <option value="out"<?= $invFilter==='out'?'selected':'' ?>>Out of Stock</option>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
        <a href="<?= BASE_URL ?>/admin/inventory.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
      </div>
    </form>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Product / SKU</th>
            <th>Category</th>
            <th>Sell Price</th>
            <th>Cost</th>
            <th>Stock Qty</th>
            <th>Status</th>
            <th>Last Movement</th>
            <th class="no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($inventory as $p): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
              <small class="text-muted font-monospace"><?= htmlspecialchars($p['sku']) ?></small>
            </td>
            <td><small><?= htmlspecialchars($p['category'] ?? '—') ?></small></td>
            <td class="text-success fw-bold"><?= money($p['price']) ?></td>
            <td class="text-muted"><?= money($p['cost_price']) ?></td>
            <td><?= stockBadge($p) ?></td>
            <td><?= statusBadge($p['status']) ?></td>
            <td>
              <small class="text-muted">
                <?= $p['last_movement'] ? date('M d, Y', strtotime($p['last_movement'])) : '—' ?>
              </small>
            </td>
            <td class="no-print">
              <div class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-success"
                        title="Add stock"
                        onclick='openAddStockModal(<?= (int)$p['id'] ?>,
                                 <?= json_encode($p['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                                 <?= (int)$p['quantity'] ?>)'>
                  <i class="bi bi-plus-circle"></i>
                </button>
                <button class="btn btn-sm btn-outline-warning"
                        title="Manual adjustment"
                        onclick='openAdjustModal(<?= (int)$p['id'] ?>,
                                 <?= json_encode($p['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                                 <?= (int)$p['quantity'] ?>)'>
                  <i class="bi bi-sliders"></i>
                </button>
                <a href="<?= BASE_URL ?>/admin/inventory.php?hpid=<?= (int)$p['id'] ?>#history"
                   class="btn btn-sm btn-outline-secondary" title="View movement history">
                  <i class="bi bi-clock-history"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($inventory)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-5">No products found.</td>
          </tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td colspan="4" class="text-end fw-bold">Total Units (shown):</td>
            <td class="fw-bold text-primary">
              <?= number_format(array_sum(array_column($inventory, 'quantity'))) ?>
            </td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- ── Stock Movement History ──────────────────────────────────────────── -->
<div class="card" id="history">
  <div class="card-header">
    <i class="bi bi-clock-history me-2"></i>Stock Movement History
    <span class="badge bg-secondary ms-2"><?= count($movements) ?> records</span>
  </div>

  <!-- History filters -->
  <div class="card-body border-bottom py-2 no-print">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label" style="font-size:.75rem;margin-bottom:3px">From</label>
        <input type="date" name="hfrom" class="form-control form-control-sm" value="<?= $histFrom ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label" style="font-size:.75rem;margin-bottom:3px">To</label>
        <input type="date" name="hto" class="form-control form-control-sm" value="<?= $histTo ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label" style="font-size:.75rem;margin-bottom:3px">Type</label>
        <select name="htype" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach ($movementTypes as $v => $l): ?>
          <option value="<?= $v ?>" <?= $histType===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label" style="font-size:.75rem;margin-bottom:3px">Product</label>
        <select name="hpid" class="form-select form-select-sm">
          <option value="">All Products</option>
          <?php foreach ($allProducts as $ap): ?>
          <option value="<?= $ap['id'] ?>" <?= $histProduct==$ap['id']?'selected':'' ?>>
            <?= htmlspecialchars($ap['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label" style="font-size:.75rem;margin-bottom:3px">Search</label>
        <input type="text" name="hq" class="form-control form-control-sm"
               placeholder="Product / SKU / notes…" value="<?= htmlspecialchars($histSearch) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="<?= BASE_URL ?>/admin/inventory.php#history"
           class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
      </div>
    </form>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Date / Time</th>
            <th>Product</th>
            <th>SKU</th>
            <th>Before</th>
            <th>Change</th>
            <th>After</th>
            <th>Type</th>
            <th>Reference</th>
            <th>By</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($movements as $i => $m):
            $isPositive = $m['qty_change'] > 0;
            $typeLabel  = $movementTypes[$m['movement_type']] ?? ucwords(str_replace('_',' ',$m['movement_type']));
            $inList1    = array('initial_stock','stock_added','return','cancelled_order');
            $inList2    = array('sale','order_placed');
            $inList3    = array('damaged','expired','lost');
            if (in_array($m['movement_type'], $inList1)) {
                $typeBadge = 'bg-success';
            } elseif (in_array($m['movement_type'], $inList2)) {
                $typeBadge = 'bg-primary';
            } elseif (in_array($m['movement_type'], $inList3)) {
                $typeBadge = 'bg-danger';
            } else {
                $typeBadge = 'bg-secondary';
            }
          ?>
          <tr>
            <td><small class="text-muted"><?= (int)$m['id'] ?></small></td>
            <td>
              <small>
                <?= date('M d, Y', strtotime($m['created_at'])) ?><br>
                <span class="text-muted"><?= date('h:i A', strtotime($m['created_at'])) ?></span>
              </small>
            </td>
            <td>
              <div class="fw-semibold" style="font-size:.84rem">
                <?= htmlspecialchars($m['product_name']) ?>
              </div>
            </td>
            <td><small class="font-monospace text-muted"><?= htmlspecialchars($m['sku']) ?></small></td>
            <td class="text-center"><?= (int)$m['qty_before'] ?></td>
            <td class="text-center fw-bold <?= $isPositive ? 'text-success' : 'text-danger' ?>">
              <?= $isPositive ? '+' : '' ?><?= (int)$m['qty_change'] ?>
            </td>
            <td class="text-center fw-bold"><?= (int)$m['qty_after'] ?></td>
            <td>
              <span class="badge <?= $typeBadge ?>" style="font-size:.66rem">
                <?= htmlspecialchars($typeLabel) ?>
              </span>
            </td>
            <td>
              <?php if ($m['reference_id']): ?>
              <small>
                <?php if ($m['reference_type'] === 'order'): ?>
                <a href="<?= BASE_URL ?>/admin/orders.php?view=<?= (int)$m['reference_id'] ?>"
                   class="text-primary">Order #<?= (int)$m['reference_id'] ?></a>
                <?php elseif ($m['reference_type'] === 'sale'): ?>
                <a href="<?= BASE_URL ?>/admin/sales.php?view=<?= (int)$m['reference_id'] ?>"
                   class="text-primary">Sale #<?= (int)$m['reference_id'] ?></a>
                <?php else: ?>
                #<?= (int)$m['reference_id'] ?>
                <?php endif; ?>
              </small>
              <?php else: ?>
              <small class="text-muted">—</small>
              <?php endif; ?>
            </td>
            <td><small><?= htmlspecialchars($m['actor'] ?? $m['user_name'] ?? '—') ?></small></td>
            <td>
              <small class="text-muted">
                <?= htmlspecialchars(mb_strimwidth($m['notes'] ?? '', 0, 50, '…')) ?>
              </small>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($movements)): ?>
          <tr>
            <td colspan="11" class="text-center text-muted py-5">
              No stock movements found for this period.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ ADD STOCK MODAL ═════════════════════════════════════════════════════ -->
<div class="modal fade" id="addStockModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-plus-circle me-2 text-success"></i>Add Stock
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action"     value="add_stock">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="product_id" id="asProductId">
        <div class="modal-body">
          <p class="mb-3">
            Product: <strong id="asProductName"></strong><br>
            Current stock: <strong id="asCurrentQty" class="text-primary"></strong>
          </p>
          <div class="mb-3">
            <label class="form-label">Quantity to Add <span class="text-danger">*</span></label>
            <input type="number" name="add_qty" id="asQty"
                   class="form-control" min="1" required placeholder="e.g. 50">
            <div class="form-text">
              New total will be: <strong id="asNewTotal" class="text-success">—</strong>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes / Supplier (optional)</label>
            <input type="text" name="notes" class="form-control"
                   placeholder="e.g. Supplier: ABC Ltd, Invoice #123">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-bold">
            <i class="bi bi-plus-circle me-1"></i>Add Stock
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ ADJUST MODAL ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="adjustModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-sliders me-2 text-warning"></i>Manual Stock Adjustment
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action"     value="adjust">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="product_id" id="adjProductId">
        <div class="modal-body">
          <p class="mb-3">
            Product: <strong id="adjProductName"></strong><br>
            Current stock: <strong id="adjCurrentQty" class="text-primary"></strong>
          </p>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Direction</label>
              <select name="adj_type" id="adjType" class="form-select" onchange="calcAdjTotal()">
                <option value="remove">Remove (−)</option>
                <option value="add">Add (+)</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Quantity <span class="text-danger">*</span></label>
              <input type="number" name="adj_qty" id="adjQty" class="form-control"
                     min="1" required oninput="calcAdjTotal()">
            </div>
            <div class="col-12">
              <div class="p-3 rounded" style="background:var(--surface-2,#f9f4f7)">
                New total will be: <strong id="adjNewTotal" class="text-warning">—</strong>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Reason / Type</label>
              <select name="movement_type" class="form-select">
                <option value="manual_adjustment">Manual Adjustment</option>
                <option value="damaged">Damaged</option>
                <option value="expired">Expired</option>
                <option value="lost">Lost / Theft</option>
                <option value="correction">Stock Count Correction</option>
                <option value="return">Customer Return</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Notes</label>
              <input type="text" name="adj_reason" class="form-control"
                     placeholder="Optional details">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning fw-bold">
            <i class="bi bi-check-circle me-1"></i>Apply Adjustment
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
var _asCurrentQty  = 0;
var _adjCurrentQty = 0;

function openAddStockModal(pid, pname, currentQty) {
  _asCurrentQty = parseInt(currentQty) || 0;
  document.getElementById('asProductId').value         = pid;
  document.getElementById('asProductName').textContent = pname;
  document.getElementById('asCurrentQty').textContent  = _asCurrentQty;
  document.getElementById('asQty').value               = '';
  document.getElementById('asNewTotal').textContent    = '—';
  new bootstrap.Modal(document.getElementById('addStockModal')).show();
}

function openAdjustModal(pid, pname, currentQty) {
  _adjCurrentQty = parseInt(currentQty) || 0;
  document.getElementById('adjProductId').value         = pid;
  document.getElementById('adjProductName').textContent = pname;
  document.getElementById('adjCurrentQty').textContent  = _adjCurrentQty;
  document.getElementById('adjQty').value               = '';
  document.getElementById('adjType').value              = 'remove';
  document.getElementById('adjNewTotal').textContent    = '—';
  new bootstrap.Modal(document.getElementById('adjustModal')).show();
}

function calcAdjTotal() {
  var qty  = parseInt(document.getElementById('adjQty').value) || 0;
  var type = document.getElementById('adjType').value;
  var n    = type === 'add' ? (_adjCurrentQty + qty) : (_adjCurrentQty - qty);
  var el   = document.getElementById('adjNewTotal');
  el.textContent = qty > 0 ? n : '—';
  el.style.color = (n < 0) ? '#dc2626' : '#059669';
}

document.addEventListener('DOMContentLoaded', function () {
  var asQtyEl = document.getElementById('asQty');
  if (asQtyEl) {
    asQtyEl.addEventListener('input', function () {
      var add = parseInt(this.value) || 0;
      document.getElementById('asNewTotal').textContent =
        add > 0 ? (_asCurrentQty + add) : '—';
    });
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
