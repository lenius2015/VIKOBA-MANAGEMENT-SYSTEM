  </div><!-- /content-area -->
</div><!-- /main-content -->

<!-- Small sticky footer -->
<footer class="text-center py-2" style="position:fixed;left:var(--sidebar-w);right:0;bottom:0;z-index:50;">
  <div class="container d-flex justify-content-between align-items-center" style="max-width:1100px;">
    <div class="text-muted fs-12">&copy; <?= date('Y') ?> <?= APP_NAME ?> — Built with care.</div>
    <div class="d-flex align-items-center gap-2">
      <span class="text-muted fs-11">v2.0</span>
      <button id="themeToggle" class="btn btn-sm">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> Dark
      </button>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/public/js/app.js?v=2.0"></script>
</body>
</html>
