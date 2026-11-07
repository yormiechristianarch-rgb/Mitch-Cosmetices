/* ============================================================
   MITCH COSMETICS SHOP — Main JavaScript v2
   All original functionality preserved + UX improvements.
   ============================================================ */

'use strict';

/* ── DOM Ready ────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {

  /* ── Sidebar toggle ─────────────────────────────────── */
  const toggleBtn = document.getElementById('sidebarToggle');
  const sidebar   = document.getElementById('sidebar');
  const overlay   = document.getElementById('sidebarOverlay');

  function openSidebar() {
    sidebar.classList.add('show');
    if (overlay) { overlay.style.display = 'block'; requestAnimationFrame(() => overlay.classList.add('show')); }
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('show');
    if (overlay) {
      overlay.classList.remove('show');
      // Wait for fade-out before hiding
      setTimeout(() => { overlay.style.display = 'none'; }, 250);
    }
    document.body.style.overflow = '';
  }

  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', () => {
      sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
    });
  }
  if (overlay) {
    overlay.addEventListener('click', closeSidebar);
  }
  // Close sidebar on ESC key
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && sidebar && sidebar.classList.contains('show')) closeSidebar();
  });

  /* ── Active nav link ────────────────────────────────── */
  const currentPath = window.location.pathname;
  document.querySelectorAll('#sidebar .nav-link').forEach(link => {
    const href = link.getAttribute('href');
    if (!href || href === '#') return;
    // Strip query string from href before comparing
    const hrefPath = href.split('?')[0];
    // Match the path portion only (works on localhost subfolders AND live root)
    try {
      const hrefUrl = new URL(href, window.location.origin);
      if (hrefUrl.pathname === window.location.pathname) {
        link.classList.add('active');
      }
    } catch (e) {
      // Fallback: simple includes check
      if (currentPath.includes(hrefPath.replace(/^https?:\/\/[^/]+/, ''))) {
        link.classList.add('active');
      }
    }
  });

  /* ── Auto-dismiss alerts ────────────────────────────── */
  document.querySelectorAll('.alert-auto-dismiss').forEach(el => {
    // Fade out smoothly
    setTimeout(() => {
      el.style.transition = 'opacity .4s ease, max-height .4s ease, margin .4s ease, padding .4s ease';
      el.style.opacity    = '0';
      el.style.maxHeight  = '0';
      el.style.margin     = '0';
      el.style.padding    = '0';
      el.style.overflow   = 'hidden';
      setTimeout(() => el.remove(), 420);
    }, 4000);
  });

  /* ── Table search init ──────────────────────────────── */
  ['productsTable','ordersTable','customersTable','staffTable','salesTable','attendanceTable']
    .forEach(initDataTable);

  /* ── Tooltip init (Bootstrap) ───────────────────────── */
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
  });

  /* ── Number counter animation (stat cards) ──────────── */
  if (window.IntersectionObserver) {
    const statObs = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          animateStatCard(entry.target);
          statObs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.2 });

    document.querySelectorAll('.stat-card .stat-value').forEach(el => {
      // Only animate plain number values
      const raw = el.textContent.trim();
      if (/^[\$]?[\d,\.]+[\+\%\★]?$/.test(raw)) {
        el.dataset.target = raw;
        statObs.observe(el.closest('.stat-card'));
      }
    });
  }

  /* ── Reveal on scroll ───────────────────────────────── */
  if (window.IntersectionObserver) {
    const revealObs = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.classList.add('visible');
          revealObs.unobserve(e.target);
        }
      });
    }, { threshold: 0.08 });
    document.querySelectorAll('.reveal').forEach(el => revealObs.observe(el));
  }

}); // END DOMContentLoaded


