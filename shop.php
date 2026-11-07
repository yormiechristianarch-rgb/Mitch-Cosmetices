<?php
/**
 * MITCH COSMETICS SHOP — Customer Shop
 * Order flow uses only core tables: orders + order_items + products
 * No dependency on stock_movements, stock_deducted, or any patch columns.
 *
 * Schema resilience:
 *  - orders.notes — optional, detected at runtime
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('customer');

// Detect optional columns (cached static so only one DB hit per request)
function shopHasColumn(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $db = getDB();
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?"
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

$pageTitle  = 'Shop';
$activeMenu = 'shop';
$db = getDB();

// ── Place Order ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    verifyCsrf();

    $productId = (int)($_POST['product_id'] ?? 0);
    $qty       = max(1, (int)($_POST['quantity'] ?? 1));
    $notes     = sanitize($_POST['notes'] ?? '');

    if ($productId < 1) {
        setFlash('error', 'Invalid product.');
        redirect(BASE_URL . '/customer/shop.php');
    }

    // Re-fetch product from DB — never trust form values
    $prodStmt = $db->prepare(
        "SELECT id, name, price, quantity FROM products WHERE id = ? AND status = 'active' LIMIT 1"
    );
    $prodStmt->execute([$productId]);
    $prod = $prodStmt->fetch();

    if (!$prod) {
        setFlash('error', 'Product is unavailable.');
        redirect(BASE_URL . '/customer/shop.php');
    }
    if ((int)$prod['quantity'] <= 0) {
        setFlash('error', htmlspecialchars($prod['name']) . ' is out of stock.');
        redirect(BASE_URL . '/customer/shop.php');
    }
    if ($qty > (int)$prod['quantity']) {
        setFlash('error',
            'Only ' . $prod['quantity'] . ' unit(s) of ' .
            htmlspecialchars($prod['name']) . ' available.');
        redirect(BASE_URL . '/customer/shop.php');
    }

    $subtotal = round((float)$prod['price'] * $qty, 2);
    $orderNo  = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $userId   = (int)$_SESSION['user_id'];

    // Detect optional columns on orders table (cached per request)
    $ordersHasNotes = shopHasColumn('orders', 'notes');

    try {
        $db->beginTransaction();

        // 1. Insert order — use notes column only if it exists
        if ($ordersHasNotes) {
            $db->prepare("
                INSERT INTO orders
                    (order_no, customer_id, subtotal, total_amount,
                     payment_method, status, notes)
                VALUES (?, ?, ?, ?, 'cash', 'pending', ?)
            ")->execute([$orderNo, $userId, $subtotal, $subtotal, $notes]);
        } else {
            $db->prepare("
                INSERT INTO orders
                    (order_no, customer_id, subtotal, total_amount,
                     payment_method, status)
                VALUES (?, ?, ?, ?, 'cash', 'pending')
            ")->execute([$orderNo, $userId, $subtotal, $subtotal]);
        }
        $orderId = (int)$db->lastInsertId();
        if (shopHasColumn('orders', 'stock_deducted')) {
          $db->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = ?")
             ->execute([$orderId]);
        }

        // 2. Insert order item
        $db->prepare("
            INSERT INTO order_items
                (order_id, product_id, quantity, unit_price, discount, total)
            VALUES (?, ?, ?, ?, 0, ?)
        ")->execute([$orderId, $productId, $qty, $prod['price'], $subtotal]);

        // 3. Deduct stock — atomic, prevents going negative
        $updated = $db->prepare("
            UPDATE products
            SET quantity = quantity - ?
            WHERE id = ? AND quantity >= ?
        ");
        $updated->execute([$qty, $productId, $qty]);

        if ($updated->rowCount() === 0) {
            $db->rollBack();
            setFlash('error', 'Could not complete order — stock may have changed. Please try again.');
            redirect(BASE_URL . '/customer/shop.php');
        }

        $db->commit();

        // 4. Log stock movement (best-effort — does not affect order success)
        try {
            recordStockMovement(
                $productId, -$qty, 'order_placed',
                $userId, $_SESSION['user_name'] ?? 'Customer',
                $orderId, 'order', "Customer order {$orderNo}"
            );
        } catch (Exception $ignored) {}

        setFlash('success', "Order {$orderNo} placed successfully! We'll process it shortly.");
        redirect(BASE_URL . '/customer/orders.php');

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('MITCH CUSTOMER ORDER ERROR: ' . $e->getMessage());
        setFlash('error', 'Order could not be placed. Please try again.');
        redirect(BASE_URL . '/customer/shop.php');
    }
}

// ── Load Products ──────────────────────────────────────────────────────────
$search    = sanitize($_GET['q'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$sort      = sanitize($_GET['sort'] ?? 'newest');

$where  = "p.status = 'active'";
$params = [];
if ($search) {
    $where   .= " AND (p.name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($catFilter) {
    $where   .= " AND p.category_id = ?";
    $params[] = $catFilter;
}

$orderBy = 'p.created_at DESC';
if ($sort === 'price_asc')  $orderBy = 'p.price ASC';
if ($sort === 'price_desc') $orderBy = 'p.price DESC';
if ($sort === 'name')       $orderBy = 'p.name ASC';

$stmt = $db->prepare("
    SELECT p.*, c.name AS category
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE $where
    ORDER BY $orderBy
");
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// ── Auto-open order modal when arriving from gallery ─────────────────────
$autoOrderId = (int)($_GET['order'] ?? 0);
$autoProduct = null;
if ($autoOrderId > 0) {
    $aStmt = $db->prepare("SELECT * FROM products WHERE id = ? AND status = 'active' AND quantity > 0 LIMIT 1");
    $aStmt->execute([$autoOrderId]);
    $autoProduct = $aStmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Search & Filter --><div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control" placeholder="Search products..."
               value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-3">
        <select name="cat" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $catFilter == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="sort" class="form-select">
          <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest</option>
          <option value="price_asc"  <?= $sort === 'price_asc'  ? 'selected' : '' ?>>Price: Low → High</option>
          <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High → Low</option>
          <option value="name"       <?= $sort === 'name'       ? 'selected' : '' ?>>Name A–Z</option>
        </select>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-primary me-2">
          <i class="bi bi-search me-1"></i>Search
        </button>
        <a href="<?= BASE_URL ?>/customer/shop.php" class="btn btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Category Pills -->
<div class="d-flex flex-wrap gap-2 mb-4">
  <a href="<?= BASE_URL ?>/customer/shop.php"
     class="btn btn-sm <?= !$catFilter ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
  <?php foreach ($categories as $c): ?>
  <a href="?cat=<?= $c['id'] ?>"
     class="btn btn-sm <?= $catFilter == $c['id'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
    <?= htmlspecialchars($c['name']) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Products Grid -->
<div class="row g-3">
  <?php foreach ($products as $p): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card h-100">
      <?php $productImageUrl = productImageUrl($p['image'] ?? null); ?>
      <?php if ($productImageUrl): ?>
        <img src="<?= htmlspecialchars($productImageUrl) ?>"
             class="card-img-top" style="height:180px;object-fit:cover" alt="<?= htmlspecialchars($p['name']) ?>"
             onerror="this.style.display='none'">
      <?php else: ?>
        <div style="height:180px;background:var(--accent);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-image" style="font-size:2.5rem;color:var(--primary)"></i>
        </div>
      <?php endif; ?>
      <div class="card-body d-flex flex-column">
        <small class="text-muted"><?= htmlspecialchars($p['category'] ?? '') ?></small>
        <h6 class="card-title mt-1 mb-1"><?= htmlspecialchars($p['name']) ?></h6>
        <p class="card-text text-muted flex-grow-1" style="font-size:.82rem">
          <?= htmlspecialchars(mb_strimwidth($p['description'] ?? '', 0, 80, '...')) ?>
        </p>
        <div class="d-flex justify-content-between align-items-center mt-2">
          <span class="fw-bold text-success" style="font-size:1.05rem"><?= money($p['price']) ?></span>
          <?php if ((int)$p['quantity'] > 0): ?>
            <small class="text-muted">In Stock</small>
          <?php else: ?>
            <small class="text-danger fw-bold">Out of Stock</small>
          <?php endif; ?>
        </div>
        <?php if ((int)$p['quantity'] > 0): ?>
        <button class="btn btn-primary btn-sm mt-2 w-100"
                data-bs-toggle="modal" data-bs-target="#orderModal"
          data-product="<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>"
          onclick="setOrderProduct(this.dataset.product)">
          <i class="bi bi-cart-plus me-1"></i>Order Now
        </button>
        <?php else: ?>
        <button class="btn btn-secondary btn-sm mt-2 w-100" disabled>Out of Stock</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($products)): ?>
  <div class="col-12 text-center py-5 text-muted">
    <i class="bi bi-bag-x" style="font-size:3rem"></i>
    <p class="mt-2">No products found.</p>
  </div>
  <?php endif; ?>
</div>

<!-- Order Modal -->
<div class="modal fade" id="orderModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cart-plus me-2"></i>Place Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action"     value="place_order">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="product_id" id="orderProductId">
        <div class="modal-body">
          <div id="orderProductInfo" class="d-flex justify-content-between align-items-center
               mb-3 p-3 rounded" style="background:var(--accent)"></div>
          <div class="mb-3">
            <label class="form-label fw-bold">Quantity</label>
            <input type="number" name="quantity" id="orderQty"
                   class="form-control" min="1" value="1">
            <div class="form-text">Available: <span id="orderStock" class="fw-bold text-success"></span></div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Total</label>
            <div class="fw-bold text-success" style="font-size:1.3rem" id="orderTotal">$0.00</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes (optional)</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Any special request..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-bold px-4">
            <i class="bi bi-check-circle me-1"></i>Confirm Order
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
var _orderPrice = 0;
function setOrderProduct(p) {
    if (typeof p === 'string') {
        try { p = JSON.parse(p); } catch (e) { return; }
    }
    _orderPrice = parseFloat(p.price) || 0;
    document.getElementById('orderProductId').value = p.id;
    document.getElementById('orderStock').textContent = p.quantity;
    document.getElementById('orderQty').max   = p.quantity;
    document.getElementById('orderQty').value = 1;
    document.getElementById('orderTotal').textContent = '$' + _orderPrice.toFixed(2);
    document.getElementById('orderProductInfo').innerHTML =
        '<span class="fw-bold">' + p.name + '</span>' +
        '<span class="fw-bold text-success">$' + _orderPrice.toFixed(2) + '</span>';
}
document.addEventListener('DOMContentLoaded', function () {
    var qtyEl = document.getElementById('orderQty');
    if (qtyEl) {
        qtyEl.addEventListener('input', function () {
            var n = parseInt(this.value) || 1;
            document.getElementById('orderTotal').textContent =
                '$' + (_orderPrice * n).toFixed(2);
        });
    }

    <?php if ($autoProduct): ?>
    // Auto-open order modal for product coming from gallery
    var autoP = <?= json_encode($autoProduct, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    setOrderProduct(autoP);
    var modalEl = document.getElementById('orderModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        new bootstrap.Modal(modalEl).show();
    }
    <?php endif; ?>
});</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
