<?php
/**
 * MITCH COSMETICS SHOP
 * Global Helper Functions
 * Fix: getDashboardStats() now uses a PDO bound parameter for the threshold
 *      instead of interpolating a PHP variable directly into the SQL string.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

// ── Payment Method Validation ──────────────────────────────────────────────
/**
 * Enforce cash-only payment across the entire system.
 * Any submitted value other than 'cash' is silently overridden to 'cash'.
 * Prevents tampered forms from injecting mobile_money / bank_transfer / cheque.
 *
 * @param  string $submitted  Value received from $_POST or any user input.
 * @return string             Always returns 'cash'.
 */
function validatePaymentMethod(string $submitted = ''): string {
    // Only 'cash' is a valid payment method — everything else is rejected.
    return 'cash';
}

// ── Money ──────────────────────────────────────────────────────────────────
function money(float $amount): string {
    return APP_CURRENCY . number_format($amount, 2);
}

/** Resolve a stored product image only when it exists and is a valid image. */
function productImageFile(?string $filename): ?array {
    $filename = trim((string)$filename);
    if ($filename === '' || basename(str_replace('\\', '/', $filename)) !== $filename) {
        return null;
    }

    $path = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return null;
    }

    $info = new finfo(FILEINFO_MIME_TYPE);
    $mime = $info->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        return null;
    }

    return ['path' => $path, 'mime' => $mime, 'filename' => $filename];
}

function productImageUrl(?string $filename): ?string {
    $image = productImageFile($filename);
    if ($image === null) {
        return null;
    }

    $version = filemtime($image['path']);
    return BASE_URL . '/product-image.php?image=' . rawurlencode($image['filename'])
         . '&v=' . ($version !== false ? $version : time());
}

// ── SKU / Barcode ──────────────────────────────────────────────────────────
function generateSKU(string $category = 'PROD'): string {
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $category), 0, 3));
    if ($prefix === '') $prefix = 'SKU';
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
}

// ── File Upload ────────────────────────────────────────────────────────────
function uploadFile(array $file, string $destination, array $allowedMimes, int $maxBytes = 5000000): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error (code ' . $file['error'] . ')'];
    }
    if ($file['size'] > $maxBytes) {
        return ['success' => false, 'message' => 'File too large (max ' . round($maxBytes / 1000000, 1) . ' MB)'];
    }

    // Validate MIME type from file content, not the client-supplied type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes, true)) {
        return ['success' => false, 'message' => 'Invalid file type: ' . htmlspecialchars($mime)];
    }

    // Derive safe extension from MIME type — never trust client-supplied filename
    $mimeExtMap = [
        'image/jpeg' => 'jpg', 'image/png'  => 'png',
        'image/webp' => 'webp','image/gif'  => 'gif',
        'video/mp4'  => 'mp4', 'video/webm' => 'webm',
        'video/ogg'  => 'ogv',
    ];
    $ext      = isset($mimeExtMap[$mime]) ? $mimeExtMap[$mime] : strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ext      = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
    $filename = uniqid('mcs_', true) . '.' . $ext;
    $target   = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR . $filename;

    // Ensure destination directory exists
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['success' => false, 'message' => 'Could not save uploaded file. Check directory permissions.'];
    }

    return ['success' => true, 'filename' => $filename];
}

// ── Pagination ─────────────────────────────────────────────────────────────
function paginate(int $total, int $perPage, int $currentPage, string $url): array {
    $totalPages  = max(1, (int) ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => ($currentPage - 1) * $perPage,
        'url'          => $url,
    ];
}

