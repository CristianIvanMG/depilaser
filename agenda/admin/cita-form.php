<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$pageTitle = 'Cita';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>
<div class="bnc-card">
  <div class="bnc-card-body">
    <h2 class="h5 fw-bold">Crear / editar cita</h2>
    <p class="text-muted">Pantalla pendiente de implementar (fase 2). Por ahora, las citas se crean desde el flujo cliente <a href="<?= url('agendar.php') ?>">/agendar.php</a>. Esta vista tendrá:</p>
    <ul>
      <li>Buscador de cliente (por email/teléfono) o creación rápida.</li>
      <li>Selector de sucursal + servicio + slot disponible.</li>
      <li>Editor de estado (programada → confirmada → atendida).</li>
      <li>Notas internas para el equipo.</li>
    </ul>
    <a href="<?= url('admin/') ?>" class="btn btn-bnc-outline">← Volver al dashboard</a>
  </div>
</div>
<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
