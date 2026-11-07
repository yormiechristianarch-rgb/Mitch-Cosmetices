<?php
/**
 * MITCH COSMETICS SHOP — Attendance Management (Admin)
 * Fix: year/month parsing now uses date() on a proper DateTime object
 *      instead of a fragile explode() on an arbitrary string.
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Attendance';
$activeMenu = 'attendance';
$db = getDB();

// ── Parse month param safely ───────────────────────────────────────────────
$monthRaw = sanitize($_GET['month'] ?? date('Y-m'));
// Validate format YYYY-MM; fall back to current month if malformed
if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $monthRaw)) {
    $monthRaw = date('Y-m');
}
$month = $monthRaw;
// Derive year and month number cleanly — no explode needed
$dtMonth = DateTime::createFromFormat('Y-m', $month);
$year    = (int) $dtMonth->format('Y');
$mon     = (int) $dtMonth->format('n');  // 1-12

$userId = (int) ($_GET['user_id'] ?? 0);

// ── Query ──────────────────────────────────────────────────────────────────
$where  = "DATE_FORMAT(a.date, '%Y-%m') = ?";
$params = [$month];
if ($userId > 0) {
    $where   .= ' AND a.user_id = ?';
    $params[] = $userId;
}

$records = $db->prepare("
    SELECT a.*, u.name AS staff_name
    FROM   attendance a
    JOIN   users      u ON u.id = a.user_id
    WHERE  $where
    ORDER  BY a.date DESC, u.name ASC
");
$records->execute($params);
$records = $records->fetchAll();

$staffList = $db->query(
    "SELECT id, name FROM users WHERE role IN ('staff','admin') ORDER BY name"
)->fetchAll();

// ── Stats ──────────────────────────────────────────────────────────────────
$uniqueDates = array_unique(array_column($records, 'date'));
$presentDays = count($uniqueDates);
$totalHours  = 0.0;
foreach ($records as $r) {
    if (!empty($r['check_in']) && !empty($r['check_out'])) {
        $totalHours += (strtotime($r['check_out']) - strtotime($r['check_in'])) / 3600;
    }
}

// Friendly month label
$monthLabel = $dtMonth->format('F Y');

include __DIR__ . '/../includes/header.php';
?>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">Month</label>
        <input type="month" name="month" class="form-control" value="<?= $month ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">Staff Member</label>
        <select name="user_id" class="form-select">
          <option value="">All Staff</option>
          <?php foreach ($staffList as $s): ?>
          <option value="<?= (int) $s['id'] ?>"
                  <?= $userId === (int) $s['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2 align-items-end">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="<?= BASE_URL ?>/admin/attendance.php" class="btn btn-outline-secondary">Reset</a>
      </div>
      <div class="col-md-3 text-end">
        <button type="button" class="btn btn-outline-secondary"
                onclick="window.print()">
          <i class="bi bi-printer me-1"></i>Print Report
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card stat-pink">
      <i class="bi bi-calendar-check stat-icon"></i>
      <div class="stat-value"><?= count($records) ?></div>
      <div class="stat-label">Total Check-ins</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-blue">
      <i class="bi bi-clock-history stat-icon"></i>
      <div class="stat-value"><?= number_format($totalHours, 1) ?>h</div>
      <div class="stat-label">Total Hours Worked</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card stat-green">
      <i class="bi bi-person-check stat-icon"></i>
      <div class="stat-value"><?= count($staffList) ?></div>
      <div class="stat-label">Total Staff</div>
    </div>
  </div>
</div>

<!-- Print Header (hidden on screen, visible when printing) -->
<div class="d-none" id="printHeader" style="text-align:center;padding:10px">
  <strong style="font-size:1.2rem"><?= APP_NAME ?></strong><br>
  Attendance Report — <?= $monthLabel ?><br>
  Generated: <?= date('M d, Y h:i A') ?>
</div>

<!-- Attendance Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center no-print">
    <span>
      <i class="bi bi-calendar-check me-2"></i>Attendance — <?= $monthLabel ?>
    </span>
    <input type="text" id="attendanceTableSearch" class="form-control form-control-sm no-print"
           style="max-width:200px" placeholder="Filter list…">
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="attendanceTable">
        <thead>
          <tr>
            <th>Staff Name</th>
            <th>Date</th>
            <th>Check In</th>
            <th>Check Out</th>
            <th>Hours</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r):
            $hours = '';
            if (!empty($r['check_in']) && !empty($r['check_out'])) {
                $diff  = (strtotime($r['check_out']) - strtotime($r['check_in'])) / 3600;
                $hours = number_format($diff, 2) . 'h';
            }
          ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($r['staff_name']) ?></td>
            <td><?= date('D, M d Y', strtotime($r['date'])) ?></td>
            <td>
              <span class="badge bg-success">
                <?= !empty($r['check_in']) ? date('h:i A', strtotime($r['check_in'])) : '—' ?>
              </span>
            </td>
            <td>
              <?php if (!empty($r['check_out'])): ?>
                <span class="badge bg-secondary">
                  <?= date('h:i A', strtotime($r['check_out'])) ?>
                </span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Not checked out</span>
              <?php endif; ?>
            </td>
            <td><?= $hours ?: '—' ?></td>
            <td><small class="text-muted"><?= htmlspecialchars($r['notes'] ?? '') ?></small></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($records)): ?>
          <tr>
            <td colspan="6" class="text-center text-muted py-5">
              No attendance records for this period.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
