<?php
/**
 * MITCH COSMETICS SHOP — Receipt Page
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');

if (empty($_SESSION['last_receipt'])) {
    redirect(BASE_URL . '/staff/pos.php');
}

// Read the receipt data then immediately clear from session so navigating
// back to this URL shows POS instead of the previous sale's receipt.
$r = $_SESSION['last_receipt'];
unset($_SESSION['last_receipt']);

$pageTitle  = 'Receipt — ' . $r['receipt_no'];
$activeMenu = 'pos';

include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <a href="<?= BASE_URL ?>/staff/pos.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>New Sale
      </a>
      <button class="btn btn-primary" onclick="printSection('receiptContent')">
        <i class="bi bi-printer me-2"></i>Print Receipt
      </button>
    </div>

    <div class="card">
      <div class="card-body p-2">
        <div id="receiptContent">
          <div class="receipt-wrapper">
            <!-- Header -->
            <div class="receipt-header">
              <h4><?= APP_NAME ?></h4>
              <div><?= APP_ADDRESS ?></div>
              <div><?= APP_PHONE ?></div>
              <?php if (APP_EMAIL): ?><div><?= APP_EMAIL ?></div><?php endif; ?>
            </div>

            <div class="receipt-divider"></div>

            <table class="receipt-table">
              <tr><td><strong>Receipt #:</strong></td><td style="text-align:right"><?= htmlspecialchars($r['receipt_no']) ?></td></tr>
              <tr><td><strong>Date:</strong></td><td style="text-align:right"><?= date('M d, Y h:i A', strtotime($r['date'])) ?></td></tr>
              <tr><td><strong>Customer:</strong></td><td style="text-align:right"><?= htmlspecialchars($r['customer_name']) ?></td></tr>
              <tr><td><strong>Cashier:</strong></td><td style="text-align:right"><?= htmlspecialchars($r['staff_name']) ?></td></tr>
              <tr><td><strong>Payment:</strong></td><td style="text-align:right"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$r['payment_method']))) ?></td></tr>
            </table>

            <div class="receipt-divider"></div>

            <!-- Items -->
            <table class="receipt-table">
              <tr style="border-bottom:1px dashed #888">
                <td><strong>Item</strong></td>
                <td style="text-align:center"><strong>Qty</strong></td>
                <td style="text-align:right"><strong>Price</strong></td>
                <td style="text-align:right"><strong>Total</strong></td>
              </tr>
              <?php foreach ($r['items'] as $item): ?>
              <tr>
                <td style="max-width:100px;word-break:break-word"><?= htmlspecialchars($item['name']) ?></td>
                <td style="text-align:center"><?= (int)$item['qty'] ?></td>
                <td style="text-align:right"><?= money((float)$item['price']) ?></td>
                <td style="text-align:right"><?= money((float)$item['price'] * (int)$item['qty']) ?></td>
              </tr>
              <?php endforeach; ?>
            </table>

            <div class="receipt-divider"></div>

            <!-- Totals -->
            <table class="receipt-table">
              <tr>
                <td>Subtotal:</td>
                <td style="text-align:right"><?= money($r['subtotal']) ?></td>
              </tr>
              <?php if ($r['discount'] > 0): ?>
              <tr>
                <td>Discount:</td>
                <td style="text-align:right; color:#c0007a">- <?= money($r['discount']) ?></td>
              </tr>
              <?php endif; ?>
              <tr class="receipt-total">
                <td><strong>GRAND TOTAL:</strong></td>
                <td style="text-align:right"><strong><?= money($r['grand_total']) ?></strong></td>
              </tr>
              <?php if ($r['payment_method'] === 'cash'): ?>
              <tr>
                <td>Amount Tendered:</td>
                <td style="text-align:right"><?= money($r['amount_tendered']) ?></td>
              </tr>
              <tr>
                <td>Change Due:</td>
                <td style="text-align:right"><?= money($r['change_due']) ?></td>
              </tr>
              <?php endif; ?>
            </table>

            <div class="receipt-divider"></div>

            <!-- Signatures -->
            <div class="signature-line">
              <div class="sig-block">
                <div class="sig-line">Seller Signature</div>
              </div>
              <div class="sig-block">
                <div class="sig-line">Customer Signature</div>
              </div>
            </div>

            <!-- Footer -->
            <div class="receipt-footer">
              <div class="receipt-divider"></div>
              <strong>Thank you for shopping at <?= APP_NAME ?>!</strong><br>
              We appreciate your business.<br>
              <em>Items sold are not returnable without receipt.</em>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
