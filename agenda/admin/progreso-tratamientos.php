<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

TreatmentProgressService::ensureSchema();

$branches = Database::all('SELECT id, name FROM branches WHERE active = 1 ORDER BY display_order, name');
$filters = [
    'level' => isset(TreatmentProgressService::LEVELS[$_GET['level'] ?? '']) ? $_GET['level'] : '',
    'branch_id' => max(0, (int) ($_GET['branch_id'] ?? 0)),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
];
$rows = TreatmentProgressService::adminRows($filters);

$pageTitle = 'Seguimiento de tratamientos';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a href="<?= url('admin/citas.php') ?>" class="btn btn-sm btn-bnc-outline"><i class="bi bi-list-check"></i> Citas</a>
  <a href="<?= url('admin/reportes.php') ?>" class="btn btn-sm btn-bnc-outline"><i class="bi bi-graph-up-arrow"></i> Reportes</a>
</div>

<div class="bnc-card mb-4">
  <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Filtros de seguimiento clínico</h2></div>
  <div class="bnc-card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-2">
        <label class="bnc-label">Desde</label>
        <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>">
      </div>
      <div class="col-md-2">
        <label class="bnc-label">Hasta</label>
        <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>">
      </div>
      <div class="col-md-3">
        <label class="bnc-label">Sucursal</label>
        <select name="branch_id" class="form-select">
          <option value="0">Todas</option>
          <?php foreach ($branches as $branch): ?>
            <option value="<?= (int) $branch['id'] ?>" <?= (int) $filters['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="bnc-label">Nivel de avance</label>
        <select name="level" class="form-select">
          <option value="">Todos</option>
          <?php foreach (TreatmentProgressService::LEVELS as $slug => $label): ?>
            <option value="<?= e($slug) ?>" <?= $filters['level'] === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-bnc-primary" type="submit"><i class="bi bi-funnel"></i></button>
        <a class="btn btn-bnc-outline" href="<?= url('admin/progreso-tratamientos.php') ?>">Limpiar</a>
      </div>
    </form>
  </div>
</div>

<div class="bnc-card">
  <div class="bnc-card-header d-flex flex-wrap align-items-center gap-2">
    <h2 class="h6 fw-bold mb-0 me-auto">Avances registrados</h2>
    <span class="badge bg-secondary"><?= count($rows) ?> resultado(s)</span>
  </div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Cita</th>
          <th>Sucursal</th>
          <th>Nivel</th>
          <th>Fecha de registro</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No hay avances registrados con esos filtros.</td></tr>
        <?php else: foreach ($rows as $row): ?>
          <tr>
            <td>
              <strong><?= e($row['client_name']) ?></strong><br>
              <small class="text-muted"><?= e($row['client_phone'] ?: $row['client_email']) ?></small>
            </td>
            <td>
              <a href="<?= url('admin/cita-form.php?id=' . (int) $row['appointment_id']) ?>" class="fw-bold text-decoration-none"><?= e($row['code']) ?></a><br>
              <small class="text-muted"><?= e($row['service_name']) ?> · <?= e(fmt_dt_short($row['start_at'])) ?></small>
            </td>
            <td><?= e($row['branch_name']) ?></td>
            <td><span class="bnc-progress-level <?= e($row['progress_level']) ?>"><?= e(TreatmentProgressService::LEVELS[$row['progress_level']] ?? $row['progress_level']) ?></span></td>
            <td><?= e(fmt_dt_short($row['registered_at'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
