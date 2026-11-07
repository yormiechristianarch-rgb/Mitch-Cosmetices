<?php
/**
 * MITCH COSMETICS SHOP — Product Gallery
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('customer', 'admin', 'staff');

$pageTitle  = 'Product Gallery';
$activeMenu = 'gallery';
$db = getDB();

$catFilter = (int)($_GET['cat'] ?? 0);
$search    = sanitize($_GET['q'] ?? '');

$where  = "p.status='active'";
$params = [];
if ($catFilter) { $where .= " AND p.category_id=?"; $params[] = $catFilter; }
if ($search)    { $where .= " AND p.name LIKE ?"; $params[] = "%$search%"; }

$stmt = $db->prepare("SELECT p.*, c.name AS category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE $where ORDER BY p.created_at DESC");
$stmt->execute($params);
$products   = $stmt->fetchAll();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Filter Bar -->
<div class="row g-2 mb-4 align-items-center">
  <div class="col-md-5">
    <form method="GET" class="input-group">
      <input type="text" name="q" class="form-control" placeholder="Search products..."
             value="<?= htmlspecialchars($search) ?>">
      <?php if ($catFilter): ?><input type="hidden" name="cat" value="<?= $catFilter ?>"><?php endif; ?>
      <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
    </form>
  </div>
  <div class="col-md-7">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/customer/gallery.php" class="btn btn-sm <?= !$catFilter?'btn-primary':'btn-outline-secondary' ?>">All</a>
      <?php foreach ($categories as $c): ?>
      <a href="?cat=<?= $c['id'] ?><?= $search?"&q=".urlencode($search):'' ?>"
         class="btn btn-sm <?= $catFilter==$c['id']?'btn-primary':'btn-outline-secondary' ?>">
        <?= htmlspecialchars($c['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <?php foreach ($products as $p): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="gallery-card"
         data-bs-toggle="modal" data-bs-target="#galleryModal"
         onclick="showGalleryItem(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)">
      <?php $productImageUrl = productImageUrl($p['image'] ?? null); ?>
      <?php if ($productImageUrl): ?>
        <img src="<?= htmlspecialchars($productImageUrl) ?>" alt="<?= htmlspecialchars($p['name']) ?>"
             onerror="this.style.display='none'">
      <?php else: ?>
        <div style="width:100%;height:220px;background:var(--accent);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-image" style="font-size:3rem;color:var(--primary)"></i>
        </div>
      <?php endif; ?>
      <div class="gallery-overlay">
        <div class="gallery-title"><?= htmlspecialchars($p['name']) ?></div>
        <div class="gallery-price"><?= money($p['price']) ?></div>
        <?php if ($p['video']): ?>
          <small><i class="bi bi-play-circle me-1"></i>Has Video</small>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($products)): ?>
  <div class="col-12 text-center py-5 text-muted">
    <i class="bi bi-images" style="font-size:3rem"></i>
    <p class="mt-2">No products in gallery.</p>
  </div>
  <?php endif; ?>
</div>

<!-- Gallery Modal -->
<div class="modal fade" id="galleryModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="galleryModalTitle">Product Detail</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-5" id="galleryMediaCol">
            <img id="galleryImg" alt="" onerror="this.style.display='none'" style="width:100%;border-radius:12px;object-fit:cover;max-height:300px;display:none">
            <div id="galleryVideoWrap" style="display:none;margin-top:10px">
              <video id="galleryVideo" controls style="width:100%;border-radius:8px"></video>
            </div>
          </div>
          <div class="col-md-7">
            <span id="galleryCat" class="badge bg-light text-dark border mb-2"></span>
            <h5 id="galleryName" class="fw-bold"></h5>
            <p id="galleryDesc" class="text-muted" style="font-size:.88rem"></p>
            <div class="d-flex align-items-center gap-3 mb-3">
              <span id="galleryPrice" class="fw-bold text-success" style="font-size:1.4rem"></span>
              <span id="galleryStockBadge"></span>
            </div>
            <small class="text-muted d-block mb-1">SKU: <span id="gallerySku" class="font-monospace"></span></small>
            <a id="galleryOrderBtn" href="#" class="btn btn-primary mt-2">
              <i class="bi bi-bag-plus me-1"></i>Order This Product
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function showGalleryItem(p) {
  document.getElementById('galleryModalTitle').textContent = p.name;
  document.getElementById('galleryName').textContent  = p.name;
  document.getElementById('galleryDesc').textContent  = p.description || 'No description available.';
  document.getElementById('galleryCat').textContent   = p.category || '';
  document.getElementById('galleryPrice').textContent = '$' + parseFloat(p.price).toFixed(2);
  document.getElementById('gallerySku').textContent   = p.sku;
  document.getElementById('galleryStockBadge').innerHTML = p.quantity > 0
    ? '<span class="badge bg-success">In Stock ('+p.quantity+')</span>'
    : '<span class="badge bg-danger">Out of Stock</span>';

  const img = document.getElementById('galleryImg');
  if (p.image) { img.src = '<?= UPLOAD_URL ?>' + encodeURIComponent(p.image); img.style.display = ''; }
  else { img.style.display = 'none'; }

  const vw  = document.getElementById('galleryVideoWrap');
  const vid = document.getElementById('galleryVideo');
  if (p.video) { vid.src = '<?= VIDEO_URL ?>' + p.video; vw.style.display = ''; }
  else { vw.style.display = 'none'; }

  var pid = parseInt(p.id) || 0;
  var inStock = parseInt(p.quantity) > 0;
  var btn = document.getElementById('galleryOrderBtn');
  if (inStock && pid > 0) {
    btn.href = '<?= BASE_URL ?>/customer/shop.php?order=' + pid;
    btn.className = 'btn btn-primary mt-2';
    btn.innerHTML = '<i class="bi bi-bag-plus me-1"></i>Order This Product';
  } else {
    btn.href = '#';
    btn.className = 'btn btn-secondary mt-2 disabled';
    btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Out of Stock';
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
