<?php
/**
 * MITCH COSMETICS SHOP — Attendance Settings (Admin only)
 * Configures per-day check-in/check-out schedule.
 * Existing attendance records and workflows are NOT touched.
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Attendance Settings';
$activeMenu = 'attendance_settings';
$db = getDB();

// ── Ensure table exists (auto-create if admin visits before SQL patch run) ─
$db->exec("CREATE TABLE IF NOT EXISTS `attendance_settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `day_of_week`   TINYINT      NOT NULL,
    `is_enabled`    TINYINT(1)   NOT NULL DEFAULT 1,
    `checkin_start` TIME         NOT NULL DEFAULT '08:00:00',
    `checkin_end`   TIME         NOT NULL DEFAULT '10:00:00',
    `late_after`    TIME         DEFAULT NULL,
    `checkout_time` TIME         DEFAULT NULL,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_day` (`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Seed missing rows silently
$db->exec("INSERT IGNORE INTO `attendance_settings`
    (`day_of_week`,`is_enabled`,`checkin_start`,`checkin_end`,`late_after`,`checkout_time`)
    VALUES
    (0,0,'08:00:00','10:00:00',NULL,NULL),
    (1,1,'08:00:00','10:00:00','08:30:00','17:00:00'),
    (2,1,'08:00:00','10:00:00','08:30:00','17:00:00'),
    (3,1,'08:00:00','10:00:00','08:30:00','17:00:00'),
    (4,1,'08:00:00','10:00:00','08:30:00','17:00:00'),
    (5,1,'08:00:00','10:00:00','08:30:00','17:00:00'),
    (6,1,'08:00:00','09:00:00',NULL,'13:00:00')");

// ── Handle Save ────────────────────────────────────────────────────────────
$saveErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $days = $_POST['days'] ?? [];   // indexed by day_of_week (0-6)

    foreach ($days as $dow => $cfg) {
        $dow        = (int) $dow;
        if ($dow < 0 || $dow > 6) continue;

        $enabled    = isset($cfg['is_enabled']) ? 1 : 0;
        $start      = $cfg['checkin_start'] ?? '08:00';
        $end        = $cfg['checkin_end']   ?? '10:00';
        $late       = !empty($cfg['late_after'])    ? $cfg['late_after']    : null;
        $checkout   = !empty($cfg['checkout_time']) ? $cfg['checkout_time'] : null;

        // Pad to H:i:s for TIME column
        $start    = (strlen($start) === 5)   ? $start . ':00'   : $start;
        $end      = (strlen($end) === 5)     ? $end   . ':00'   : $end;
        if ($late)     $late     = (strlen($late) === 5)     ? $late . ':00'     : $late;
        if ($checkout) $checkout = (strlen($checkout) === 5) ? $checkout . ':00' : $checkout;

        // Validate start < end
        if ($start >= $end) {
            $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            $saveErrors[] = $dayNames[$dow] . ': Check-in Start must be earlier than Check-in End.';
            continue;
        }

        $db->prepare("UPDATE attendance_settings SET
            is_enabled    = ?,
            checkin_start = ?,
            checkin_end   = ?,
            late_after    = ?,
            checkout_time = ?
            WHERE day_of_week = ?")
           ->execute([$enabled, $start, $end, $late, $checkout, $dow]);
    }

    if (empty($saveErrors)) {
        setFlash('success', 'Attendance schedule saved successfully.');
        redirect(BASE_URL . '/admin/attendance_settings.php');
    }
}

// ── Load current settings ──────────────────────────────────────────────────
$rows = $db->query("SELECT * FROM attendance_settings ORDER BY day_of_week")->fetchAll();
// Index by day_of_week for easy access in template
$schedule = [];
foreach ($rows as $r) { $schedule[(int)$r['day_of_week']] = $r; }

$dayNames = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
];
$dayIcons = [
    0 => 'bi-sun',
    1 => 'bi-calendar2-week',
    2 => 'bi-calendar2-week',
    3 => 'bi-calendar2-week',
    4 => 'bi-calendar2-week',
    5 => 'bi-calendar2-week',
    6 => 'bi-calendar2',
];

// Today's day for visual highlight
$todayDow = (int) date('w');   // 0=Sun … 6=Sat

include __DIR__ . '/../includes/header.php';
?>

<!-- Page intro -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <p class="text-muted mb-0" style="font-size:.9rem">
      Configure which days attendance is open and when staff may check in or out.
      Changes take effect immediately.
    </p>
  </div>
  <a href="<?= BASE_URL ?>/admin/attendance.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back to Attendance
  </a>
</div>

<?php if (!empty($saveErrors)): ?>
<div class="alert alert-danger">
  <strong><i class="bi bi-exclamation-triangle me-2"></i>Please fix the following:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($saveErrors as $e): ?>
    <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="POST" id="settingsForm">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <!-- Current day banner -->
  <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-4">
    <i class="bi bi-clock-history fs-5"></i>
    <span>
      Today is <strong><?= $dayNames[$todayDow] ?></strong>
      (<?= date('l, F d Y') ?>) — Timezone: <strong><?= date_default_timezone_get() ?></strong>
    </span>
  </div>

  <div class="row g-4">
    <?php for ($dow = 1; $dow <= 6; $dow++): // Mon–Sat first
      $s       = $schedule[$dow] ?? [];
      $enabled = !empty($s['is_enabled']);
      $isToday = ($dow === $todayDow);
    ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100 <?= $isToday ? 'border-primary' : '' ?>"
           style="<?= $isToday ? 'border-width:2px' : '' ?>">
        <div class="card-header d-flex justify-content-between align-items-center"
             style="<?= $enabled ? '' : 'background:#f8f9fa;color:#6c757d' ?>">
          <span class="fw-bold">
            <i class="bi <?= $dayIcons[$dow] ?> me-2"></i>
            <?= $dayNames[$dow] ?>
            <?php if ($isToday): ?>
              <span class="badge bg-primary ms-1" style="font-size:.65rem">TODAY</span>
            <?php endif; ?>
          </span>
          <!-- Enable / Disable toggle -->
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox"
                   name="days[<?= $dow ?>][is_enabled]"
                   id="enabled_<?= $dow ?>"
                   value="1"
                   <?= $enabled ? 'checked' : '' ?>
                   onchange="toggleDay(<?= $dow ?>, this.checked)">
            <label class="form-check-label small fw-semibold"
                   for="enabled_<?= $dow ?>">
              <?= $enabled ? 'Open' : 'Closed' ?>
            </label>
          </div>
        </div>

        <div class="card-body" id="dayBody_<?= $dow ?>"
             <?= !$enabled ? 'style="opacity:.45;pointer-events:none"' : '' ?>>
          <div class="row g-2">

            <!-- Check-in Start -->
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:.78rem;font-weight:600">
                <i class="bi bi-box-arrow-in-right text-success me-1"></i>
                Check-in Start <span class="text-danger">*</span>
              </label>
              <input type="time" class="form-control form-control-sm"
                     name="days[<?= $dow ?>][checkin_start]"
                     value="<?= htmlspecialchars(substr($s['checkin_start'] ?? '08:00:00', 0, 5)) ?>"
                     required>
            </div>

            <!-- Check-in End -->
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:.78rem;font-weight:600">
                <i class="bi bi-x-circle text-danger me-1"></i>
                Check-in End <span class="text-danger">*</span>
              </label>
              <input type="time" class="form-control form-control-sm"
                     name="days[<?= $dow ?>][checkin_end]"
                     value="<?= htmlspecialchars(substr($s['checkin_end'] ?? '10:00:00', 0, 5)) ?>"
                     required>
            </div>

            <!-- Late After -->
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:.78rem;font-weight:600">
                <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                Late After
                <span class="text-muted fw-normal">(optional)</span>
              </label>
              <input type="time" class="form-control form-control-sm"
                     name="days[<?= $dow ?>][late_after]"
                     value="<?= htmlspecialchars(substr($s['late_after'] ?? '', 0, 5)) ?>">
            </div>

            <!-- Check-out Time -->
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:.78rem;font-weight:600">
                <i class="bi bi-box-arrow-right text-secondary me-1"></i>
                Checkout From
                <span class="text-muted fw-normal">(optional)</span>
              </label>
              <input type="time" class="form-control form-control-sm"
                     name="days[<?= $dow ?>][checkout_time]"
                     value="<?= htmlspecialchars(substr($s['checkout_time'] ?? '', 0, 5)) ?>">
            </div>

          </div><!-- /row -->

          <!-- Summary pill -->
          <div class="mt-2 pt-2 border-top">
            <small class="text-muted">
              <?php if (!empty($s['checkin_start']) && !empty($s['checkin_end'])): ?>
                Window:
                <strong><?= date('h:i A', strtotime($s['checkin_start'])) ?></strong>
                –
                <strong><?= date('h:i A', strtotime($s['checkin_end'])) ?></strong>
                <?php if (!empty($s['late_after'])): ?>
                  · Late after <strong><?= date('h:i A', strtotime($s['late_after'])) ?></strong>
                <?php endif; ?>
              <?php endif; ?>
            </small>
          </div>
        </div><!-- /card-body -->
      </div>
    </div>
    <?php endfor; ?>

    <!-- Sunday (day 0) — separate, usually closed -->
    <?php
      $dow     = 0;
      $s       = $schedule[0] ?? [];
      $enabled = !empty($s['is_enabled']);
      $isToday = ($todayDow === 0);
    ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100 <?= $isToday ? 'border-primary' : '' ?>"
           style="<?= $isToday ? 'border-width:2px' : '' ?>">
        <div class="card-header d-flex justify-content-between align-items-center"
             style="<?= $enabled ? '' : 'background:#f8f9fa;color:#6c757d' ?>">
          <span class="fw-bold">
            <i class="bi bi-sun me-2"></i>Sunday
            <?php if ($isToday): ?>
              <span class="badge bg-primary ms-1" style="font-size:.65rem">TODAY</span>
            <?php endif; ?>
          </span>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox"
                   name="days[0][is_enabled]" id="enabled_0" value="1"
                   <?= $enabled ? 'checked' : '' ?>
                   onchange="toggleDay(0, this.checked)">
            <label class="form-check-label small fw-semibold" for="enabled_0">
              <?= $enabled ? 'Open' : 'Closed' ?>
            </label>
          </div>
        </div>
        <div class="card-body" id="dayBody_0"
             <?= !$enabled ? 'style="opacity:.45;pointer-events:none"' : '' ?>>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:.78rem;font-weight:600">
                <i class="bi bi-box-arrow-in-right text-success me-1"></i>Check-in Start <span class="text-danger">*</span>
              </label>
              <input type="time" class="form-control form-control-sm"
                     name="days[0][checkin_start]"
                     value="<?= htmlspecialchars(substr($s['checkin_start'] ?? '08:00:00', 0, 5)) ?>">
            </div>
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:.78rem;font-weight:600">
                <i class="bi bi-x-circle text-danger me-1"></i>Check-in End <span class="text-danger">*</span>
              </label>
              <input type="time" class="form-control form-control-sm"
                     name="days[0][checkin_end]"
                     value="<?= htmlspecialchars(substr($s['checkin_end'] ?? '10:00:00', 0, 5)) ?>">
            </div>
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:.78rem;font-weight:600">
                <i class="bi bi-exclamation-triangle text-warning me-1"></i>Late After
                <span class="text-muted fw-normal">(optional)</span>
              </label>
              <input type="time" class="form-control form-control-sm"
                     name="days[0][late_after]"
                     value="<?= htmlspecialchars(substr($s['late_after'] ?? '', 0, 5)) ?>">
            </div>
            <div class="col-6">
              <label class="form-label mb-1" style="font-size:.78rem;font-weight:600">
                <i class="bi bi-box-arrow-right text-secondary me-1"></i>Checkout From
                <span class="text-muted fw-normal">(optional)</span>
              </label>
              <input type="time" class="form-control form-control-sm"
                     name="days[0][checkout_time]"
                     value="<?= htmlspecialchars(substr($s['checkout_time'] ?? '', 0, 5)) ?>">
            </div>
          </div>
          <div class="mt-2 pt-2 border-top">
            <small class="text-muted">
              <?php if (!empty($s['checkin_start']) && !empty($s['checkin_end'])): ?>
                Window:
                <strong><?= date('h:i A', strtotime($s['checkin_start'])) ?></strong>
                – <strong><?= date('h:i A', strtotime($s['checkin_end'])) ?></strong>
              <?php endif; ?>
            </small>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /row -->

  <!-- Legend + Save -->
  <div class="card mt-4">
    <div class="card-body py-3">
      <div class="row align-items-center">
        <div class="col-md-8">
          <div class="d-flex flex-wrap gap-3" style="font-size:.82rem">
            <span><i class="bi bi-box-arrow-in-right text-success me-1"></i>
              <strong>Check-in Start</strong> — earliest time staff can check in</span>
            <span><i class="bi bi-x-circle text-danger me-1"></i>
              <strong>Check-in End</strong> — latest time allowed (door closes)</span>
            <span><i class="bi bi-exclamation-triangle text-warning me-1"></i>
              <strong>Late After</strong> — check-in after this time is marked late</span>
            <span><i class="bi bi-box-arrow-right text-secondary me-1"></i>
              <strong>Checkout From</strong> — earliest allowed check-out</span>
          </div>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
          <button type="submit" class="btn btn-primary fw-bold px-4">
            <i class="bi bi-save me-2"></i>Save Schedule
          </button>
        </div>
      </div>
    </div>
  </div>

</form>

<script>
function toggleDay(dow, enabled) {
    const body  = document.getElementById('dayBody_' + dow);
    const label = document.querySelector('#enabled_' + dow + ' ~ label') ||
                  document.querySelector('label[for="enabled_' + dow + '"]');
    body.style.opacity        = enabled ? '1'    : '.45';
    body.style.pointerEvents  = enabled ? ''     : 'none';
    if (label) label.textContent = enabled ? 'Open' : 'Closed';
}

// Client-side start < end validation before submit
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    const days = [0,1,2,3,4,5,6];
    const names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    let errors = [];
    days.forEach(function(d) {
        const s = document.querySelector('[name="days[' + d + '][checkin_start]"]');
        const n = document.querySelector('[name="days[' + d + '][checkin_end]"]');
        if (s && n && s.value && n.value && s.value >= n.value) {
            errors.push(names[d] + ': Check-in Start must be before Check-in End.');
        }
    });
    if (errors.length) {
        e.preventDefault();
        alert('Please fix:\n\n' + errors.join('\n'));
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