function renderPagination(array $p): string {
    if ($p['total_pages'] <= 1) return '';
    $html = '<nav aria-label="Page navigation"><ul class="pagination pagination-sm justify-content-end">';

    $prevDisabled = $p['current_page'] <= 1 ? ' disabled' : '';
    $html .= '<li class="page-item' . $prevDisabled . '">'
           . '<a class="page-link" href="' . $p['url'] . '?page=' . ($p['current_page'] - 1) . '">‹</a></li>';

    for ($i = 1; $i <= $p['total_pages']; $i++) {
        $active = ($i === $p['current_page']) ? ' active' : '';
        $html  .= '<li class="page-item' . $active . '">'
                . '<a class="page-link" href="' . $p['url'] . '?page=' . $i . '">' . $i . '</a></li>';
    }

    $nextDisabled = $p['current_page'] >= $p['total_pages'] ? ' disabled' : '';
    $html .= '<li class="page-item' . $nextDisabled . '">'
           . '<a class="page-link" href="' . $p['url'] . '?page=' . ($p['current_page'] + 1) . '">›</a></li>';

    $html .= '</ul></nav>';
    return $html;
}

// ── Date Helpers ───────────────────────────────────────────────────────────
function niceDate(string $datetime): string {
    return date('M d, Y h:i A', strtotime($datetime));
}
function today(): string { return date('Y-m-d'); }
function nowDT(): string { return date('Y-m-d H:i:s'); }

// ── Dashboard Stats ────────────────────────────────────────────────────────
// FIX: every query now uses prepared statements / bound params — no
//      PHP variables or constants are ever interpolated into SQL.
function getDashboardStats(): array {
    $db        = getDB();
    $threshold = (int) APP_LOW_STOCK_THRESHOLD;   // cast to int, then bind
    $stats     = [];

    $stmt = $db->query("SELECT COUNT(*) FROM products");
    $stats['total_products'] = (int) $stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'customer'");
    $stats['total_customers'] = (int) $stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM orders");
    $stats['total_orders'] = (int) $stmt->fetchColumn();

    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales");
    $stats['total_sales'] = (float) $stmt->fetchColumn();

    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE DATE(created_at) = CURDATE()");
    $stats['daily_sales'] = (float) $stmt->fetchColumn();

    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales
                        WHERE MONTH(created_at) = MONTH(CURDATE())
                          AND YEAR(created_at)  = YEAR(CURDATE())");
    $stats['monthly_sales'] = (float) $stmt->fetchColumn();

    // Bound parameter — no inline variable interpolation
    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE quantity <= ? AND quantity > 0");
    $stmt->execute([$threshold]);
    $stats['low_stock'] = (int) $stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM products WHERE quantity = 0");
    $stats['out_of_stock'] = (int) $stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
    $stats['pending_orders'] = (int) $stmt->fetchColumn();

    return $stats;
}

