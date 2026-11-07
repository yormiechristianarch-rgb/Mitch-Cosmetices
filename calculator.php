<?php
/**
 * MITCH COSMETICS SHOP — Built-in Calculator
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');

$pageTitle  = 'Calculator';
$activeMenu = 'calculator';
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-sm-8 col-md-5 col-lg-4">
    <div class="card">
      <div class="card-header text-center fw-bold">
        <i class="bi bi-calculator me-2"></i>Quick Calculator
      </div>
      <div class="card-body">
        <!-- Display -->
        <div class="calc-display mb-3" id="calcDisplay">0</div>

        <!-- Buttons -->
        <div class="row g-2">
          <!-- Row 1 -->
          <div class="col-3"><button class="calc-btn calc-btn-clear" onclick="Calc.press('C')">C</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-op" onclick="Calc.press('⌫')">⌫</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-op" onclick="Calc.press('%')">%</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-op" onclick="Calc.press('/')">÷</button></div>
          <!-- Row 2 -->
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('7')">7</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('8')">8</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('9')">9</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-op" onclick="Calc.press('*')">×</button></div>
          <!-- Row 3 -->
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('4')">4</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('5')">5</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('6')">6</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-op" onclick="Calc.press('-')">−</button></div>
          <!-- Row 4 -->
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('1')">1</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('2')">2</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('3')">3</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-op" onclick="Calc.press('+')">+</button></div>
          <!-- Row 5 -->
          <div class="col-6"><button class="calc-btn calc-btn-num" onclick="Calc.press('0')">0</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-num" onclick="Calc.press('.')">.</button></div>
          <div class="col-3"><button class="calc-btn calc-btn-eq" onclick="Calc.press('=')">=</button></div>
        </div>

        <!-- Keyboard hint -->
        <p class="text-muted text-center mt-3 mb-0" style="font-size:.75rem">
          <i class="bi bi-keyboard me-1"></i>Keyboard input supported
        </p>
      </div>
    </div>

    <!-- Quick discount calculator -->
    <div class="card mt-3">
      <div class="card-header fw-bold"><i class="bi bi-tag me-2"></i>Discount Calculator</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label mb-1" style="font-size:.8rem">Original Price ($)</label>
            <input type="number" id="dcOriginal" class="form-control" step="0.01" min="0" oninput="calcDiscount()">
          </div>
          <div class="col-6">
            <label class="form-label mb-1" style="font-size:.8rem">Discount (%)</label>
            <input type="number" id="dcPercent" class="form-control" step="1" min="0" max="100" oninput="calcDiscount()">
          </div>
          <div class="col-6">
            <label class="form-label mb-1" style="font-size:.8rem">Discount Amount</label>
            <input type="text" id="dcAmount" class="form-control bg-light" readonly>
          </div>
          <div class="col-6">
            <label class="form-label mb-1" style="font-size:.8rem">Final Price</label>
            <input type="text" id="dcFinal" class="form-control fw-bold" style="color:var(--primary)" readonly>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Keyboard support for calculator
document.addEventListener('keydown', function(e) {
  const allowed = '0123456789+-*/.%';
  if (allowed.includes(e.key)) Calc.press(e.key);
  else if (e.key === 'Enter' || e.key === '=') Calc.press('=');
  else if (e.key === 'Backspace') Calc.press('⌫');
  else if (e.key === 'Escape') Calc.press('C');
});

function calcDiscount() {
  const orig = parseFloat(document.getElementById('dcOriginal').value) || 0;
  const pct  = parseFloat(document.getElementById('dcPercent').value)  || 0;
  const amt  = (orig * pct / 100);
  const final = orig - amt;
  document.getElementById('dcAmount').value = '$' + amt.toFixed(2);
  document.getElementById('dcFinal').value  = '$' + final.toFixed(2);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
