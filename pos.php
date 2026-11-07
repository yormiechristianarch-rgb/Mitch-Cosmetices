<?php
/**
 * MITCH COSMETICS SHOP — Point of Sale (POS)
 * Uses only core tables: sales + sale_items + orders + order_items + products
 * No dependency on stock_movements, stock_deducted, or any patch columns.
 *
 * Schema resilience:
 *  - orders.staff_id  — optional, detected at runtime
 *  - orders.discount  — optional, detected at runtime
 *  - sales.order_id   — optional, detected at runtime
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');

// ── Detect optional columns once per request ──────────────────────────────
function posHasColumn(string $table, string $column): bool {
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

$pageTitle  = 'Point of Sale';
$activeMenu = 'pos';
$db = getDB();

// ── Process Sale ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_sale') {
    verifyCsrf();

    $cartJson      = $_POST['cart_data'] ?? null;
    $customerInput = $_POST['customer_name'] ?? 'Walk-in Customer';
    $paymentInput  = $_POST['payment_method'] ?? '';
    $discountInput = $_POST['discount'] ?? '0';
    $tenderedInput = $_POST['amount_tendered'] ?? '';

    if (!is_string($cartJson) || !is_string($customerInput)
        || !is_string($paymentInput) || !is_string($discountInput)
        || !is_string($tenderedInput)) {
        setFlash('error', 'Invalid checkout data. Please review the sale and try again.');
        redirect(BASE_URL . '/staff/pos.php');
    }

    $cartData     = json_decode($cartJson, true);
    $customerName = sanitize($customerInput);
    $payMethod    = validatePaymentMethod($paymentInput);
    $discountInput = trim($discountInput);
    $tenderedInput = trim($tenderedInput);

    if (empty($cartData) || !is_array($cartData)) {
        setFlash('error', 'Cart is empty. Please add products.');
        redirect(BASE_URL . '/staff/pos.php');
    }

    // Server-side re-validate every item (fetch fresh from DB)
    $subtotal = 0.0;
    $verified = [];
    foreach ($cartData as $item) {
        if (!is_array($item) || !isset($item['id'], $item['qty'])
            || (!is_int($item['id']) && !is_string($item['id']))
            || (!is_int($item['qty']) && !is_string($item['qty']))) {
            setFlash('error', 'Invalid cart item. Please refresh the POS and try again.');
            redirect(BASE_URL . '/staff/pos.php');
        }

        $pid = filter_var($item['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qty = filter_var($item['qty'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($pid === false || $qty === false) {
            setFlash('error', 'Invalid product or quantity in cart.');
            redirect(BASE_URL . '/staff/pos.php');
        }

        $pStmt = $db->prepare(
            "SELECT id, name, sku, price, quantity FROM products WHERE id = ? AND status = 'active'"
        );
        $pStmt->execute([$pid]);
        $prod = $pStmt->fetch();

        if (!$prod || (int)$prod['quantity'] < $qty) {
            setFlash('error', '"' . htmlspecialchars($prod['name'] ?? "ID $pid") . '" is out of stock or unavailable.');
            redirect(BASE_URL . '/staff/pos.php');
        }
        $subtotal  += (float)$prod['price'] * $qty;
        $verified[] = [
            'id'    => (int)$prod['id'],
            'name'  => $prod['name'],
            'sku'   => $prod['sku'],
            'price' => (float)$prod['price'],
            'qty'   => $qty,
        ];
    }

    if (empty($verified)) {
        setFlash('error', 'No valid products in cart.');
        redirect(BASE_URL . '/staff/pos.php');
    }

    if (!is_numeric($discountInput)) {
        setFlash('error', 'Enter a valid, non-negative discount amount.');
        redirect(BASE_URL . '/staff/pos.php');
    }
    $discountValue = (float)$discountInput;
    if (!is_finite($discountValue) || $discountValue < 0 || $discountValue > 99999999.99) {
        setFlash('error', 'Enter a valid, non-negative discount amount within the supported range.');
        redirect(BASE_URL . '/staff/pos.php');
    }

    if (!is_finite($subtotal) || $subtotal <= 0 || $subtotal > 99999999.99) {
        setFlash('error', 'The sale subtotal is outside the supported amount range.');
        redirect(BASE_URL . '/staff/pos.php');
    }

    $subtotalCents = (int)round($subtotal * 100);
    $discountCents = (int)round($discountValue * 100);

    $maxDiscountCents = isAdmin()
        ? $subtotalCents - 1
        : intdiv($subtotalCents, 10);
    if ($discountCents > $maxDiscountCents) {
        setFlash(
            'error',
            isAdmin()
                ? 'The discount must leave at least $0.01 due.'
                : 'Staff discounts cannot exceed 10% of the sale subtotal.'
        );
        redirect(BASE_URL . '/staff/pos.php');
    }

    if (!is_numeric($tenderedInput)) {
        setFlash('error', 'Enter a valid, non-negative amount tendered.');
        redirect(BASE_URL . '/staff/pos.php');
    }
    $tenderedValue = (float)$tenderedInput;
    if (!is_finite($tenderedValue) || $tenderedValue < 0 || $tenderedValue > 99999999.99) {
        setFlash('error', 'Enter a valid, non-negative amount tendered within the supported range.');
        redirect(BASE_URL . '/staff/pos.php');
    }

    $amtTenderedCents = (int)round($tenderedValue * 100);
    $grandTotalCents = $subtotalCents - $discountCents;
    if ($amtTenderedCents < $grandTotalCents) {
        setFlash('error', 'The amount tendered must cover the sale total.');
        redirect(BASE_URL . '/staff/pos.php');
    }

    $subtotal    = $subtotalCents / 100;
    $discount    = $discountCents / 100;
    $grandTotal  = $grandTotalCents / 100;
    $amtTendered = $amtTenderedCents / 100;
    $changeDue   = ($amtTenderedCents - $grandTotalCents) / 100;
    $receiptNo  = generateReceiptNo();
    $staffId    = (int)$_SESSION['user_id'];
    $staffName  = $_SESSION['user_name'] ?? 'Staff';

    try {
        $db->beginTransaction();

        // 1. Insert sale header
        $db->prepare("
            INSERT INTO sales
                (receipt_no, customer_name, staff_id, subtotal, discount,
                 total_amount, payment_method, amount_tendered, change_due)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $receiptNo, $customerName, $staffId,
            $subtotal, $discount, $grandTotal,
            $payMethod, $amtTendered, $changeDue,
        ]);
        $saleId = (int)$db->lastInsertId();

        // 2. Insert sale items + deduct stock atomically
        $siStmt  = $db->prepare("
            INSERT INTO sale_items
                (sale_id, product_id, product_name, sku, quantity, unit_price, discount, total)
            VALUES (?,?,?,?,?,?,0,?)
        ");
        $stkStmt = $db->prepare("
            UPDATE products SET quantity = quantity - ?
            WHERE id = ? AND quantity >= ?
        ");

        foreach ($verified as $item) {
            $lineTotal = $item['price'] * $item['qty'];

            $siStmt->execute([
                $saleId, $item['id'], $item['name'],
                $item['sku'], $item['qty'], $item['price'], $lineTotal,
            ]);

            $stkStmt->execute([$item['qty'], $item['id'], $item['qty']]);

            if ($stkStmt->rowCount() === 0) {
                $db->rollBack();
                setFlash('error', '"' . htmlspecialchars($item['name']) . '" ran out of stock during checkout. Please refresh.');
                redirect(BASE_URL . '/staff/pos.php');
            }
        }

        // 3. Create matching order record — only use columns that actually exist
        $orderNo = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

        $hasStaffId  = posHasColumn('orders', 'staff_id');
        $hasDiscount = posHasColumn('orders', 'discount');

        if ($hasStaffId && $hasDiscount) {
            $db->prepare("
                INSERT INTO orders
                    (order_no, customer_id, staff_id, subtotal, discount,
                     total_amount, payment_method, status)
                VALUES (?,?,?,?,?,?,'cash','completed')
            ")->execute([
                $orderNo, null, $staffId, $subtotal, $discount, $grandTotal,
            ]);
        } elseif ($hasStaffId) {
            $db->prepare("
                INSERT INTO orders
                    (order_no, customer_id, staff_id,
                     subtotal, total_amount, payment_method, status)
                VALUES (?,?,?,?,?,'cash','completed')
            ")->execute([
                $orderNo, null, $staffId, $subtotal, $grandTotal,
            ]);
        } elseif ($hasDiscount) {
            $db->prepare("
                INSERT INTO orders
                    (order_no, customer_id, subtotal, discount,
                     total_amount, payment_method, status)
                VALUES (?,?,?,?,?,'cash','completed')
            ")->execute([
                $orderNo, null, $subtotal, $discount, $grandTotal,
            ]);
        } else {
            $db->prepare("
                INSERT INTO orders
                    (order_no, customer_id,
                     subtotal, total_amount, payment_method, status)
                VALUES (?,?,?,?,'cash','completed')
            ")->execute([
                $orderNo, null, $subtotal, $grandTotal,
            ]);
        }
        $orderId = (int)$db->lastInsertId();
        if (posHasColumn('orders', 'stock_deducted')) {
          $db->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = ?")
             ->execute([$orderId]);
        }

        // 4. Insert order items
        $oiStmt = $db->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, discount, total)
            VALUES (?,?,?,?,0,?)
        ");
        foreach ($verified as $item) {
            $oiStmt->execute([
                $orderId, $item['id'], $item['qty'],
                $item['price'], $item['price'] * $item['qty'],
            ]);
        }

        // 5. Link sale to order (best-effort — only if column exists)
        if (posHasColumn('sales', 'order_id')) {
            $db->prepare("UPDATE sales SET order_id = ? WHERE id = ?")->execute([$orderId, $saleId]);
        }

        $db->commit();

        // 6. Log to stock_movements (best-effort — does not affect sale success)
        foreach ($verified as $item) {
            try {
                recordStockMovement(
                    $item['id'], -$item['qty'], 'sale',
                    $staffId, $staffName, $saleId, 'sale',
                    "POS sale {$receiptNo}"
                );
            } catch (Exception $ignored) {}
        }

        // 7. Store receipt in session
        $_SESSION['last_receipt'] = [
            'receipt_no'      => $receiptNo,
            'customer_name'   => $customerName,
            'staff_name'      => $_SESSION['user_name'],
            'payment_method'  => $payMethod,
            'subtotal'        => $subtotal,
            'discount'        => $discount,
            'grand_total'     => $grandTotal,
            'amount_tendered' => $amtTendered,
            'change_due'      => $changeDue,
            'items'           => $verified,
            'date'            => date('Y-m-d H:i:s'),
        ];

        redirect(BASE_URL . '/staff/receipt.php');

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('MITCH POS ERROR: ' . $e->getMessage());
        setFlash('error', 'Sale failed. Please try again. (' . htmlspecialchars($e->getMessage()) . ')');
        redirect(BASE_URL . '/staff/pos.php');
    }
}


// ── Load Products ──────────────────────────────────────────────────────────
$catFilter  = (int) ($_GET['cat'] ?? 0);
$search     = sanitize($_GET['q'] ?? '');

$where  = "p.status = 'active' AND p.quantity > 0";
$params = [];
if ($catFilter) {
    $where   .= ' AND p.category_id = ?';
    $params[] = $catFilter;
}
if ($search) {
    $where   .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$pStmt = $db->prepare("
    SELECT p.*, c.name AS category
    FROM   products    p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE  $where
    ORDER  BY p.name
");
$pStmt->execute($params);
$products   = $pStmt->fetchAll();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="pos-wrapper">

  <!-- ── LEFT: Product Grid ──────────────────────────────────────────── -->
  <div class="pos-products">

    <div class="card mb-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-center">
          <div class="col-md-5">
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" id="posSearchInput" class="form-control"
                     placeholder="Search name or scan barcode / SKU…"
                     oninput="posSearch(this.value)"
                     value="<?= htmlspecialchars($search) ?>">
            </div>
          </div>
          <div class="col-md-4">
            <div class="d-flex flex-wrap gap-1">
              <a href="<?= BASE_URL ?>/staff/pos.php"
                 class="btn btn-sm <?= !$catFilter ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
              <?php foreach ($categories as $cat): ?>
              <a href="<?= BASE_URL ?>/staff/pos.php?cat=<?= (int) $cat['id'] ?>"
                 class="btn btn-sm <?= $catFilter === (int) $cat['id'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <?= htmlspecialchars($cat['name']) ?>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-md-3 text-end">
            <small class="text-muted"><?= count($products) ?> products available</small>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3" id="posProductsGrid">
      <?php foreach ($products as $p):
        // Use JSON_HEX_* flags to produce HTML-attribute-safe JSON
        // that JS can parse correctly even for names with &, <, > or "
        $pJson = json_encode([
            'id'       => (int)$p['id'],
            'name'     => $p['name'],
            'sku'      => $p['sku'],
            'price'    => $p['price'],
            'quantity' => (int)$p['quantity'],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
      ?>
      <div class="col-6 col-md-4 col-xl-3 pos-product-col">
        <div class="pos-product-card"
             data-name="<?= htmlspecialchars($p['name']) ?>"
             data-sku="<?= htmlspecialchars($p['sku']) ?>"
             onclick='cartAddFromJson(<?= $pJson ?>)'>
          <?php $productImageUrl = productImageUrl($p['image'] ?? null); ?>
          <?php if ($productImageUrl): ?>
            <img src="<?= htmlspecialchars($productImageUrl) ?>"
                 alt="<?= htmlspecialchars($p['name']) ?>"
                 onerror="this.style.display='none'">
          <?php else: ?>
            <div style="height:110px;background:#f8f0f5;border-radius:8px;
                        display:flex;align-items:center;justify-content:center;color:#c0007a">
              <i class="bi bi-image" style="font-size:2rem"></i>
            </div>
          <?php endif; ?>
          <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
          <div class="d-flex justify-content-between align-items-center mt-1">
            <span class="product-price"><?= money($p['price']) ?></span>
            <small class="text-muted">Qty: <?= (int) $p['quantity'] ?></small>
          </div>
          <small class="text-muted font-monospace d-block mt-1">
            <?= htmlspecialchars($p['sku']) ?>
          </small>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($products)): ?>
      <div class="col-12 text-center py-5 text-muted">
        <i class="bi bi-box" style="font-size:3rem"></i>
        <p class="mt-2">No products available</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── RIGHT: Cart ─────────────────────────────────────────────────── -->
  <div class="pos-cart">
    <div class="card h-100 d-flex flex-column">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-cart3 me-2"></i>Cart</span>
        <button class="btn btn-sm btn-outline-danger" onclick="Cart.clear()">
          <i class="bi bi-trash me-1"></i>Clear
        </button>
      </div>

      <div class="card-body pos-cart-items" id="cartItems">
        <p class="text-center text-muted py-4">
          <i class="bi bi-cart3" style="font-size:2rem"></i><br>Cart is empty
        </p>
      </div>

      <div class="card-footer p-3">
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Subtotal</span>
          <span id="cartSubtotal" class="fw-semibold">$0.00</span>
        </div>
        <div class="mb-2">
          <label class="form-label mb-1" style="font-size:.82rem">Discount ($)</label>
          <input type="number" id="discountInput" class="form-control form-control-sm"
                 min="0" step="0.01" value="0"
                 oninput="Cart.render(); calcChange()">
          <small class="form-text">
            <?= isAdmin() ? 'Admin discounts must leave at least $0.01 due.' : 'Staff discounts are limited to 10% of the subtotal.' ?>
          </small>
        </div>
        <div class="d-flex justify-content-between mb-3 border-top pt-2">
          <span class="fw-bold">Grand Total</span>
          <span id="cartTotal" class="fw-bold text-success" style="font-size:1.2rem">$0.00</span>
        </div>
        <button class="btn btn-primary w-100 fw-bold" onclick="openCheckout()">
          <i class="bi bi-cash-coin me-2"></i>Checkout
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Checkout Modal -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Complete Sale</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="checkoutForm">
        <input type="hidden" name="action"      value="complete_sale">
        <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
        <input type="hidden" name="cart_data"   id="cartDataField">
        <input type="hidden" name="discount"    id="checkoutDiscount">

        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Customer Name</label>
            <input type="text" name="customer_name" class="form-control"
                   value="Walk-in Customer">
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Method</label>
            <!-- Cash is the only accepted payment method -->
            <input type="hidden" name="payment_method" value="cash">
            <input type="text" class="form-control" value="Cash Payment" readonly
                   style="background:#f8f0f5;font-weight:700;color:var(--primary)">
          </div>

          <div id="cashSection">
            <div class="d-flex justify-content-between mb-2 p-3 rounded"
                 style="background:#f8f0f5">
              <span class="fw-bold">Total Amount:</span>
              <span class="fw-bold text-success" id="checkoutTotal"
                    style="font-size:1.1rem">$0.00</span>
            </div>
            <div class="mb-3">
              <label class="form-label">Amount Tendered ($)</label>
              <input type="number" name="amount_tendered" id="amountTendered"
                     class="form-control" min="0" step="0.01" required
                     oninput="calcChange()">
            </div>
            <div class="d-flex justify-content-between p-3 rounded"
                 style="background:#e8f5e9">
              <span class="fw-bold">Change Due:</span>
              <span class="fw-bold text-success" id="changeDue"
                    style="font-size:1.1rem">$0.00</span>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary"
                  data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-bold px-4">
            <i class="bi bi-check-circle me-2"></i>Complete Sale
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// FIX: safe bridge function — JSON is already encoded server-side;
//      the browser just parses it and calls Cart.add()
function cartAddFromJson(jsonValue) {
    try {
        const p = (typeof jsonValue === 'string') ? JSON.parse(jsonValue) : jsonValue;
        if (!p || !p.id) return;
        Cart.add(p.id, p.name, p.sku, p.price, p.quantity);
    } catch (e) {
        console.error('cartAddFromJson parse error', e);
    }
}

function openCheckout() {
    if (Cart.items.length === 0) {
        alert('Please add products to the cart first.');
        return;
    }
    const disc = parseFloat(document.getElementById('discountInput').value) || 0;
    const sub  = Cart.subtotal();
    const tot  = Math.max(0, sub - disc);
    document.getElementById('checkoutTotal').textContent    = '$' + tot.toFixed(2);
    document.getElementById('checkoutDiscount').value       = disc;
    document.getElementById('cartDataField').value          = JSON.stringify(Cart.items);
    document.getElementById('amountTendered').value         = '';
    document.getElementById('changeDue').textContent        = '$0.00';
    new bootstrap.Modal(document.getElementById('checkoutModal')).show();
}
// Cash is the only payment method — cashSection always visible
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
