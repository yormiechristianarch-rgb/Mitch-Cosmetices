<?php
/**
 * MITCH COSMETICS SHOP — Product Management (Inventory-aware)
 *
 * Changes vs original:
 *  - action=add         → records 'initial_stock' movement if qty > 0
 *  - action=edit        → quantity field is LOCKED; use action=add_stock or
 *                         action=adjust instead. Other fields still editable.
 *  - action=add_stock   → adds stock via recordStockMovement('stock_added')
 *  - action=adjust      → admin manual correction via recordStockMovement('manual_adjustment')
 *  - action=delete      → soft-delete (status='inactive') if product has
 *                         sales/order history; hard-delete only if no history.
 *
 * Schema resilience:
 *  - Works whether or not low_stock_threshold column exists on the live DB.
 *  - Column presence is detected once per request and cached.
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

// ── Detect optional columns once ──────────────────────────────────────────
/**
 * Returns true if the products table has a low_stock_threshold column.
 * Result is cached so we only query INFORMATION_SCHEMA once per request.
 */
function hasLowStockColumn(): bool {
    static $result = null;
    if ($result !== null) return $result;
    $db = getDB();
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'products'
               AND COLUMN_NAME  = 'low_stock_threshold'"
        );
        $stmt->execute();
        $result = ((int)$stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        $result = false;
    }
    return $result;
}

$pageTitle  = 'Products';
$activeMenu = 'products';
$db = getDB();

