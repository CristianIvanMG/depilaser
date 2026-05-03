<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

WaitlistService::ensureSchema();

$admin = Auth::user();
$branches = Database::all('SELECT id, name FROM branches WHERE active = 1 ORDER BY display_order, name');
$services = Database::all('SELECT id, name FROM services WHERE active = 1 ORDER BY display_order, name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'cancel' && $id > 0) {
        if (WaitlistService::cancel($id, (int) $admin['id'])) {
            flash('success', 'Entrada cancelada correctamente.');
        } else {
            flash('warning', 'La entrada ya no está activa o no existe.');
        }
    }
    redirect('admin/lista-espera.php?' . http_build_query($_GET));
}

$filters = [
    'status' => in_array(($_GET['status'] ?? ''), ['waiting', 'promoted', 'cancelled'], true) ? $_GET['status'] : '',
    'branch_id' => max(0, (int) ($_GET['branch_id'] ?? 0)),
    'service_id' => max(0, (int) ($_GET['service_id'] ?? 0)),
];
$rows = WaitlistService::rows($filters);

$statusLabels = [
    'waiting' => 'En espera',
    'promoted' => 'Promovida',
    'cancelled' => 'Cancelada',
];

$pageTitle = 'Lista de espera';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a href="<?= url('admin/citas.php') ?>" class="btn btn-sm btn-bnc-outline"><i class="bi bi-list-check"></i> Citas</a>
  <a href="<?= url('admin/calendario.php') ?>" class="btn btn-sm btn-bnc-outline"><i class="bi bi-calendar3"></i> Calendario</a>
</div>

<div class="bnc-card mb-4">
  <div class="bnc-card-header">
    <h2 class="h6 fw-bold mb-0">Filtros de lista de espera</h2>
  </div>
  <div class="bnc-card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="bnc-label">Estado</label>
        <select name="status" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($statusLabels as $slug => $label): ?>
            <option value="<?= e($slug) ?>" <?= $filters['status'] === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="bnc-label">Sucursal</label>
        <select name="branch_id" class="form-select">
          <option value="0">Todas</option>
          <?php foreach ($branches as $branch): ?>
            <option value="<?= (int) $branch['id'] ?>" <?= (int) $filters['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="bnc-label">Servicio</label>
        <select name="service_id" class="form-select">
          <option value="0">Todos</option>
          <?php foreach ($services as $service): ?>
            <option value="<?= (int) $service['id'] ?>" <?= (int) $filters['service_id'] === (int) $service['id'] ? 'selected' : '' ?>><?= e($service['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1 d-grid">
        <button class="btn btn-bnc-primary" type="submit"><i class="bi bi-funnel"></i></button>
      </div>
    </form>
  </div>
</div>

<div class="bnc-card">
  <div class="bnc-card-header d-flex flex-wrap align-items-center gap-2">
    <h2 class="h6 fw-bold mb-0 me-auto">Clientes en lista de espera</h2>
    <span class="badge bg-secondary"><?= count($rows) ?> resultado(s)</span>
  </div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Servicio</th>
          <th>Sucursal</th>
          <th>Preferencia</th>
          <th>Estado</th>
          <th>Resultado</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No hay registros con esos filtros.</td></tr>
        <?php else: foreach ($rows as $row): ?>
          <tr>
            <td>
              <strong><?= e($row['client_name']) ?></strong><br>
              <small class="text-muted"><?= e($row['client_phone'] ?: $row['client_email']) ?></small>
            </td>
            <td>
              <?= e($row['service_name']) ?>
              <?php if (!empty($row['zone'])): ?><br><small class="text-muted">Zona: <?= e($row['zone']) ?></small><?php endif; ?>
            </td>
            <td><?= e($row['branch_name']) ?></td>
            <td>
              <?php if ($row['preferred_date_from']): ?>
                <?= e($row['preferred_date_from']) ?>
                <?php if ($row['preferred_date_to'] && $row['preferred_date_to'] !== $row['preferred_date_from']): ?>
                  al <?= e($row['preferred_date_to']) ?>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">Flexible</span>
              <?php endif; ?>
              <br><small class="text-muted">Alta: <?= e(fmt_dt_short($row['created_at'])) ?></small>
            </td>
            <td><span class="bnc-status <?= e($row['status']) ?>"><?= e($statusLabels[$row['status']] ?? $row['status']) ?></span></td>
            <td>
              <?php if ($row['promoted_appointment_id']): ?>
                <a href="<?= url('admin/cita-form.php?id=' . (int) $row['promoted_appointment_id']) ?>" class="fw-bold text-decoration-none">
                  <?= e($row['promoted_code'] ?: 'Ver cita') ?>
                </a><br>
                <small class="text-muted"><?= e(fmt_dt_short($row['promoted_start_at'])) ?></small>
              <?php else: ?>
                <span class="text-muted">Pendiente</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($row['status'] === 'waiting'): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Cancelar esta entrada de lista de espera?');">
                  <?= Csrf::input() ?>
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x-circle"></i></button>
                </form>
              <?php else: ?>
                <span class="text-muted small">Sin acciones</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
