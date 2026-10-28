  </div>

  <footer class="adm-bottom">
    <span>&copy; <?= date('Y') ?> <?= e(setting('site_name', 'Asviora Games')) ?> &mdash; admin panel</span>
    <span>PHP <?= PHP_VERSION ?></span>
  </footer>
</div>

<script src="<?= asset('assets/js/main.js') ?>" defer></script>
<script>
(function () {
  var b  = document.getElementById('admBurger');
  var s  = document.getElementById('admSidebar');
  var bd = document.getElementById('admBackdrop');
  if (!b || !s) return;

  if (bd) bd.hidden = false;

  function setOpen(open) {
    s.classList.toggle('open', open);
    b.classList.toggle('open', open);
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (bd) bd.classList.toggle('show', open);
    document.body.classList.toggle('adm-locked', open);
  }

  b.addEventListener('click', function (ev) {
    ev.stopPropagation();
    setOpen(!s.classList.contains('open'));
  });

  if (bd) bd.addEventListener('click', function () { setOpen(false); });

  // tapping a menu link closes the drawer
  s.addEventListener('click', function (ev) {
    if (window.innerWidth <= 1024 && ev.target.closest('a')) setOpen(false);
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') setOpen(false);
  });

  // never leave the drawer stuck open when resizing back to desktop
  var rz;
  window.addEventListener('resize', function () {
    clearTimeout(rz);
    rz = setTimeout(function () {
      if (window.innerWidth > 1024) setOpen(false);
    }, 150);
  });
})();
</script>
<?= $admin_foot ?? '' ?>
</body>
</html>
