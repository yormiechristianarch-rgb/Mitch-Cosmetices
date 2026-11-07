<?php
/**
 * MITCH COSMETICS SHOP
 * Shared HTML Header + Sidebar — v2
 * Logic completely unchanged; markup and presentation improved.
 */
if (!defined('APP_NAME')) { require_once __DIR__ . '/functions.php'; }
$user = currentUser();
$role = $user['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> — <?= APP_NAME ?></title>
  <link rel="icon" href="<?= BASE_URL ?>/assets/images/favicon.ico" type="image/x-icon">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">

  <!-- Bootstrap 5 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- Chart.js (available globally for dashboard pages) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<!-- ── Sidebar Overlay ─────────────────────────────────────────────────────── -->
<div id="sidebarOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1049;
            opacity:0;transition:opacity .25s ease;"
     onclick="document.getElementById('sidebar').classList.remove('show');
              this.classList.remove('show');
              setTimeout(()=>{this.style.display='none'},250);
              document.body.style.overflow=''">
</div>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════ -->
<nav id="sidebar" role="navigation" aria-label="Main navigation">

  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="d-flex align-items-center gap-3">
      <div class="sidebar-brand-icon">
        <i class="bi bi-shop brand-icon"></i>
      </div>
      <div>
        <div class="brand-name"><?= APP_NAME ?></div>
        <div class="brand-sub">Management System</div>
      </div>
    </div>
  </div>

  <!-- Navigation links -->
  <div class="sidebar-nav">

    <?php if ($role === 'admin'): ?>

    <div class="sidebar-section-title">Main</div>
    <a href="<?= BASE_URL ?>/admin/dashboard.php"
       class="nav-link <?= ($activeMenu ?? '') === 'dashboard' ? 'active' : '' ?>">
      <i class="bi bi-speedometer2"></i>
      <span>Dashboard</span>
    </a>

    <div class="sidebar-section-title">Inventory</div>
    <a href="<?= BASE_URL ?>/admin/products.php"
       class="nav-link <?= ($activeMenu ?? '') === 'products' ? 'active' : '' ?>">
      <i class="bi bi-box-seam"></i>
      <span>Products</span>
    </a>
    <a href="<?= BASE_URL ?>/admin/inventory.php"
       class="nav-link <?= ($activeMenu ?? '') === 'inventory' ? 'active' : '' ?>">
      <i class="bi bi-journal-text"></i>
      <span>Inventory</span>
    </a>
    <a href="<?= BASE_URL ?>/admin/categories.php"
       class="nav-link <?= ($activeMenu ?? '') === 'categories' ? 'active' : '' ?>">
      <i class="bi bi-tags"></i>
      <span>Categories</span>
    </a>

    <div class="sidebar-section-title">Sales</div>
    <a href="<?= BASE_URL ?>/staff/pos.php"
       class="nav-link <?= ($activeMenu ?? '') === 'pos' ? 'active' : '' ?>">
      <i class="bi bi-cart3"></i>
      <span>Point of Sale</span>
    </a>
    <a href="<?= BASE_URL ?>/admin/orders.php"
       class="nav-link <?= ($activeMenu ?? '') === 'orders' ? 'active' : '' ?>">
      <i class="bi bi-receipt"></i>
      <span>Orders</span>
    </a>
    <a href="<?= BASE_URL ?>/admin/sales.php"
       class="nav-link <?= ($activeMenu ?? '') === 'sales' ? 'active' : '' ?>">
      <i class="bi bi-graph-up-arrow"></i>
      <span>Sales History</span>
    </a>

    <div class="sidebar-section-title">People</div>
    <a href="<?= BASE_URL ?>/admin/customers.php"
       class="nav-link <?= ($activeMenu ?? '') === 'customers' ? 'active' : '' ?>">
      <i class="bi bi-people"></i>
      <span>Customers</span>
    </a>
    <a href="<?= BASE_URL ?>/admin/staff.php"
       class="nav-link <?= ($activeMenu ?? '') === 'staff' ? 'active' : '' ?>">
      <i class="bi bi-person-badge"></i>
      <span>Staff</span>
    </a>

    <div class="sidebar-section-title">Operations</div>
    <a href="<?= BASE_URL ?>/admin/attendance.php"
       class="nav-link <?= ($activeMenu ?? '') === 'attendance' ? 'active' : '' ?>">
      <i class="bi bi-calendar-check"></i>
      <span>Attendance</span>
    </a>
    <a href="<?= BASE_URL ?>/admin/attendance_settings.php"
       class="nav-link <?= ($activeMenu ?? '') === 'attendance_settings' ? 'active' : '' ?>">
      <i class="bi bi-gear"></i>
      <span>Att. Settings</span>
    </a>
    <a href="<?= BASE_URL ?>/admin/payments.php"
       class="nav-link <?= ($activeMenu ?? '') === 'payments' ? 'active' : '' ?>">
      <i class="bi bi-cash-coin"></i>
      <span>Staff Payments</span>
    </a>
    <a href="<?= BASE_URL ?>/reports/index.php"
       class="nav-link <?= ($activeMenu ?? '') === 'reports' ? 'active' : '' ?>">
      <i class="bi bi-file-earmark-bar-graph"></i>
      <span>Reports</span>
    </a>
    <a href="<?= BASE_URL ?>/staff/calculator.php"
       class="nav-link <?= ($activeMenu ?? '') === 'calculator' ? 'active' : '' ?>">
      <i class="bi bi-calculator"></i>
      <span>Calculator</span>
    </a>

    <div class="sidebar-section-title">Public</div>
    <a href="<?= BASE_URL ?>/" target="_blank" rel="noopener" class="nav-link">
      <i class="bi bi-box-arrow-up-right"></i>
      <span>View Storefront</span>
    </a>

    <?php elseif ($role === 'staff'): ?>

    <div class="sidebar-section-title">Staff Panel</div>
    <a href="<?= BASE_URL ?>/staff/dashboard.php"
       class="nav-link <?= ($activeMenu ?? '') === 'dashboard' ? 'active' : '' ?>">
      <i class="bi bi-speedometer2"></i>
      <span>Dashboard</span>
    </a>
    <a href="<?= BASE_URL ?>/staff/pos.php"
       class="nav-link <?= ($activeMenu ?? '') === 'pos' ? 'active' : '' ?>">
      <i class="bi bi-cart3"></i>
      <span>Point of Sale</span>
    </a>
    <a href="<?= BASE_URL ?>/admin/orders.php"
       class="nav-link <?= ($activeMenu ?? '') === 'orders' ? 'active' : '' ?>">
      <i class="bi bi-receipt"></i>
      <span>Orders</span>
    </a>
    <a href="<?= BASE_URL ?>/staff/attendance.php"
       class="nav-link <?= ($activeMenu ?? '') === 'attendance' ? 'active' : '' ?>">
      <i class="bi bi-calendar-check"></i>
      <span>My Attendance</span>
    </a>
    <a href="<?= BASE_URL ?>/staff/my_payments.php"
       class="nav-link <?= ($activeMenu ?? '') === 'my_payments' ? 'active' : '' ?>">
      <i class="bi bi-cash-coin"></i>
      <span>My Payments</span>
    </a>
    <a href="<?= BASE_URL ?>/staff/calculator.php"
       class="nav-link <?= ($activeMenu ?? '') === 'calculator' ? 'active' : '' ?>">
      <i class="bi bi-calculator"></i>
      <span>Calculator</span>
    </a>

    <?php elseif ($role === 'customer'): ?>

    <div class="sidebar-section-title">Customer</div>
    <a href="<?= BASE_URL ?>/customer/dashboard.php"
       class="nav-link <?= ($activeMenu ?? '') === 'dashboard' ? 'active' : '' ?>">
      <i class="bi bi-house"></i>
      <span>Home</span>
    </a>
    <a href="<?= BASE_URL ?>/customer/shop.php"
       class="nav-link <?= ($activeMenu ?? '') === 'shop' ? 'active' : '' ?>">
      <i class="bi bi-bag"></i>
      <span>Shop</span>
    </a>
    <a href="<?= BASE_URL ?>/customer/gallery.php"
       class="nav-link <?= ($activeMenu ?? '') === 'gallery' ? 'active' : '' ?>">
      <i class="bi bi-images"></i>
      <span>Gallery</span>
    </a>
    <a href="<?= BASE_URL ?>/customer/orders.php"
       class="nav-link <?= ($activeMenu ?? '') === 'orders' ? 'active' : '' ?>">
      <i class="bi bi-bag-check"></i>
      <span>My Orders</span>
    </a>
    <a href="<?= BASE_URL ?>/customer/profile.php"
       class="nav-link <?= ($activeMenu ?? '') === 'profile' ? 'active' : '' ?>">
      <i class="bi bi-person-circle"></i>
      <span>Profile</span>
    </a>

    <?php endif; ?>

  </div><!-- /sidebar-nav -->

  <div class="sidebar-footer">
    <small><?= APP_NAME ?><br>© <?= date('Y') ?> All rights reserved</small>
  </div>

</nav><!-- /#sidebar -->

<!-- ══ TOP BAR ══════════════════════════════════════════════════════════════ -->
<header id="topbar" role="banner">

  <button id="sidebarToggle" class="btn-sidebar-toggle" type="button" aria-label="Toggle sidebar">
    <i class="bi bi-list"></i>
  </button>

  <span class="topbar-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></span>

  <div class="topbar-actions">

    <!-- Live clock -->
    <span class="topbar-clock d-none d-md-block" id="liveClock" aria-live="off"></span>

    <!-- Low-stock bell (admin only) ── logic unchanged ── -->
    <?php if ($role === 'admin'):
      $db       = getDB();
      $lowStmt  = $db->prepare("SELECT COUNT(*) FROM products WHERE quantity <= ? AND quantity > 0");
      $lowStmt->execute([(int) APP_LOW_STOCK_THRESHOLD]);
      $lowCount = (int) $lowStmt->fetchColumn();
    ?>
    <a href="<?= BASE_URL ?>/admin/products.php?filter=low_stock"
       class="btn btn-sm btn-outline-warning position-relative"
       title="<?= $lowCount ?> low-stock item<?= $lowCount !== 1 ? 's' : '' ?>"
       data-bs-toggle="tooltip" data-bs-placement="bottom">
      <i class="bi bi-bell"></i>
      <?php if ($lowCount > 0): ?>
      <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
            style="font-size:.55rem;padding:3px 5px">
        <?= $lowCount ?>
      </span>
      <?php endif; ?>
    </a>
    <?php endif; ?>

    <!-- User dropdown -->
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2"
              type="button"
              data-bs-toggle="dropdown"
              aria-expanded="false"
              aria-label="User menu">
        <!-- Avatar initial -->
        <span class="d-inline-flex align-items-center justify-content-center rounded-circle"
              style="width:26px;height:26px;background:var(--primary-xlight);color:var(--primary);
                     font-size:.75rem;font-weight:700;flex-shrink:0">
          <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
        </span>
        <span class="d-none d-sm-inline"
              style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= htmlspecialchars($user['name'] ?? 'User') ?>
        </span>
      </button>

      <ul class="dropdown-menu dropdown-menu-end">
        <!-- User info block -->
        <li>
          <div class="px-3 py-2" style="border-bottom:1px solid var(--border-light);margin-bottom:4px">
            <div class="fw-bold" style="font-size:.85rem;color:var(--text)">
              <?= htmlspecialchars($user['name'] ?? '') ?>
            </div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:1px">
              <span class="badge"
                    style="background:var(--primary-xlight);color:var(--primary);font-size:.65rem;
                           padding:2px 8px;border-radius:99px">
                <?= ucfirst($role) ?>
              </span>
            </div>
          </div>
        </li>

        <!-- Profile link — role-aware ── logic unchanged ── -->
        <?php if ($role === 'customer'): ?>
        <li>
          <a class="dropdown-item" href="<?= BASE_URL ?>/customer/profile.php">
            <i class="bi bi-person me-2"></i>My Profile
          </a>
        </li>
        <?php elseif ($role === 'staff'): ?>
        <li>
          <a class="dropdown-item" href="<?= BASE_URL ?>/staff/profile.php">
            <i class="bi bi-person me-2"></i>My Profile
          </a>
        </li>
        <?php else: ?>
        <li>
          <a class="dropdown-item" href="<?= BASE_URL ?>/admin/profile.php">
            <i class="bi bi-person me-2"></i>My Profile
          </a>
        </li>
        <?php endif; ?>

        <li><hr class="dropdown-divider"></li>

        <li>
          <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php">
            <i class="bi bi-box-arrow-right me-2"></i>Sign Out
          </a>
        </li>
      </ul>
    </div><!-- /dropdown -->

  </div><!-- /topbar-actions -->
</header><!-- /#topbar -->

<!-- ══ MAIN CONTENT ══════════════════════════════════════════════════════════ -->
<main id="main-content" role="main">
<?= flashAlert() ?>
