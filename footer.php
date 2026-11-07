</main><!-- /#main-content -->

<!-- Bootstrap 5 JS bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('#main-content .modal').forEach(function (modal) {
  document.body.appendChild(modal);
});
</script>
<!-- App JS -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraJs)): echo $extraJs; endif; ?>

<!-- Page-enter fade (very subtle, InfinityFree-safe — no framework) -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  var mc = document.getElementById('main-content');
  if (mc) {
    mc.style.opacity    = '0';
    mc.style.transform  = 'translateY(6px)';
    mc.style.transition = 'opacity .28s ease, transform .28s ease';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        mc.style.opacity   = '1';
        mc.style.transform = 'translateY(0)';
      });
    });
  }
});
</script>
</body>
</html>
