<?php
/**
 * MITCH COSMETICS SHOP — Staff Payroll Management (Admin)
 * Features: add payment, edit, mark paid, delete, printable payslip, summary stats
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Staff Payments';
$activeMenu = 'payments';
$db = getDB();

// ── Handle POST actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $staffId   = (int)($_POST['staff_id']      ?? 0);
        $period    = sanitize($_POST['pay_period']  ?? date('Y-m'));
        $basic     = abs((float)($_POST['basic_salary']  ?? 0));
        $allow     = abs((float)($_POST['allowances']    ?? 0));
        $deduct    = abs((float)($_POST['deductions']    ?? 0));
        $netPay    = max(0, $basic + $allow - $deduct);
        // Backend enforcement: only cash is accepted
        $method    = validatePaymentMethod($_POST['payment_method'] ?? '');
        $status    = in_array($_POST['status'] ?? '', ['pending','paid','cancelled'])
                     ? $_POST['status'] : 'pending';
        $notes     = sanitize($_POST['notes'] ?? '');
        $paidAt    = ($status === 'paid') ? date('Y-m-d H:i:s') : null;

        if (!$staffId || !$period) {
            setFlash('error', 'Staff member and pay period are required.');
            redirect(BASE_URL . '/admin/payments.php');
        }

        if ($action === 'add') {
            $db->prepare("INSERT INTO staff_payments
                (staff_id, pay_period, basic_salary, allowances, deductions,
                 net_pay, payment_method, status, notes, paid_at, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$staffId,$period,$basic,$allow,$deduct,
                          $netPay,$method,$status,$notes,$paidAt,$_SESSION['user_id']]);
            setFlash('success', 'Payment record added successfully.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            // Only update paid_at if status just became 'paid'
            $existingStmt = $db->prepare("SELECT status, paid_at FROM staff_payments WHERE id=?");
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch();
            if ($status === 'paid' && $existing && $existing['status'] !== 'paid') {
                $paidAt = date('Y-m-d H:i:s');
            } elseif ($existing) {
                $paidAt = $existing['paid_at'];
            }
            $db->prepare("UPDATE staff_payments SET
                staff_id=?, pay_period=?, basic_salary=?, allowances=?, deductions=?,
                net_pay=?, payment_method=?, status=?, notes=?, paid_at=?
                WHERE id=?")
               ->execute([$staffId,$period,$basic,$allow,$deduct,
                          $netPay,$method,$status,$notes,$paidAt,$id]);
            setFlash('success', 'Payment record updated.');
        }

    } elseif ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE staff_payments SET status='paid', paid_at=? WHERE id=?")
           ->execute([date('Y-m-d H:i:s'), $id]);
        setFlash('success', 'Payment marked as paid.');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM staff_payments WHERE id=?")->execute([$id]);
        setFlash('success', 'Payment record deleted.');
    }

    redirect(BASE_URL . '/admin/payments.php');
}

// ── Filters ────────────────────────────────────────────────────────────────
$filterPeriod = sanitize($_GET['period'] ?? '');
$filterStaff  = (int)($_GET['staff_id'] ?? 0);
$filterStatus = sanitize($_GET['status'] ?? '');
$viewId       = (int)($_GET['view'] ?? 0);

$where  = '1=1';
$params = [];
if ($filterPeriod) { $where .= ' AND sp.pay_period = ?';    $params[] = $filterPeriod; }
if ($filterStaff)  { $where .= ' AND sp.staff_id = ?';      $params[] = $filterStaff; }
if ($filterStatus) { $where .= ' AND sp.status = ?';        $params[] = $filterStatus; }

$payments = $db->prepare("
    SELECT sp.*, u.name AS staff_name, u.phone AS staff_phone,
           u.email AS staff_email, c.name AS creator_name
    FROM   staff_payments sp
    JOIN   users u ON u.id = sp.staff_id
    LEFT JOIN users c ON c.id = sp.created_by
    WHERE  $where
    ORDER  BY sp.pay_period DESC, sp.created_at DESC
");
$payments->execute($params);
$payments = $payments->fetchAll();

// Staff list for dropdown
$staffList = $db->query(
    "SELECT id, name, email FROM users WHERE role IN ('staff','admin') AND status='active' ORDER BY name"
)->fetchAll();

// Summary stats
$statsStmt = $db->prepare("
    SELECT
        COUNT(*)                                        AS total_records,
        COALESCE(SUM(net_pay),0)                       AS total_paid_out,
        COALESCE(SUM(CASE WHEN status='pending' THEN net_pay ELSE 0 END),0) AS pending_amount,
        COUNT(CASE WHEN status='pending' THEN 1 END)   AS pending_count
    FROM staff_payments sp WHERE $where
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch();

// Single payslip view
$viewPayment = null;
if ($viewId) {
    $vStmt = $db->prepare("
        SELECT sp.*, u.name AS staff_name, u.phone AS staff_phone, u.email AS staff_email
        FROM   staff_payments sp JOIN users u ON u.id=sp.staff_id
        WHERE  sp.id=?");
    $vStmt->execute([$viewId]);
    $viewPayment = $vStmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($viewPayment): ?>
<!-- ══ PAYSLIP PRINT VIEW ══════════════════════════════════════════════ -->
<div class="mb-3 d-flex justify-content-between align-items-center no-print">
    <a href="<?= BASE_URL ?>/admin/payments.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    <button onclick="window.print()" class="btn btn-primary">
        <i class="bi bi-printer me-2"></i>Print Payslip
    </button>
</div>
<div class="row justify-content-center">
  <div class="col-md-7 col-lg-6">
    <div class="card">
      <div class="card-body p-4" id="payslipContent">
        <!-- Payslip Header -->
        <div class="text-center mb-3 pb-3 border-bottom">
          <h4 class="fw-bold mb-0" style="color:var(--primary)"><?= APP_NAME ?></h4>
          <small class="text-muted"><?= APP_ADDRESS ?> | <?= APP_PHONE ?></small>
          <div class="mt-2">
            <span class="badge bg-primary px-3 py-2" style="font-size:.9rem">PAYSLIP</span>
          </div>
        </div>
        <!-- Staff Info -->
        <div class="row g-2 mb-3">
          <div class="col-6">
            <small class="text-muted d-block">Employee Name</small>
            <strong><?= htmlspecialchars($viewPayment['staff_name']) ?></strong>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">Pay Period</small>
            <strong><?= date('F Y', strtotime($viewPayment['pay_period'].'-01')) ?></strong>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">Email</small>
            <span><?= htmlspecialchars($viewPayment['staff_email']) ?></span>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">Phone</small>
            <span><?= htmlspecialchars($viewPayment['staff_phone'] ?? '—') ?></span>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">Payment Method</small>
            <span><?= ucfirst(str_replace('_',' ',$viewPayment['payment_method'])) ?></span>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">Status</small>
            <?= statusBadge($viewPayment['status']) ?>
          </div>
        </div>
        <!-- Earnings & Deductions Table -->
        <table class="table table-bordered mb-3">
          <thead class="table-light">
            <tr><th>Description</th><th class="text-end">Amount</th></tr>
          </thead>
          <tbody>
            <tr><td>Basic Salary</td><td class="text-end"><?= money($viewPayment['basic_salary']) ?></td></tr>
            <tr><td>Allowances</td><td class="text-end text-success">+ <?= money($viewPayment['allowances']) ?></td></tr>
            <tr><td>Deductions</td><td class="text-end text-danger">- <?= money($viewPayment['deductions']) ?></td></tr>
          </tbody>
          <tfoot class="table-success">
            <tr>
              <th class="fw-bold">NET PAY</th>
              <th class="text-end fw-bold" style="font-size:1.1rem"><?= money($viewPayment['net_pay']) ?></th>
            </tr>
          </tfoot>
        </table>
        <?php if ($viewPayment['notes']): ?>
        <p class="text-muted" style="font-size:.85rem">
          <strong>Notes:</strong> <?= htmlspecialchars($viewPayment['notes']) ?>
        </p>
        <?php endif; ?>
        <?php if ($viewPayment['paid_at']): ?>
        <p class="text-muted" style="font-size:.82rem">
          Paid on: <?= niceDate($viewPayment['paid_at']) ?>
        </p>
        <?php endif; ?>
        <!-- Signature Lines -->
        <div class="row mt-4 pt-2">
          <div class="col-5 text-center">
            <div style="border-top:1px solid #000;padding-top:6px;font-size:.8rem">
              Authorised Signature
            </div>
          </div>
          <div class="col-2"></div>
          <div class="col-5 text-center">
            <div style="border-top:1px solid #000;padding-top:6px;font-size:.8rem">
              Employee Signature
            </div>
          </div>
        </div>
        <div class="text-center mt-4" style="font-size:.75rem;color:#888">
          This is a computer-generated payslip — <?= APP_NAME ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══ PAYMENTS LIST ═══════════════════════════════════════════════════ -->

<!-- Summary Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card stat-blue">
      <i class="bi bi-receipt-cutoff stat-icon"></i>
      <div class="stat-value"><?= (int)$stats['total_records'] ?></div>
      <div class="stat-label">Total Records</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-green">
      <i class="bi bi-cash-stack stat-icon"></i>
      <div class="stat-value"><?= money($stats['total_paid_out']) ?></div>
      <div class="stat-label">Total Paid Out</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-orange">
      <i class="bi bi-hourglass-split stat-icon"></i>
      <div class="stat-value"><?= (int)$stats['pending_count'] ?></div>
      <div class="stat-label">Pending Payments</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card stat-red">
      <i class="bi bi-exclamation-circle stat-icon"></i>
      <div class="stat-value"><?= money($stats['pending_amount']) ?></div>
      <div class="stat-label">Pending Amount</div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">Pay Period</label>
        <input type="month" name="period" class="form-control" value="<?= htmlspecialchars($filterPeriod) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">Staff Member</label>
        <select name="staff_id" class="form-select">
          <option value="">All Staff</option>
          <?php foreach ($staffList as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterStaff==$s['id']?'selected':'' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1" style="font-size:.8rem">Status</label>
        <select name="status" class="form-select">
          <option value="">All</option>
          <option value="pending"   <?= $filterStatus==='pending'  ?'selected':'' ?>>Pending</option>
          <option value="paid"      <?= $filterStatus==='paid'     ?'selected':'' ?>>Paid</option>
          <option value="cancelled" <?= $filterStatus==='cancelled'?'selected':'' ?>>Cancelled</option>
        </select>
      </div>
      <div class="col-md-4 d-flex gap-2 align-items-end">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="<?= BASE_URL ?>/admin/payments.php" class="btn btn-outline-secondary">Reset</a>
        <button type="button" class="btn btn-success ms-auto"
                data-bs-toggle="modal" data-bs-target="#paymentModal"
                onclick="openAddModal()">
          <i class="bi bi-plus-lg me-1"></i>Add Payment
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Payments Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-cash-coin me-2"></i>Staff Payments
      <span class="badge bg-primary ms-2"><?= count($payments) ?></span>
    </span>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Staff</th><th>Period</th><th>Basic</th><th>Allowances</th>
            <th>Deductions</th><th>Net Pay</th><th>Method</th><th>Status</th>
            <th>Paid On</th><th class="no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($p['staff_name']) ?></div>
              <small class="text-muted"><?= htmlspecialchars($p['staff_email']) ?></small>
            </td>
            <td class="fw-semibold">
              <?= date('M Y', strtotime($p['pay_period'].'-01')) ?>
            </td>
            <td><?= money($p['basic_salary']) ?></td>
            <td class="text-success">+<?= money($p['allowances']) ?></td>
            <td class="text-danger">-<?= money($p['deductions']) ?></td>
            <td class="fw-bold text-success"><?= money($p['net_pay']) ?></td>
            <td>
              <span class="badge bg-light text-dark border">
                <?= ucfirst(str_replace('_',' ',$p['payment_method'])) ?>
              </span>
            </td>
            <td><?= statusBadge($p['status']) ?></td>
            <td>
              <small class="text-muted">
                <?= $p['paid_at'] ? date('M d, Y', strtotime($p['paid_at'])) : '—' ?>
              </small>
            </td>
            <td class="no-print">
              <div class="d-flex gap-1 flex-wrap">
                <!-- View Payslip -->
                <a href="?view=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Payslip">
                  <i class="bi bi-file-earmark-text"></i>
                </a>
                <!-- Edit -->
                <button class="btn btn-sm btn-outline-secondary"
                        onclick='openEditModal(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                        title="Edit">
                  <i class="bi bi-pencil"></i>
                </button>
                <!-- Mark Paid -->
                <?php if ($p['status'] === 'pending'): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <button type="submit" class="btn btn-sm btn-success" title="Mark as Paid">
                    <i class="bi bi-check-circle"></i>
                  </button>
                </form>
                <?php endif; ?>
                <!-- Delete -->
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Delete this payment record?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($payments)): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-5">
              <i class="bi bi-cash-coin" style="font-size:2.5rem;opacity:.3"></i>
              <p class="mt-2 mb-0">No payment records found.</p>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ ADD / EDIT PAYMENT MODAL ════════════════════════════════════════ -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="paymentModalTitle">Add Payment Record</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action"     id="pmAction"   value="add">
        <input type="hidden" name="id"         id="pmId"       value="">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Staff Member <span class="text-danger">*</span></label>
              <select name="staff_id" id="pmStaff" class="form-select" required>
                <option value="">— Select Staff —</option>
                <?php foreach ($staffList as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Pay Period <span class="text-danger">*</span></label>
              <input type="month" name="pay_period" id="pmPeriod" class="form-control"
                     value="<?= date('Y-m') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Basic Salary ($) <span class="text-danger">*</span></label>
              <input type="number" name="basic_salary" id="pmBasic" class="form-control"
                     step="0.01" min="0" value="0" oninput="calcNet()" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Allowances ($)</label>
              <input type="number" name="allowances" id="pmAllow" class="form-control"
                     step="0.01" min="0" value="0" oninput="calcNet()">
              <div class="form-text">Transport, housing, etc.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Deductions ($)</label>
              <input type="number" name="deductions" id="pmDeduct" class="form-control"
                     step="0.01" min="0" value="0" oninput="calcNet()">
              <div class="form-text">Tax, absence, loans, etc.</div>
            </div>
            <!-- Net Pay display -->
            <div class="col-12">
              <div class="p-3 rounded d-flex justify-content-between align-items-center"
                   style="background:#f0fdf4;border:1px solid #bbf7d0">
                <span class="fw-bold">NET PAY</span>
                <span id="pmNetDisplay" class="fw-bold text-success" style="font-size:1.3rem">$0.00</span>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Payment Method</label>
              <!-- Cash is the only accepted payment method -->
              <input type="hidden" name="payment_method" id="pmMethod" value="cash">
              <input type="text" class="form-control" value="Cash Payment" readonly
                     style="background:#f8f0f5;font-weight:700;color:var(--primary)">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="pmStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="paid">Paid</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="pmNotes" class="form-control" rows="2"
                        placeholder="Optional notes…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-bold" id="pmSubmitBtn">
            Add Payment
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function calcNet() {
    const b = parseFloat(document.getElementById('pmBasic').value)  || 0;
    const a = parseFloat(document.getElementById('pmAllow').value)  || 0;
    const d = parseFloat(document.getElementById('pmDeduct').value) || 0;
    const net = Math.max(0, b + a - d);
    document.getElementById('pmNetDisplay').textContent = '$' + net.toFixed(2);
}

function openAddModal() {
    document.getElementById('paymentModalTitle').textContent = 'Add Payment Record';
    document.getElementById('pmAction').value  = 'add';
    document.getElementById('pmSubmitBtn').textContent = 'Add Payment';
    document.getElementById('pmId').value      = '';
    document.getElementById('pmStaff').value   = '';
    document.getElementById('pmPeriod').value  = '<?= date('Y-m') ?>';
    document.getElementById('pmBasic').value   = '0';
    document.getElementById('pmAllow').value   = '0';
    document.getElementById('pmDeduct').value  = '0';
    document.getElementById('pmMethod').value  = 'cash';
    document.getElementById('pmStatus').value  = 'pending';
    document.getElementById('pmNotes').value   = '';
    calcNet();
}

function openEditModal(p) {
    document.getElementById('paymentModalTitle').textContent = 'Edit Payment Record';
    document.getElementById('pmAction').value  = 'edit';
    document.getElementById('pmSubmitBtn').textContent = 'Save Changes';
    document.getElementById('pmId').value      = p.id;
    document.getElementById('pmStaff').value   = p.staff_id;
    document.getElementById('pmPeriod').value  = p.pay_period;
    document.getElementById('pmBasic').value   = p.basic_salary;
    document.getElementById('pmAllow').value   = p.allowances;
    document.getElementById('pmDeduct').value  = p.deductions;
    document.getElementById('pmMethod').value  = p.payment_method;
    document.getElementById('pmStatus').value  = p.status;
    document.getElementById('pmNotes').value   = p.notes || '';
    calcNet();
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
