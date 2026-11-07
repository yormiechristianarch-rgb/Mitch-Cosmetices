<?php
/**
 * MITCH COSMETICS SHOP — Admin Dashboard
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';

$stats      = getDashboardStats();
$monthly    = getMonthlySalesData();
$topProducts = getTopProducts(5);

$db = getDB();

// Recent orders — prepared statement, no user input
$recentStmt = $db->prepare("
    SELECT o.*, u.name AS customer_name
    FROM orders o
    LEFT JOIN users u ON u.id = o.customer_id
    ORDER BY o.created_at DESC LIMIT 8
");
$recentStmt->execute();
$recentOrders = $recentStmt->fetchAll();

// Total units in stock (distinct from product count)
$totalUnitsInStock = (int)$db->query(
    "SELECT COALESCE(SUM(quantity),0) FROM products"
)->fetchColumn();

// Low stock products — use a prepared statement, never interpolate constants into SQL
$lsStmt = $db->prepare("
    SELECT p.*, c.name AS category
    FROM   products    p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE  p.quantity <= ?
    ORDER  BY p.quantity ASC
    LIMIT  8
");
$lsStmt->execute([(int) APP_LOW_STOCK_THRESHOLD]);
$lowStock = $lsStmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Stat Cards Row 1 -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-pink">
      <i class="bi bi-box-seam stat-icon"></i>
      <div class="stat-value"><?= number_format($stats['total_products']) ?></div>
      <div class="stat-label">Product Types</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-green">
      <i class="bi bi-stack stat-icon"></i>
      <div class="stat-value"><?= number_format($totalUnitsInStock) ?></div>
      <div class="stat-label">Total Units in Stock</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-purple">
      <i class="bi bi-people stat-icon"></i>
      <div class="stat-value"><?= number_format($stats['total_customers']) ?></div>
      <div class="stat-label">Total Customers</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-blue">
      <i class="bi bi-receipt stat-icon"></i>
      <div class="stat-value"><?= number_format($stats['total_orders']) ?></div>
      <div class="stat-label">Total Orders</div>
    </div>
  </div>
</div>

<!-- Stat Cards Row 2 -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-teal">
      <i class="bi bi-currency-dollar stat-icon"></i>
      <div class="stat-value"><?= money($stats['total_sales']) ?></div>
      <div class="stat-label">Total Sales</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-indigo">
      <i class="bi bi-calendar-day stat-icon"></i>
      <div class="stat-value"><?= money($stats['daily_sales']) ?></div>
      <div class="stat-label">Today's Sales</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-orange">
      <i class="bi bi-calendar-month stat-icon"></i>
      <div class="stat-value"><?= money($stats['monthly_sales']) ?></div>
      <div class="stat-label">Monthly Sales</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-red">
      <i class="bi bi-clock-history stat-icon"></i>
      <div class="stat-value"><?= number_format($stats['pending_orders']) ?></div>
      <div class="stat-label">Pending Orders</div>
    </div>
  </div>
</div>

<!-- Stat Cards Row 3 — Stock status -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-pink">
      <i class="bi bi-exclamation-triangle stat-icon"></i>
      <div class="stat-value"><?= number_format($stats['low_stock']) ?></div>
      <div class="stat-label">Low Stock Items</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-purple">
      <i class="bi bi-x-circle stat-icon"></i>
      <div class="stat-value"><?= number_format($stats['out_of_stock']) ?></div>
      <div class="stat-label">Out of Stock</div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-graph-up-arrow me-2 text-pink"></i>Monthly Sales — <?= date('Y') ?></span>
        <small class="text-muted">Jan – Dec</small>
      </div>
      <div class="card-body">
        <canvas id="salesChart" height="110"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Top Products</div>
      <div class="card-body">
        <canvas id="topProductsChart"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Recent Orders + Low Stock -->
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-2"></i>Recent Orders</span>
        <a href="<?= BASE_URL ?>/admin/orders.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>Order #</th><th>Customer</th><th>Total</th><th>Method</th><th>Status</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentOrders as $o): ?>
              <tr>
                <td><a href="<?= BASE_URL ?>/admin/orders.php?view=<?= $o['id'] ?>" class="fw-semibold"><?= htmlspecialchars($o['order_no']) ?></a></td>
                <td><?= htmlspecialchars($o['customer_name'] ?? 'Walk-in') ?></td>
                <td class="fw-bold text-success"><?= money($o['total_amount']) ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $o['payment_method']))) ?></span></td>
                <td><?= statusBadge($o['status']) ?></td>
                <td><small class="text-muted"><?= date('M d, Y', strtotime($o['created_at'])) ?></small></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($recentOrders)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No orders yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle text-warning me-2"></i>Low Stock Alerts</span>
        <a href="<?= BASE_URL ?>/admin/products.php?filter=low_stock" class="btn btn-sm btn-outline-warning">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Product</th><th>Category</th><th>Qty</th></tr></thead>
            <tbody>
              <?php foreach ($lowStock as $p): ?>
              <tr>
                <td>
                  <div class="fw-semibold" style="font-size:.85rem"><?= htmlspecialchars($p['name']) ?></div>
                  <small class="text-muted"><?= htmlspecialchars($p['sku']) ?></small>
                </td>
                <td><small><?= htmlspecialchars($p['category'] ?? '—') ?></small></td>
                <td>
                  <?php if ($p['quantity'] == 0): ?>
                    <span class="badge bg-danger">Out of Stock</span>
                  <?php else: ?>
                    <span class="badge bg-warning text-dark"><?= $p['quantity'] ?> left</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($lowStock)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success"></i> All products well-stocked</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
// Monthly Sales Chart
new Chart(document.getElementById("salesChart"), {
  type: "bar",
  data: {
    labels: ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
    datasets: [{
      label: "Sales ($)",
      data: ' . json_encode($monthly) . ',
      backgroundColor: "rgba(192,0,122,0.7)",
      borderColor: "rgba(192,0,122,1)",
      borderWidth: 2,
      borderRadius: 6
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { callback: v => "$"+v } }
    }
  }
});

// Top Products Chart
const topLabels = ' . json_encode(array_column($topProducts, 'name')) . ';
const topData   = ' . json_encode(array_column($topProducts, 'sold')) . ';
new Chart(document.getElementById("topProductsChart"), {
  type: "doughnut",
  data: {
    labels: topLabels,
    datasets: [{
      data: topData,
      backgroundColor: ["#c0007a","#ff6fb5","#7b2d8b","#0044cc","#00875a"],
      borderWidth: 2
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { position: "bottom", labels: { font: { size: 11 } } } }
  }
});
</script>';
include __DIR__ . '/../includes/footer.php';
?>
