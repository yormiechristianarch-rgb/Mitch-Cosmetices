<?php
/**
 * MITCH COSMETICS SHOP — Category Management
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Categories';
$activeMenu = 'categories';
$db = getDB();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name = sanitize($_POST['name'] ?? '');
        $desc = sanitize($_POST['description'] ?? '');
        if (empty($name)) { setFlash('error', 'Category name is required.'); }
        else {
            if ($action === 'add') {
                $stmt = $db->prepare("INSERT INTO categories (name, description) VALUES (?,?)");
                $stmt->execute([$name, $desc]);
                setFlash('success', 'Category added successfully.');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare("UPDATE categories SET name=?, description=? WHERE id=?");
                $stmt->execute([$name, $desc, $id]);
                setFlash('success', 'Category updated.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Guard: block delete if category still has products
        $countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
        $countStmt->execute([$id]);
        $productCount = (int)$countStmt->fetchColumn();
        if ($productCount > 0) {
            setFlash('error', "Cannot delete: this category has {$productCount} product(s) assigned to it. Reassign or delete those products first.");
        } else {
            try {
                $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
                setFlash('success', 'Category deleted.');
            } catch (PDOException $e) {
                setFlash('error', 'Could not delete category. It may still be referenced by existing records.');
            }
        }
    }
    redirect(BASE_URL . '/admin/categories.php');
}

$categories = $db->query("
    SELECT c.*, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id ORDER BY c.name
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div></div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCatModal">
    <i class="bi bi-plus-lg me-1"></i>Add Category
  </button>
</div>

<div class="card">
  <div class="card-header"><i class="bi bi-tags me-2"></i>All Categories</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>#</th><th>Name</th><th>Description</th><th>Products</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($categories as $i => $cat): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($cat['name']) ?></td>
            <td><?= htmlspecialchars($cat['description'] ?? '—') ?></td>
            <td><span class="badge bg-primary"><?= $cat['product_count'] ?></span></td>
            <td><small class="text-muted"><?= date('M d, Y', strtotime($cat['created_at'])) ?></small></td>
            <td>
              <button class="btn btn-sm btn-outline-primary me-1"
                      onclick="editCategory(<?= json_encode($cat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)">
                <i class="bi bi-pencil"></i>
              </button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this category?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($categories)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No categories yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addCatModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Category</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editCatModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editCatId">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="editCatName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" id="editCatDesc" class="form-control" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editCategory(cat) {
  document.getElementById('editCatId').value   = cat.id;
  document.getElementById('editCatName').value = cat.name;
  document.getElementById('editCatDesc').value = cat.description || '';
  new bootstrap.Modal(document.getElementById('editCatModal')).show();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
