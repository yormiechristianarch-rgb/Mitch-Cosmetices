<?php
/**
 * MITCH COSMETICS SHOP — My Payments (Staff View)
 * Staff can see their own payment history and print payslips.
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('staff', 'admin');

$pageTitle  = 'My Payments';
$activeMenu = 'my_payments';
$db  = getDB();
$uid = (int) $_SESSION['user_id'];

// ── Filters ────────────────────────────────────────────────────────────────
$filterPeriod = sanitize($_GET['period'] ?? '');
$filterStatus = sanitize($_GET['status'] ?? '');
$viewId       = (int) ($_GET['view'] ?? 0);

$where  = 'sp.staff_id = ?';
$params = [$uid];
if ($filterPeriod) { $where .= ' AND sp.pay_period = ?'; $params[] = $filterPeriod; }
if ($filterStatus) { $where .= ' AND sp.status = ?';     $params[] = $filterStatus; }

$stmt = $db->prepare("
    SELECT sp.*, u.name AS staff_name, u.phone AS staff_phone, u.email AS staff_email
    FROM   staff_payments sp
    JOIN   users u ON u.id = sp.staff_id
    WHERE  $where
    ORDER  BY sp.pay_period DESC, sp.created_at DESC
");
$stmt->execute($params);
$payments = $stmt->fetchAll();

// ── Summary ────────────────────────────────────────────────────────────────
$sumStmt = $db->prepare("
    SELECT
        COUNT(*)                                                        AS total,
        COALESCE(SUM(CASE WHEN status='paid' THEN net_pay ELSE 0 END), 0) AS total_received,
        COALESCE(SUM(CASE WHEN status='pending' THEN net_pay ELSE 0 END),0) AS pending_amount,
        COUNT(CASE WHEN status='pending' THEN 1 END)                    AS pending_count
    FROM staff_payments
    WHERE staff_id = ?
");
$sumStmt->execute([$uid]);
$summary = $sumStmt->fetch();

// ── Single Payslip View ────────────────────────────────────────────────────
$viewPayment = null;
if ($viewId) {
    $vStmt = $db->prepare("
        SELECT sp.*, u.name AS staff_name, u.phone AS staff_phone, u.email AS staff_email
        FROM   staff_payments sp
        JOIN   users u ON u.id = sp.staff_id
        WHERE  sp.id = ? AND sp.staff_id = ?
    ");
    $vStmt->execute([$viewId, $uid]);
    $viewPayment = $vStmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($viewPayment): ?>
<!-- ══ PAYSLIP VIEW ════════════════════════════════════════════════════ -->
<div class="mb-3 d-flex justify-content-between align-items-center no-print">
    <a href="<?= BASE_URL ?>/staff/my_payments.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to My Payments
    </a>
    <button onclick="window.print()" class="btn btn-primary">
        <i class="bi bi-printer me-2"></i>Print Payslip
    </button>
</div>

<div class="row justify-content-center">
  <div class="col-md-7 col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body p-4" id="payslipPrint">

        <!-- Header -->
        <div class="text-center mb-3 pb-3 border-bottom">
          <h4 class="fw-bold mb-1" style="color:var(--primary)"><?= APP_NAME ?></h4>
          <div class="text-muted" style="font-size:.82rem">
            <?= APP_ADDRESS ?><br>
            <?= APP_PHONE ?> &nbsp;|&nbsp; <?= APP_EMAIL ?>
          </div>
          <div class="mt-2">
            <span class="badge bg-primary px-4 py-2" style="font-size:.9rem;letter-spacing:1px">
              PAYSLIP
            </span>
          </div>
        </div>

        <!-- Employee Info -->
        <div class="row g-2 mb-4 p-3 rounded" style="background:#f8f0f5">
          <div class="col-6">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px">Employee</div>
            <div class="fw-bold"><?= htmlspecialchars($viewPayment['staff_name']) ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px">Pay Period</div>
            <div class="fw-bold"><?= date('F Y', strtotime($viewPayment['pay_period'] . '-01')) ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px">Email</div>
            <div><?= htmlspecialchars($viewPayment['staff_email']) ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px">Phone</div>
            <div><?= htmlspecialchars($viewPayment['staff_phone'] ?? '—') ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px">Payment Method</div>
            <div><?= ucfirst(str_replace('_', ' ', $viewPayment['payment_method'])) ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px">Status</div>
            <div><?= statusBadge($viewPayment['status']) ?></div>
          </div>
        </div>

        <!-- Earnings / Deductions Table -->
        <table class="table table-bordered mb-3">
          <thead class="table-light">
            <tr>
              <th>Description</th>
              <th class="text-end">Amount (<?= APP_CURRENCY ?>)</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><i class="bi bi-plus-circle text-success me-2"></i>Basic Salary</td>
              <td class="text-end"><?= money($viewPayment['basic_salary']) ?></td>
            </tr>
            <tr>
              <td><i class="bi bi-plus-circle text-success me-2"></i>Allowances</td>
              <td class="text-end text-success">+ <?= money($viewPayment['allowances']) ?></td>
            </tr>
            <tr>
              <td><i class="bi bi-dash-circle text-danger me-2"></i>Deductions</td>
              <td class="text-end text-danger">− <?= money($viewPayment['deductions']) ?></td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="table-success">
              <th class="fw-bold fs-6">NET PAY</th>
              <th class="text-end fw-bold fs-5"><?= money($viewPayment['net_pay']) ?></th>
            </tr>
          </tfoot>
        </table>

        <?php if (!empty($viewPayment['notes'])): ?>
        <div class="alert alert-light border py-2 mb-3" style="font-size:.85rem">
          <strong>Notes:</strong> <?= htmlspecialchars($viewPayment['notes']) ?>
        </div>
        <?php endif; ?>

        <?php if ($viewPayment['paid_at']): ?>
        <p class="text-muted mb-3" style="font-size:.82rem">
          <i class="bi bi-calendar-check me-1"></i>
          Payment Date: <?= niceDate($viewPayment['paid_at']) ?>
        </p>
        <?php endif; ?>

        <!-- Signature Lines -->
        <div class="row mt-4 pt-2">
          <div class="col-5 text-center">
            <div class="mt-4 pt-2" style="border-top:1px solid #333;font-size:.78rem;color:#555">
              Authorised by Management
            </div>
          </div>
          <div class="col-2"></div>
          <div class="col-5 text-center">
            <div class="mt-4 pt-2" style="border-top:1px solid #333;font-size:.78rem;color:#555">
              Employee Signature
            </div>
          </div>
        </div>

        <!-- Footer note -->
        <div class="text-center mt-4 pt-3 border-top" style="font-size:.72rem;color:#aaa">
          This is a computer-generated payslip — <?= APP_NAME ?><br>
          Generated: <?= date('M d, Y h:i A') ?>
        </div>

      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══ PAYMENTS LIST ═══════════════════════════════════════════════════ -->

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <i class="bi bi-receipt-cutoff stat-icon"></i>
      <div class="stat-value"><?= (int) $summary['total'] ?></div>
      <div class="stat-label">Total Records</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <i class="bi bi-cash-stack stat-icon"></i>
      <div class="stat-value"><?= money($summary['total_received']) ?></div>
      <div class="stat-label">Total Received</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <i class="bi bi-hourglass-split stat-icon"></i>
      <div class="stat-value"><?= (int) $summary['pending_count'] ?></div>
      <div class="stat-label">Pending</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-pink">
      <i class="bi bi-wallet2 stat-icon"></i>
      <div class="stat-value"><?= money($summary['pending_amount']) ?></div>
      <div class="stat-label">Pending Amount</div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label mb-1" style="font-size:.8rem">Pay Period</label>
        <input type="month" name="period" class="form-control"
               value="<?= htmlspecialchars($filterPeriod) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">Status</label>
        <select name="status" class="form-select">
          <option value="">All</option>
          <option value="pending"   <?= $filterStatus === 'pending'   ? 'selected' : '' ?>>Pending</option>
          <option value="paid"      <?= $filterStatus === 'paid'      ? 'selected' : '' ?>>Paid</option>
          <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2 align-items-end">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="<?= BASE_URL ?>/staff/my_payments.php" class="btn btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Payments Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-cash-coin me-2"></i>My Payment History
      <span class="badge bg-primary ms-2"><?= count($payments) ?></span>
    </span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Period</th>
            <th>Basic Salary</th>
            <th>Allowances</th>
            <th>Deductions</th>
            <th>Net Pay</th>
            <th>Method</th>
            <th>Status</th>
            <th>Paid On</th>
            <th>Payslip</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
          <tr>
            <td class="fw-bold">
              <?= date('F Y', strtotime($p['pay_period'] . '-01')) ?>
            </td>
            <td><?= money($p['basic_salary']) ?></td>
            <td class="text-success">+ <?= money($p['allowances']) ?></td>
            <td class="text-danger">− <?= money($p['deductions']) ?></td>
            <td class="fw-bold text-success" style="font-size:1rem">
              <?= money($p['net_pay']) ?>
            </td>
            <td>
              <span class="badge bg-light text-dark border">
                <?= ucfirst(str_replace('_', ' ', $p['payment_method'])) ?>
              </span>
            </td>
            <td><?= statusBadge($p['status']) ?></td>
            <td>
              <small class="text-muted">
                <?= $p['paid_at'] ? date('M d, Y', strtotime($p['paid_at'])) : '—' ?>
              </small>
            </td>
            <td>
              <a href="?view=<?= (int) $p['id'] ?>"
                 class="btn btn-sm btn-outline-primary" title="View & Print Payslip">
                <i class="bi bi-file-earmark-text me-1"></i>Payslip
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($payments)): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-5">
              <i class="bi bi-cash-coin" style="font-size:2.5rem;opacity:.3"></i>
              <p class="mt-2 mb-0">No payment records found.</p>
              <small>Contact your administrator if you have questions about your payments.</small>
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
