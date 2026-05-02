</main><?php /* cierre <main> abierto en header_*.php */ ?>

<?php if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/')): ?>
  </div></div><?php /* cierre .bnc-admin-content y .bnc-admin-shell */ ?>
<?php else: ?>
  <footer class="bnc-footer mt-5">
    <div class="container py-4 text-center small text-muted">
      <div class="mb-2">
        <a href="https://depilasermexico.com" class="text-decoration-none text-muted">← Volver al sitio principal</a>
      </div>
      © <?= date('Y') ?> BellaNick Clinic · Sistema de citas
    </div>
  </footer>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/')): ?>
  <script src="<?= url('assets/js/admin.js') ?>"></script>
<?php else: ?>
  <script src="<?= url('assets/js/app.js') ?>"></script>
<?php endif; ?>
</body>
</html>