// ── Monthly Sales Chart Data ───────────────────────────────────────────────
function getMonthlySalesData(): array {
    $db   = getDB();
    $year = (int) date('Y');
    $stmt = $db->prepare("
        SELECT MONTH(created_at) AS month,
               COALESCE(SUM(total_amount), 0) AS total
        FROM   sales
        WHERE  YEAR(created_at) = ?
        GROUP  BY MONTH(created_at)
        ORDER  BY month
    ");
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll();

    $data = array_fill(1, 12, 0.0);
    foreach ($rows as $r) {
        $data[(int) $r['month']] = (float) $r['total'];
    }
    return array_values($data);   // 0-indexed array for Chart.js
}

// ── Top Products ───────────────────────────────────────────────────────────
function getTopProducts(int $limit = 5): array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT p.name,
               COALESCE(SUM(oi.quantity), 0) AS sold
        FROM   products p
        LEFT JOIN order_items oi ON oi.product_id = p.id
        GROUP  BY p.id, p.name
        ORDER  BY sold DESC
        LIMIT  ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// ── Receipt Number ─────────────────────────────────────────────────────────
function generateReceiptNo(): string {
    return 'MCS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

// ── Status Badge ──────────────────────────────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'pending'    => 'warning',
        'processing' => 'info',
        'completed'  => 'success',
        'cancelled'  => 'danger',
        'active'     => 'success',
        'inactive'   => 'secondary',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst(htmlspecialchars($status)) . '</span>';
}

// ── Redirect ───────────────────────────────────────────────────────────────
function redirect(string $url): void {
    // Prevent header injection by stripping newlines
    header('Location: ' . str_replace(["\r", "\n"], '', $url));
    exit;
}

// ── Flash Alert HTML ───────────────────────────────────────────────────────
function flashAlert(): string {
    $flash = getFlash();
    if (!$flash) return '';
    $type  = $flash['type'] === 'error' ? 'danger' : htmlspecialchars($flash['type']);
    return '<div class="alert alert-' . $type . ' alert-dismissible fade show alert-auto-dismiss" role="alert">'
         . htmlspecialchars($flash['message'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
         . '</div>';
}

// ── Attendance Schedule Helpers ────────────────────────────────────────────

/**
 * Load the attendance setting for a given day-of-week (0=Sun … 6=Sat).
 * Returns the DB row as an array, or null if the table doesn't exist yet.
 */
function getAttendanceSchedule(int $dayOfWeek): ?array {
    $db = getDB();
    try {
        $stmt = $db->prepare(
            "SELECT * FROM attendance_settings WHERE day_of_week = ? LIMIT 1"
        );
        $stmt->execute([$dayOfWeek]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        // Table not yet created — treat as no restriction
        return null;
    }
}

/**
 * Check whether attendance is open right now for the current day/time.
 *
 * Returns an array:
 *   ['allowed'  => bool,
 *    'reason'   => string,   // human-readable message if not allowed
 *    'is_late'  => bool,     // true if check-in is allowed but after late_after
 *    'schedule' => array|null]
 */
function checkAttendanceAllowed(): array {
    $now     = new DateTime('now');
    $dow     = (int) $now->format('w');          // 0=Sun … 6=Sat
    $timeNow = $now->format('H:i:s');
    $sched   = getAttendanceSchedule($dow);

    // No settings row → allow freely (backward compatible)
    if (!$sched) {
        return ['allowed' => true, 'reason' => '', 'is_late' => false, 'schedule' => null];
    }

    // Day is disabled
    if (empty($sched['is_enabled'])) {
        $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        return [
            'allowed'  => false,
            'reason'   => 'Attendance is closed on ' . $dayNames[$dow] . '. '
                        . 'The office is not open today.',
            'is_late'  => false,
            'schedule' => $sched,
        ];
    }

    $start = $sched['checkin_start'];   // 'HH:MM:SS'
    $end   = $sched['checkin_end'];

    // Too early
    if ($timeNow < $start) {
        return [
            'allowed'  => false,
            'reason'   => 'Check-in is not open yet. '
                        . 'It opens at ' . date('h:i A', strtotime($start)) . '.',
            'is_late'  => false,
            'schedule' => $sched,
        ];
    }

    // Too late (window closed)
    if ($timeNow > $end) {
        return [
            'allowed'  => false,
            'reason'   => 'Check-in is now closed. '
                        . 'The window was ' . date('h:i A', strtotime($start))
                        . ' – ' . date('h:i A', strtotime($end)) . '.',
            'is_late'  => false,
            'schedule' => $sched,
        ];
    }

    // Within window — check if late
    $isLate = false;
    if (!empty($sched['late_after']) && $timeNow > $sched['late_after']) {
        $isLate = true;
    }

    return [
        'allowed'  => true,
        'reason'   => '',
        'is_late'  => $isLate,
        'schedule' => $sched,
    ];
}

// ── Site Settings (key-value store) ───────────────────────────────────────

/**
 * Auto-create site_settings table if missing, then return the value
 * for the given key. Returns $default if the key doesn't exist.
 */
function getSiteSetting(string $key, string $default = ''): string {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return ($row !== false) ? (string)$row['setting_value'] : $default;
    } catch (PDOException $e) {
        // Table doesn't exist yet — create it silently then return default
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `setting_key`   VARCHAR(100) NOT NULL,
                `setting_value` TEXT         DEFAULT NULL,
                `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $ce) { /* ignore */ }
        return $default;
    }
}

/**
 * Insert or update a site setting.
 */
function setSiteSetting(string $key, string $value): void {
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key`   VARCHAR(100) NOT NULL,
            `setting_value` TEXT         DEFAULT NULL,
            `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->prepare(
            "INSERT INTO site_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$key, $value]);
    } catch (PDOException $e) { /* ignore */ }
}


// ══════════════════════════════════════════════════════════════════════════
// INVENTORY HELPERS
// All inventory mutations go through these two functions so every change
// is atomic (transaction-wrapped), validated, and audited in one place.
// ══════════════════════════════════════════════════════════════════════════

/**
 * Get the current live quantity for a product directly from the DB.
 * Always use this instead of trusting a cached/passed value.
 *
 * @param  int $productId
 * @return int|null  null if product doesn't exist
 */
function getProductStock(int $productId): ?int {
    $db   = getDB();
    $stmt = $db->prepare("SELECT quantity FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $row  = $stmt->fetch();
    return ($row !== false) ? (int)$row['quantity'] : null;
}

/**
 * Apply a stock change and record it in stock_movements — atomically.
 * Fully resilient: works with or without stock_movements table,
 * works with MyISAM or InnoDB, works with or without FOR UPDATE support.
 */
function recordStockMovement(
    int     $productId,
    int     $qtyChange,
    string  $movementType,
    ?int    $userId      = null,
    string  $userName    = 'System',
    ?int    $referenceId = null,
    ?string $refType     = null,
    string  $notes       = '',
    ?PDO    $existingTx  = null
): array {

    $db    = $existingTx ?? getDB();
    $ownTx = ($existingTx === null);

    try {
        if ($ownTx) $db->beginTransaction();

        // ── Read product row (try FOR UPDATE, fall back to plain SELECT) ──
        $prod = null;
        try {
            $lockStmt = $db->prepare(
                "SELECT id, name, sku, quantity FROM products WHERE id = ? FOR UPDATE"
            );
            $lockStmt->execute([$productId]);
            $prod = $lockStmt->fetch();
        } catch (PDOException $lockEx) {
            // FOR UPDATE not supported (MyISAM or no active transaction)
            $plainStmt = $db->prepare(
                "SELECT id, name, sku, quantity FROM products WHERE id = ?"
            );
            $plainStmt->execute([$productId]);
            $prod = $plainStmt->fetch();
        }

        if (!$prod) {
            if ($ownTx) $db->rollBack();
            return ['success' => false, 'message' => 'Product not found.', 'qty_after' => 0];
        }

        $qtyBefore = (int)$prod['quantity'];
        $qtyAfter  = $qtyBefore + $qtyChange;

        // ── Never go below zero ────────────────────────────────────────────
        if ($qtyAfter < 0) {
            if ($ownTx) $db->rollBack();
            return [
                'success'   => false,
                'message'   => 'Insufficient stock. Only ' . $qtyBefore . ' unit(s) available.',
                'qty_after' => $qtyBefore,
            ];
        }

        // ── Apply the quantity change ──────────────────────────────────────
        $db->prepare("UPDATE products SET quantity = ? WHERE id = ?")
           ->execute([$qtyAfter, $productId]);

        // ── Write audit record (best-effort — skip if table missing) ───────
        try {
            $db->prepare("
                INSERT INTO stock_movements
                    (product_id, product_name, sku, qty_before, qty_change, qty_after,
                     movement_type, reference_id, reference_type, notes, user_id, user_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $productId, $prod['name'], $prod['sku'],
                $qtyBefore, $qtyChange, $qtyAfter,
                $movementType, $referenceId, $refType,
                $notes ?: null, $userId, $userName,
            ]);
        } catch (PDOException $auditEx) {
            // Table not yet created — log but don't fail the whole operation
            error_log('stock_movements insert skipped: ' . $auditEx->getMessage());
        }

        if ($ownTx) $db->commit();

        return ['success' => true, 'message' => 'Stock updated.', 'qty_after' => $qtyAfter];

    } catch (Exception $e) {
        if ($ownTx && $db->inTransaction()) $db->rollBack();
        return [
            'success'   => false,
            'message'   => 'Stock update failed: ' . $e->getMessage(),
            'qty_after' => isset($qtyBefore) ? $qtyBefore : 0,
        ];
    }
}

/**
 * Convenience: deduct stock for a list of order items.
 * Rolls back everything if any single item fails.
 * Returns ['success'=>bool, 'message'=>string].
 *
 * $items  = [['product_id'=>int, 'qty'=>int, 'name'=>string], ...]
 */
function deductStockForItems(
    array   $items,
    string  $movementType = 'order_placed',
    ?int    $userId       = null,
    string  $userName     = 'System',
    ?int    $referenceId  = null,
    ?string $refType      = 'order'
): array {

    $db = getDB();
    $db->beginTransaction();

    try {
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $qty = (int)$item['qty'];
            if ($qty <= 0) continue;

            $result = recordStockMovement(
                $pid,
                -$qty,          // negative = deduct
                $movementType,
                $userId,
                $userName,
                $referenceId,
                $refType,
                '',
                $db             // pass existing transaction
            );

            if (!$result['success']) {
                $db->rollBack();
                return [
                    'success' => false,
                    'message' => ($item['name'] ?? "Product #{$pid}") . ': ' . $result['message'],
                ];
            }
        }

        $db->commit();
        return ['success' => true, 'message' => 'Stock deducted.'];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => 'Stock deduction failed: ' . $e->getMessage()];
    }
}

/**
 * Convenience: restore stock for a list of order items (cancellation / return).
 */
function restoreStockForItems(
    array   $items,
    string  $movementType = 'cancelled_order',
    ?int    $userId       = null,
    string  $userName     = 'System',
    ?int    $referenceId  = null,
    ?string $refType      = 'order'
): array {

    $db = getDB();
    $db->beginTransaction();

    try {
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $qty = (int)$item['qty'];
            if ($qty <= 0) continue;

            $result = recordStockMovement(
                $pid,
                +$qty,          // positive = restore
                $movementType,
                $userId,
                $userName,
                $referenceId,
                $refType,
                '',
                $db
            );

            if (!$result['success']) {
                $db->rollBack();
                return ['success' => false, 'message' => $result['message']];
            }
        }

        $db->commit();
        return ['success' => true, 'message' => 'Stock restored.'];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => 'Stock restore failed: ' . $e->getMessage()];
    }
}

/**
 * Return the system low-stock threshold for a product.
 * Uses product-level override when set, otherwise APP_LOW_STOCK_THRESHOLD.
 */
function getLowStockThreshold(array $product): int {
    $override = (int)($product['low_stock_threshold'] ?? 0);
    return $override > 0 ? $override : (int)APP_LOW_STOCK_THRESHOLD;
}

/**
 * Return a stock-status string for a product row.
 * 'out_of_stock' | 'low_stock' | 'in_stock'
 */
function stockStatus(array $product): string {
    $qty       = (int)$product['quantity'];
    $threshold = getLowStockThreshold($product);
    if ($qty === 0)          return 'out_of_stock';
    if ($qty <= $threshold)  return 'low_stock';
    return 'in_stock';
}

/**
 * Render a coloured stock-status badge (HTML string).
 */
function stockBadge(array $product): string {
    $qty    = (int)$product['quantity'];
    $status = stockStatus($product);
    switch ($status) {
        case 'out_of_stock':
            return '<span class="badge bg-danger">Out of Stock</span>';
        case 'low_stock':
            return '<span class="badge bg-warning text-dark">' . $qty . ' — Low Stock ⚠</span>';
        default:
            return '<span class="badge bg-success">' . $qty . ' In Stock</span>';
    }
}
// ── End Inventory Helpers ──────────────────────────────────────────────────