$currentUserId   = (int)($_SESSION['user_id']   ?? 0);
$currentUserName = $_SESSION['user_name'] ?? 'Admin';

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Add / Edit product info ────────────────────────────────────────────
    if ($action === 'add' || $action === 'edit') {
        $id         = (int)($_POST['id'] ?? 0);
        $cat        = (int)($_POST['category_id'] ?? 0) ?: null;
        $name       = sanitize($_POST['name'] ?? '');
        $sku        = strtoupper(sanitize($_POST['sku'] ?? ''));
        $desc       = sanitize($_POST['description'] ?? '');
        $price      = abs((float)($_POST['price'] ?? 0));
        $costPrice  = abs((float)($_POST['cost_price'] ?? 0));
        $status     = in_array($_POST['status'] ?? '', ['active','inactive'])
                      ? $_POST['status'] : 'active';
        $lowThresh  = abs((int)($_POST['low_stock_threshold'] ?? 0));

        if (empty($name) || $price <= 0) {
            setFlash('error', 'Product name and a positive selling price are required.');
            redirect(BASE_URL . '/admin/products.php');
        }

        // Auto-generate SKU if blank
        if (empty($sku)) {
            $catName = 'PROD';
            if ($cat) {
                $cs = $db->prepare("SELECT name FROM categories WHERE id = ?");
                $cs->execute([$cat]);
                $catName = $cs->fetchColumn() ?: 'PROD';
            }
            $sku = generateSKU($catName);
        }

        // Image upload
        $imageName = $_POST['existing_image'] ?? null;
        if (!empty($_FILES['image']['name'])) {
            $up = uploadFile($_FILES['image'], UPLOAD_PATH,
                ['image/jpeg','image/png','image/webp','image/gif'], 5000000);
            if ($up['success']) { $imageName = $up['filename']; }
            else { setFlash('error', $up['message']); redirect(BASE_URL . '/admin/products.php'); }
        }

        // Video upload
        $videoName = $_POST['existing_video'] ?? null;
        if (!empty($_FILES['video']['name'])) {
            $up = uploadFile($_FILES['video'], VIDEO_PATH,
                ['video/mp4','video/webm','video/ogg'], 50000000);
            if ($up['success']) { $videoName = $up['filename']; }
            else { setFlash('error', $up['message']); redirect(BASE_URL . '/admin/products.php'); }
        }

        if ($action === 'add') {
            // quantity for new product comes from the form
            $qty = abs((int)($_POST['quantity'] ?? 0));

            // Build INSERT dynamically — low_stock_threshold is optional
            if (hasLowStockColumn()) {
                $stmt = $db->prepare("
                    INSERT INTO products
                        (category_id,name,sku,description,price,cost_price,
                         quantity,image,video,status,low_stock_threshold)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([$cat,$name,$sku,$desc,$price,$costPrice,
                                0,$imageName,$videoName,$status,$lowThresh]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO products
                        (category_id,name,sku,description,price,cost_price,
                         quantity,image,video,status)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([$cat,$name,$sku,$desc,$price,$costPrice,
                                0,$imageName,$videoName,$status]);
            }
            $newId = (int)$db->lastInsertId();

            // Record initial stock movement if qty > 0
            if ($qty > 0) {
                recordStockMovement(
                    $newId, $qty, 'initial_stock',
                    $currentUserId, $currentUserName,
                    null, null,
                    'Initial stock on product creation'
                );
            }
            setFlash('success', "Product «{$name}» added. Initial stock: {$qty}.");

        } else {
            // EDIT — quantity is NOT updated here; use Add Stock / Adjust instead
            if (hasLowStockColumn()) {
                $stmt = $db->prepare("
                    UPDATE products SET
                        category_id=?,name=?,sku=?,description=?,
                        price=?,cost_price=?,image=?,video=?,
                        status=?,low_stock_threshold=?
                    WHERE id=?
                ");
                $stmt->execute([$cat,$name,$sku,$desc,$price,$costPrice,
                                $imageName,$videoName,$status,$lowThresh,$id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE products SET
                        category_id=?,name=?,sku=?,description=?,
                        price=?,cost_price=?,image=?,video=?,
                        status=?
                    WHERE id=?
                ");
                $stmt->execute([$cat,$name,$sku,$desc,$price,$costPrice,
                                $imageName,$videoName,$status,$id]);
            }
            setFlash('success', "Product «{$name}» updated. (Use Add Stock to change quantity.)");
        }

    // ── Add Stock (Restock) ────────────────────────────────────────────────
    } elseif ($action === 'add_stock') {
        $pid   = (int)($_POST['product_id'] ?? 0);
        $qty   = abs((int)($_POST['add_qty'] ?? 0));
        $notes = sanitize($_POST['notes'] ?? '');

        if ($pid < 1 || $qty < 1) {
            setFlash('error', 'Invalid product or quantity.');
            redirect(BASE_URL . '/admin/products.php');
        }

        $result = recordStockMovement(
            $pid, $qty, 'stock_added',
            $currentUserId, $currentUserName,
            null, null,
            $notes ?: 'Stock added by admin'
        );

        if ($result['success']) {
            setFlash('success', "Stock updated. New quantity: {$result['qty_after']}.");
        } else {
            setFlash('error', $result['message']);
        }

    // ── Manual Adjustment ──────────────────────────────────────────────────
    } elseif ($action === 'adjust') {
        $pid        = (int)($_POST['product_id'] ?? 0);
        $adjType    = $_POST['adj_type'] ?? 'add';   // 'add' | 'remove'
        $adjQty     = abs((int)($_POST['adj_qty'] ?? 0));
        $adjReason  = sanitize($_POST['adj_reason'] ?? '');
        $moveType   = sanitize($_POST['movement_type'] ?? 'manual_adjustment');

        $allowedMoveTypes = [
            'manual_adjustment','damaged','expired','lost','correction','return'
        ];
        if (!in_array($moveType, $allowedMoveTypes, true)) {
            $moveType = 'manual_adjustment';
        }

        if ($pid < 1 || $adjQty < 1) {
            setFlash('error', 'Invalid product or quantity.');
            redirect(BASE_URL . '/admin/products.php');
        }

        $delta  = ($adjType === 'remove') ? -$adjQty : +$adjQty;
        $result = recordStockMovement(
            $pid, $delta, $moveType,
            $currentUserId, $currentUserName,
            null, null,
            $adjReason ?: 'Manual adjustment by admin'
        );

        if ($result['success']) {
            setFlash('success', "Adjustment recorded. New quantity: {$result['qty_after']}.");
        } else {
            setFlash('error', $result['message']);
        }

    // ── Delete (safe) ──────────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // ── Check for sales history using proper prepared statements ───────
        $saleChk = $db->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id = ?");
        $saleChk->execute([$id]);
        $hasSales = (int)$saleChk->fetchColumn();

        $orderChk = $db->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
        $orderChk->execute([$id]);
        $hasOrders = (int)$orderChk->fetchColumn();

        if ($hasSales > 0 || $hasOrders > 0) {
            // ── Read current quantity BEFORE the UPDATE so we can record the movement ──
            $prStmt = $db->prepare("SELECT name, quantity FROM products WHERE id = ?");
            $prStmt->execute([$id]);
            $pr = $prStmt->fetch();
            $originalQty = ($pr && (int)$pr['quantity'] > 0) ? (int)$pr['quantity'] : 0;

            // Soft-delete: mark inactive, set quantity to 0
            $db->prepare("UPDATE products SET status = 'inactive', quantity = 0 WHERE id = ?")
               ->execute([$id]);

            // Record the stock wipe now that we have the pre-update quantity
            if ($originalQty > 0) {
                recordStockMovement(
                    $id, -$originalQty, 'manual_adjustment',
                    $currentUserId, $currentUserName,
                    null, null,
                    'Product deactivated — stock set to 0'
                );
            }
            setFlash('warning',
                'Product has sales/order history and cannot be permanently deleted. '
                . 'It has been set to Inactive with zero stock instead.');
        } else {
            // Safe to hard-delete — no history
            $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
            setFlash('success', 'Product deleted.');
        }
    }

    redirect(BASE_URL . '/admin/products.php');
}

// ── Filters ────────────────────────────────────────────────────────────────
$filter    = $_GET['filter'] ?? '';
$search    = sanitize($_GET['q'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);

$hasLST = hasLowStockColumn();   // cached — only one INFORMATION_SCHEMA hit
$sysThresh = (int)APP_LOW_STOCK_THRESHOLD;

$where  = '1=1';
$params = [];
if ($search)    { $where .= ' AND (p.name LIKE ? OR p.sku LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where .= ' AND p.category_id = ?'; $params[] = $catFilter; }
if ($filter === 'low_stock') {
    if ($hasLST) {
        $where .= ' AND p.quantity > 0 AND p.quantity <= GREATEST(p.low_stock_threshold, ' . $sysThresh . ')';
    } else {
        $where .= ' AND p.quantity > 0 AND p.quantity <= ' . $sysThresh;
    }
}
if ($filter === 'out_of_stock') { $where .= ' AND p.quantity = 0'; }
if ($filter === 'active')       { $where .= " AND p.status = 'active'"; }

$stmt = $db->prepare("
    SELECT p.*, c.name AS category
    FROM   products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE  $where
    ORDER  BY p.created_at DESC
");
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Summary counts
$totalProducts  = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalUnits     = (int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM products")->fetchColumn();
if ($hasLST) {
    $totalLow = (int)$db->query(
        "SELECT COUNT(*) FROM products WHERE quantity > 0 AND quantity <= GREATEST(low_stock_threshold, " . $sysThresh . ")"
    )->fetchColumn();
} else {
    $totalLow = (int)$db->query(
        "SELECT COUNT(*) FROM products WHERE quantity > 0 AND quantity <= " . $sysThresh
    )->fetchColumn();
}
$totalOut       = (int)$db->query("SELECT COUNT(*) FROM products WHERE quantity = 0")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<!-- Summary cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card stat-blue">
      <i class="bi bi-box-seam stat-icon"></i>
      <div class="stat-value"><?= number_format($totalProducts) ?></div>
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
      <div class="stat-label">Low Stock</div>
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

<!-- Search & Filter Bar -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <input type="text" name="q" class="form-control" placeholder="Search name or SKU…"
               value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-2">
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
        <select name="filter" class="form-select">
          <option value=""     <?= $filter===''            ? 'selected':'' ?>>All Stock</option>
          <option value="low_stock"   <?= $filter==='low_stock'   ? 'selected':'' ?>>Low Stock</option>
          <option value="out_of_stock"<?= $filter==='out_of_stock'? 'selected':'' ?>>Out of Stock</option>
          <option value="active"      <?= $filter==='active'      ? 'selected':'' ?>>Active Only</option>
        </select>
      </div>
      <div class="col-md-5 d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="<?= BASE_URL ?>/admin/products.php" class="btn btn-outline-secondary">Reset</a>
        <a href="<?= BASE_URL ?>/admin/inventory.php" class="btn btn-outline-primary">
          <i class="bi bi-journal-text me-1"></i>Stock History
        </a>
        <button type="button" class="btn btn-success ms-auto"
                data-bs-toggle="modal" data-bs-target="#productModal"
                onclick="openAddModal()">
          <i class="bi bi-plus-lg me-1"></i>Add Product
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Products Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-box-seam me-2"></i>Products
      <span class="badge bg-primary ms-2"><?= count($products) ?></span>
    </span>
    <a href="<?= BASE_URL ?>/reports/index.php?type=inventory"
       class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-printer me-1"></i>Print Inventory
    </a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="productsTable">
        <thead>
          <tr>
            <th>Image</th>
            <th>Name / SKU</th>
            <th>Category</th>
            <th>Sell Price</th>
            <th>Cost</th>
            <th>Stock</th>
            <th>Status</th>
            <th class="no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): ?>
          <tr>
            <td>
              <?php $productImageUrl = productImageUrl($p['image'] ?? null); ?>
              <?php if ($productImageUrl): ?>
                <img src="<?= htmlspecialchars($productImageUrl) ?>"
                     class="product-thumb" alt="<?= htmlspecialchars($p['name']) ?>"
                     onerror="this.style.display='none'">
              <?php else: ?>
                <div class="product-thumb d-flex align-items-center justify-content-center
                            bg-light text-muted">
                  <i class="bi bi-image"></i>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
              <small class="text-muted font-monospace"><?= htmlspecialchars($p['sku']) ?></small>
            </td>
            <td><small><?= htmlspecialchars($p['category'] ?? '—') ?></small></td>
            <td class="fw-bold text-success"><?= money($p['price']) ?></td>
            <td class="text-muted"><?= money($p['cost_price']) ?></td>
            <td><?= stockBadge($p) ?></td>
            <td><?= statusBadge($p['status']) ?></td>
            <td class="no-print">
              <div class="d-flex gap-1 flex-wrap">
                <!-- Edit product info -->
                <button class="btn btn-sm btn-outline-primary"
                        title="Edit product info"
                        onclick='openEditModal(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                  <i class="bi bi-pencil"></i>
                </button>
                <!-- Add Stock -->
                <button class="btn btn-sm btn-outline-success"
                        title="Add stock / restock"
                        onclick='openAddStockModal(<?= (int)$p['id'] ?>,
                                 <?= json_encode($p['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                                 <?= (int)$p['quantity'] ?>)'>
                  <i class="bi bi-plus-circle"></i>
                </button>
                <!-- Adjust Stock -->
                <button class="btn btn-sm btn-outline-warning"
                        title="Manual stock adjustment"
                        onclick='openAdjustModal(<?= (int)$p['id'] ?>,
                                 <?= json_encode($p['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                                 <?= (int)$p['quantity'] ?>)'>
                  <i class="bi bi-sliders"></i>
                </button>
                <!-- Delete -->
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Delete this product? If it has sales history it will be deactivated instead.')">
                  <input type="hidden" name="action"     value="delete">
                  <input type="hidden" name="id"         value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete / deactivate">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($products)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-5">
              <i class="bi bi-box" style="font-size:2rem"></i><br>No products found
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ ADD / EDIT PRODUCT MODAL ════════════════════════════════════════════ -->
<div class="modal fade" id="productModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="productModalTitle">Add Product</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action"         id="productAction"  value="add">
        <input type="hidden" name="id"             id="productId"      value="">
        <input type="hidden" name="existing_image" id="existingImage"  value="">
        <input type="hidden" name="existing_video" id="existingVideo"  value="">
        <input type="hidden" name="csrf_token"     value="<?= csrfToken() ?>">

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Product Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="pName" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">SKU / Barcode</label>
              <div class="input-group">
                <input type="text" name="sku" id="pSku"
                       class="form-control font-monospace" placeholder="Auto-generated if blank">
                <button type="button" class="btn btn-outline-secondary" onclick="genSKU()">Auto</button>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Category</label>
              <select name="category_id" id="pCat" class="form-select">
                <option value="">— Select —</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Selling Price ($) <span class="text-danger">*</span></label>
              <input type="number" name="price" id="pPrice"
                     class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Cost Price ($)</label>
              <input type="number" name="cost_price" id="pCostPrice"
                     class="form-control" step="0.01" min="0">
            </div>
            <!-- Quantity — shown only when adding; locked in edit mode -->
            <div class="col-md-4" id="qtyRow">
              <label class="form-label">
                Initial Stock Qty
                <span id="qtyEditNote" class="text-muted" style="font-size:.75rem;display:none">
                  — use <strong>Add Stock</strong> or <strong>Adjust</strong> to change
                </span>
              </label>
              <input type="number" name="quantity" id="pQty"
                     class="form-control" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">
                Low-Stock Alert Qty
                <small class="text-muted">(0 = system default <?= APP_LOW_STOCK_THRESHOLD ?>)</small>
              </label>
              <input type="number" name="low_stock_threshold" id="pLowThresh"
                     class="form-control" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="pStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="pDesc" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Product Image</label>
              <input type="file" name="image" class="form-control" accept="image/*"
                     onchange="previewImage(this,'imgPreview')">
              <img id="imgPreview" alt="Product image preview"
                   onerror="this.removeAttribute('src');this.style.display='none'"
                   style="display:none;max-height:100px;margin-top:8px;border-radius:8px">
            </div>
            <div class="col-md-6">
              <label class="form-label">Promotional Video</label>
              <input type="file" name="video" class="form-control" accept="video/mp4,video/webm">
              <small class="text-muted">MP4 / WebM, max 50 MB</small>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="productSubmitBtn">Add Product</button>
        </div>
      </form>
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
        <input type="hidden" name="action"      value="add_stock">
        <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
        <input type="hidden" name="product_id"  id="asProductId">
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

<!-- ══ MANUAL ADJUST MODAL ════════════════════════════════════════════════ -->
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
        <input type="hidden" name="action"      value="adjust">
        <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
        <input type="hidden" name="product_id"  id="adjProductId">
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
              <input type="number" name="adj_qty" id="adjQty"
                     class="form-control" min="1" required
                     oninput="calcAdjTotal()" placeholder="e.g. 5">
            </div>
            <div class="col-12">
              <div class="p-3 rounded" style="background:var(--surface-2)">
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
/* ── Product add / edit modal ─────────────────────────────────────────── */
function openAddModal() {
  document.getElementById('productModalTitle').textContent  = 'Add New Product';
  document.getElementById('productAction').value            = 'add';
  document.getElementById('productSubmitBtn').textContent   = 'Add Product';
  document.getElementById('productId').value                = '';
  document.getElementById('pName').value                    = '';
  document.getElementById('pSku').value                     = '';
  document.getElementById('pCat').value                     = '';
  document.getElementById('pPrice').value                   = '';
  document.getElementById('pCostPrice').value               = '';
  document.getElementById('pQty').value                     = '0';
  document.getElementById('pLowThresh').value               = '0';
  document.getElementById('pStatus').value                  = 'active';
  document.getElementById('pDesc').value                    = '';
  document.getElementById('existingImage').value            = '';
  document.getElementById('existingVideo').value            = '';
  document.getElementById('imgPreview').removeAttribute('src');
  document.getElementById('imgPreview').style.display       = 'none';
  // Show qty field, hide edit note
  document.getElementById('pQty').readOnly                  = false;
  document.getElementById('pQty').style.background          = '';
  document.getElementById('qtyEditNote').style.display      = 'none';
}

function openEditModal(p) {
  document.getElementById('productModalTitle').textContent  = 'Edit Product';
  document.getElementById('productAction').value            = 'edit';
  document.getElementById('productSubmitBtn').textContent   = 'Save Changes';
  document.getElementById('productId').value                = p.id;
  document.getElementById('pName').value                    = p.name;
  document.getElementById('pSku').value                     = p.sku;
  document.getElementById('pCat').value                     = p.category_id || '';
  document.getElementById('pPrice').value                   = p.price;
  document.getElementById('pCostPrice').value               = p.cost_price;
  document.getElementById('pQty').value                     = p.quantity;
  document.getElementById('pLowThresh').value               = p.low_stock_threshold || '0';
  document.getElementById('pStatus').value                  = p.status;
  document.getElementById('pDesc').value                    = p.description || '';
  document.getElementById('existingImage').value            = p.image || '';
  document.getElementById('existingVideo').value            = p.video || '';
  if (p.image) {
    const img = document.getElementById('imgPreview');
    img.src = '<?= UPLOAD_URL ?>' + encodeURIComponent(p.image);
    img.style.display = 'block';
  } else {
    const img = document.getElementById('imgPreview');
    img.removeAttribute('src');
    img.style.display = 'none';
  }
  // Lock qty field — changes must go through Add Stock or Adjust
  document.getElementById('pQty').readOnly             = true;
  document.getElementById('pQty').style.background     = '#f5f5f5';
  document.getElementById('qtyEditNote').style.display = 'inline';

  new bootstrap.Modal(document.getElementById('productModal')).show();
}

function genSKU() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let s = 'SKU-';
  for (let i = 0; i < 6; i++) s += chars[Math.floor(Math.random() * chars.length)];
  document.getElementById('pSku').value = s;
}

/* ── Add Stock modal ──────────────────────────────────────────────────── */
var _asCurrentQty = 0;
function openAddStockModal(pid, pname, currentQty) {
  _asCurrentQty = parseInt(currentQty) || 0;
  document.getElementById('asProductId').value        = pid;
  document.getElementById('asProductName').textContent = pname;
  document.getElementById('asCurrentQty').textContent  = _asCurrentQty;
  document.getElementById('asQty').value               = '';
  document.getElementById('asNewTotal').textContent    = '—';
  new bootstrap.Modal(document.getElementById('addStockModal')).show();
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

/* ── Adjust Stock modal ───────────────────────────────────────────────── */
var _adjCurrentQty = 0;
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
  el.style.color = (n < 0) ? 'var(--danger)' : 'var(--success)';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