/* ── Live Clock ───────────────────────────────────────────────────────────── */
(function () {
  function updateClock() {
    const el = document.getElementById('liveClock');
    if (!el) return;
    const now = new Date();
    el.textContent = now.toLocaleTimeString('en-US', {
      hour:   '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  }
  setInterval(updateClock, 1000);
  updateClock();
})();


/* ── Stat card number animation ───────────────────────────────────────────── */
function animateStatCard(card) {
  const el = card.querySelector('.stat-value');
  if (!el || !el.dataset.target) return;

  const raw    = el.dataset.target;
  const prefix = raw.startsWith('$') ? '$' : '';
  const suffix = raw.endsWith('+') ? '+' : raw.endsWith('%') ? '%' : raw.endsWith('★') ? '★' : '';
  const numStr = raw.replace(/[$+%★,]/g, '');
  const end    = parseFloat(numStr);
  if (isNaN(end)) return;

  const duration = 800;
  const start    = performance.now();
  const isInt    = Number.isInteger(end);

  function step(now) {
    const elapsed  = now - start;
    const progress = Math.min(elapsed / duration, 1);
    const eased    = 1 - Math.pow(1 - progress, 3);  // ease-out-cubic
    const current  = end * eased;
    el.textContent = prefix + (isInt ? Math.round(current).toLocaleString() : current.toFixed(2)) + suffix;
    if (progress < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}


/* ── Calculator ───────────────────────────────────────────────────────────── */
const Calc = {
  display:   '',
  result:    '',
  hasResult: false,

  press(val) {
    const disp = document.getElementById('calcDisplay');
    if (!disp) return;

    if (val === 'C') {
      this.display = ''; this.result = ''; this.hasResult = false;
    } else if (val === '⌫') {
      this.display = this.display.slice(0, -1);
    } else if (val === '=') {
      try {
        const expr    = this.display.replace(/[^0-9+\-*/.()%]/g, '');
        this.result   = String(Function('"use strict"; return (' + expr + ')')());
        this.display  = this.result;
        this.hasResult = true;
      } catch (e) {
        this.display = 'Error';
      }
    } else if (val === '%') {
      try {
        const n = parseFloat(this.display);
        if (!isNaN(n)) this.display = String(n / 100);
      } catch (e) {}
    } else {
      if (this.hasResult && /[0-9.]/.test(val)) {
        this.display = ''; this.hasResult = false;
      }
      this.display += val;
    }
    disp.textContent = this.display || '0';
  }
};


/* ── Confirm Delete ───────────────────────────────────────────────────────── */
function confirmDelete(url, msg) {
  if (confirm(msg || 'Are you sure you want to delete this item? This cannot be undone.')) {
    window.location.href = url;
  }
}


/* ── POS Product Search ───────────────────────────────────────────────────── */
function posSearch(query) {
  const q     = query.toLowerCase().trim();
  const cols  = document.querySelectorAll('.pos-product-col');
  let   shown = 0;

  cols.forEach(col => {
    const card = col.querySelector('.pos-product-card');
    const name = (card && card.dataset.name) ? card.dataset.name.toLowerCase() : '';
    const sku  = (card && card.dataset.sku)  ? card.dataset.sku.toLowerCase()  : '';
    const hit  = !q || name.includes(q) || sku.includes(q);
    col.style.display = hit ? '' : 'none';
    if (hit) shown++;
  });

  // Show empty state if nothing matches
  const emptyEl = document.getElementById('posEmptyState');
  if (emptyEl) emptyEl.style.display = shown === 0 ? '' : 'none';
}


/* ── POS Cart ─────────────────────────────────────────────────────────────── */
const Cart = {
  items: [],

  add(id, name, sku, price, stock) {
    price = parseFloat(price);
    const existing = this.items.find(i => i.id == id);
    if (existing) {
      if (existing.qty < stock) {
        existing.qty++;
      } else {
        // Visual feedback instead of alert
        const card = document.querySelector(`.pos-product-card[onclick*="'${id}'"], .pos-product-card[onclick*='"${id}"']`);
        if (card) {
          card.style.outline = '2px solid #dc2626';
          setTimeout(() => { card.style.outline = ''; }, 800);
        }
        return;
      }
    } else {
      this.items.push({ id, name, sku, price, qty: 1, stock: parseInt(stock), discount: 0 });
    }
    this.render();
    this._flashCartBadge();
  },

  remove(id) {
    this.items = this.items.filter(i => i.id != id);
    this.render();
  },

  changeQty(id, delta) {
    const item = this.items.find(i => i.id == id);
    if (!item) return;
    item.qty = Math.max(0, Math.min(item.stock, item.qty + delta));
    if (item.qty === 0) { this.remove(id); return; }
    this.render();
  },

  subtotal() {
    return this.items.reduce((s, i) => s + (i.price * i.qty - (i.discount || 0)), 0);
  },

  render() {
    const container = document.getElementById('cartItems');
    const subEl     = document.getElementById('cartSubtotal');
    const totEl     = document.getElementById('cartTotal');
    const discEl    = document.getElementById('discountInput');
    if (!container) return;

    if (this.items.length === 0) {
      container.innerHTML = `
        <div class="text-center py-5 text-muted">
          <i class="bi bi-cart3" style="font-size:2.2rem;opacity:.35"></i>
          <div class="mt-2" style="font-size:.82rem">Cart is empty</div>
        </div>`;
    } else {
      container.innerHTML = this.items.map(item => `
        <div class="cart-item" data-id="${item.id}">
          <div style="flex:1;min-width:0">
            <div class="cart-item-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${item.name}</div>
            <small class="text-muted font-monospace" style="font-size:.68rem">${item.sku}</small>
          </div>
          <div class="cart-item-qty">
            <button class="cart-qty-btn" onclick="Cart.changeQty(${item.id},-1)" title="Remove one">−</button>
            <span style="min-width:24px;text-align:center;font-weight:700;font-size:.88rem">${item.qty}</span>
            <button class="cart-qty-btn" onclick="Cart.changeQty(${item.id},1)" title="Add one">+</button>
          </div>
          <div class="cart-item-total">$${(item.price * item.qty).toFixed(2)}</div>
          <button class="btn-remove-item" onclick="Cart.remove(${item.id})" title="Remove item">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
      `).join('');
    }

    const disc = parseFloat(discEl ? discEl.value : 0) || 0;
    const sub  = this.subtotal();
    const tot  = Math.max(0, sub - disc);
    if (subEl) subEl.textContent = '$' + sub.toFixed(2);
    if (totEl) totEl.textContent = '$' + tot.toFixed(2);

    this.syncForm();
  },

  syncForm() {
    const field = document.getElementById('cartDataField');
    if (field) field.value = JSON.stringify(this.items);
  },

  clear() {
    this.items = [];
    this.render();
  },

  _flashCartBadge() {
    const badge = document.getElementById('cartCountBadge');
    if (!badge) return;
    badge.textContent = this.items.length;
    badge.style.display = this.items.length ? '' : 'none';
    badge.animate
      ? badge.animate([{ transform: 'scale(1.5)' }, { transform: 'scale(1)' }], { duration: 280, easing: 'ease-out' })
      : null;
  }
};


/* ── Image Preview ────────────────────────────────────────────────────────── */
function previewImage(input, previewId) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      const img = document.getElementById(previewId);
      if (img) {
        img.src           = e.target.result;
        img.style.display = 'block';
        img.style.opacity = '0';
        img.style.transition = 'opacity .3s ease';
        requestAnimationFrame(() => { img.style.opacity = '1'; });
      }
    };
    reader.readAsDataURL(input.files[0]);
  }
}


/* ── Data Table Search ────────────────────────────────────────────────────── */
function initDataTable(tableId) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const searchInput = document.getElementById(tableId + 'Search');
  if (!searchInput) return;

  searchInput.addEventListener('input', function () {
    const q    = this.value.toLowerCase();
    let   rows = 0;
    table.querySelectorAll('tbody tr').forEach(row => {
      const match = row.textContent.toLowerCase().includes(q);
      row.style.display = match ? '' : 'none';
      if (match) rows++;
    });
    // Optional: show row count
    const countEl = document.getElementById(tableId + 'Count');
    if (countEl) countEl.textContent = rows;
  });
}


/* ── Change / Tender Calculation ──────────────────────────────────────────── */
function calcChange() {
  const totEl      = document.getElementById('checkoutTotal') || document.getElementById('cartTotal');
  const total      = parseFloat(totEl ? totEl.textContent.replace('$', '') : 0) || 0;
  const tendered   = parseFloat(document.getElementById('amountTendered')?.value) || 0;
  const change     = tendered - total;
  const changeEl   = document.getElementById('changeDue');
  if (!changeEl) return;
  changeEl.textContent = '$' + Math.max(0, change).toFixed(2);
  changeEl.style.color = change < 0 ? 'var(--danger)' : 'var(--success)';
}


/* ── Print Helper ─────────────────────────────────────────────────────────── */
function printSection(id) {
  const content = document.getElementById(id);
  if (!content) return;
  const win = window.open('', '_blank', 'width=460,height=640,menubar=no,toolbar=no');
  if (!win) return;
  win.document.write(`<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Print</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'Courier New', monospace; font-size: 12px; padding: 16px; margin: 0; color: #000; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 3px 4px; vertical-align: top; }
    hr { border: none; border-top: 1px dashed #888; margin: 8px 0; }
    .text-center { text-align: center; }
    .text-right  { text-align: right; }
    .fw-bold, strong { font-weight: bold; }
    .receipt-total { font-size: 14px; font-weight: bold; }
    .sig-line { border-top: 1px solid #000; margin-top: 28px; padding-top: 3px; font-size: 10px; text-align: center; }
    .signature-line { display: flex; justify-content: space-between; margin-top: 16px; }
    .sig-block { text-align: center; width: 44%; }
    .receipt-footer { text-align: center; font-size: 10px; color: #555; margin-top: 14px; }
    .receipt-divider { border-top: 1px dashed #aaa; margin: 8px 0; }
    .receipt-header { text-align: center; margin-bottom: 12px; }
    @media print {
      @page { size: auto; margin: 10mm; }
      body { width: 340px; max-width: 100%; margin: 0 auto; padding: 0; }
    }
  </style>
</head>
<body>${content.innerHTML}</body>
</html>`);
  win.document.close();
  win.focus();
  setTimeout(() => { win.print(); win.close(); }, 450);
}
