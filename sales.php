<?php
/**
 * MITCH COSMETICS SHOP — Sales History
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');

$pageTitle  = 'Sales History';
$activeMenu = 'sales';
$db = getDB();

$dateFrom = sanitize($_GET['from'] ?? date('Y-m-01'));
$dateTo   = sanitize($_GET['to']   ?? date('Y-m-d'));
$search   = sanitize($_GET['q']    ?? '');

$where  = "DATE(s.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($search) { $where .= " AND (s.receipt_no LIKE ? OR s.customer_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$stmt = $db->prepare("
    SELECT s.*, u.name AS staff_name
    FROM sales s LEFT JOIN users u ON u.id = s.staff_id
    WHERE $where ORDER BY s.created_at DESC
");
$stmt->execute($params);
$sales = $stmt->fetchAll();

$totalRevenue  = array_sum(array_column($sales, 'total_amount'));
$totalDiscount = array_sum(array_column($sales, 'discount'));

// Detect optional columns
function salesHasColumn(string $col): bool {
    static $cache = [];
    if (isset($cache[$col])) return $cache[$col];
    $db = getDB();
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME=?");
        $s->execute([$col]);
        $cache[$col] = (int)$s->fetchColumn() > 0;
    } catch (Exception $e) { $cache[$col] = false; }
    return $cache[$col];
}

// View single sale
$viewSale  = null;
$saleItems = [];
if (isset($_GET['view'])) {
    $sid = (int)$_GET['view'];
    $viewSale = $db->prepare("SELECT s.*, u.name AS staff_name FROM sales s LEFT JOIN users u ON u.id=s.staff_id WHERE s.id=?");
    $viewSale->execute([$sid]);
    $viewSale = $viewSale->fetch();
    if ($viewSale) {
        $saleItems = $db->prepare("SELECT * FROM sale_items WHERE sale_id=?");
        $saleItems->execute([$sid]);
        $saleItems = $saleItems->fetchAll();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($viewSale): ?>
<div class="mb-3 d-flex justify-content-between">
  <a href="<?= BASE_URL ?>/admin/sales.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
  <button onclick="printSection('saleReceiptPrint')" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Print</button>
</div>
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card"><div class="card-body p-2">
      <div id="saleReceiptPrint">
        <div class="receipt-wrapper">
          <div class="receipt-header">
            <h4><?= APP_NAME ?></h4>
            <div><?= APP_ADDRESS ?></div>
            <div><?= APP_PHONE ?></div>
          </div>
          <div class="receipt-divider"></div>
          <table class="receipt-table">
            <tr><td><strong>Receipt #:</strong></td><td style="text-align:right"><?= htmlspecialchars($viewSale['receipt_no']) ?></td></tr>
            <tr><td><strong>Date:</strong></td><td style="text-align:right"><?= niceDate($viewSale['created_at']) ?></td></tr>
            <tr><td><strong>Customer:</strong></td><td style="text-align:right"><?= htmlspecialchars($viewSale['customer_name'] ?? 'Walk-in') ?></td></tr>
            <tr><td><strong>Cashier:</strong></td><td style="text-align:right"><?= htmlspecialchars($viewSale['staff_name'] ?? '—') ?></td></tr>
          </table>
          <div class="receipt-divider"></div>
          <table class="receipt-table">
            <tr><td><strong>Item</strong></td><td style="text-align:center"><strong>Qty</strong></td><td style="text-align:right"><strong>Price</strong></td><td style="text-align:right"><strong>Total</strong></td></tr>
            <?php foreach ($saleItems as $si): ?>
            <tr>
              <td><?= htmlspecialchars($si['product_name']) ?></td>
              <td style="text-align:center"><?= $si['quantity'] ?></td>
              <td style="text-align:right"><?= money($si['unit_price']) ?></td>
              <td style="text-align:right"><?= money($si['total']) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
          <div class="receipt-divider"></div>
          <table class="receipt-table">
            <tr><td>Subtotal:</td><td style="text-align:right"><?= money($viewSale['subtotal']) ?></td></tr>
            <?php if (($viewSale['discount'] ?? 0) > 0): ?><tr><td>Discount:</td><td style="text-align:right">- <?= money($viewSale['discount']) ?></td></tr><?php endif; ?>
            <tr class="receipt-total"><td><strong>GRAND TOTAL:</strong></td><td style="text-align:right"><strong><?= money($viewSale['total_amount']) ?></strong></td></tr>
            <?php if (!empty($viewSale['amount_tendered']) && $viewSale['amount_tendered'] > 0): ?>
            <tr><td>Amount Tendered:</td><td style="text-align:right"><?= money($viewSale['amount_tendered']) ?></td></tr>
            <tr><td>Change Due:</td><td style="text-align:right"><?= money(max(0, $viewSale['change_due'] ?? 0)) ?></td></tr>
            <?php endif; ?>
          </table>
          <div class="receipt-divider"></div>
          <div class="signature-line">
            <div class="sig-block"><div class="sig-line">Seller Signature</div></div>
            <div class="sig-block"><div class="sig-line">Customer Signature</div></div>
          </div>
          <div class="receipt-footer"><strong>Thank you for shopping at <?= APP_NAME ?>!</strong></div>
        </div>
      </div>
    </div></div>
  </div>
</div>

<?php else: ?>
<!-- Filter -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">From Date</label>
        <input type="date" name="from" class="form-control" value="<?= $dateFrom ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">To Date</label>
        <input type="date" name="to" class="form-control" value="<?= $dateTo ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">Search</label>
        <input type="text" name="q" class="form-control" placeholder="Receipt # or customer..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
        <a href="<?= BASE_URL ?>/admin/sales.php" class="btn btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card stat-green"><i class="bi bi-cash stat-icon"></i><div class="stat-value"><?= money($totalRevenue) ?></div><div class="stat-label">Total Revenue (<?= $dateFrom ?> – <?= $dateTo ?>)</div></div></div>
  <div class="col-md-4"><div class="stat-card stat-pink"><i class="bi bi-receipt stat-icon"></i><div class="stat-value"><?= count($sales) ?></div><div class="stat-label">Total Transactions</div></div></div>
  <div class="col-md-4"><div class="stat-card stat-orange"><i class="bi bi-tag stat-icon"></i><div class="stat-value"><?= money($totalDiscount) ?></div><div class="stat-label">Total Discounts Given</div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-graph-up me-2"></i>Sales History</span>
    <a href="<?= BASE_URL ?>/reports/index.php?type=sales&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Print Report</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="salesTable">
        <thead><tr><th>Receipt #</th><th>Customer</th><th>Staff</th><th>Payment</th><th>Subtotal</th><th>Discount</th><th>Total</th><th>Date</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($sales as $s): ?>
          <tr>
            <td class="fw-semibold font-monospace"><?= htmlspecialchars($s['receipt_no']) ?></td>
            <td><?= htmlspecialchars($s['customer_name'] ?? 'Walk-in') ?></td>
            <td><?= htmlspecialchars($s['staff_name'] ?? '—') ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_',' ',$s['payment_method'])) ?></span></td>
            <td><?= money($s['subtotal']) ?></td>
            <td class="text-danger"><?= ($s['discount'] ?? 0) > 0 ? '- '.money($s['discount']) : '—' ?></td>
            <td class="fw-bold text-success"><?= money($s['total_amount']) ?></td>
            <td><small class="text-muted"><?= niceDate($s['created_at']) ?></small></td>
            <td><a href="?view=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($sales)): ?><tr><td colspan="9" class="text-center text-muted py-5">No sales found for this period</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
