<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$branches = Database::all('SELECT id, name FROM branches WHERE active=1 ORDER BY display_order, name');
$branchId = (int) ($_GET['branch_id'] ?? $_POST['branch_id'] ?? ($branches[0]['id'] ?? 0));
$errors = [];
$weekdays = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_weekly') {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::exec('DELETE FROM availability WHERE branch_id=?', [$branchId]);
            foreach ($_POST['days'] ?? [] as $weekday => $day) {
                $weekday = (int) $weekday;
                if (empty($day['active'])) continue;
                $start = trim($day['start'] ?? '');
                $end = trim($day['end'] ?? '');
                if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $end <= $start) {
                    throw new RuntimeException('Horario inválido en ' . ($GLOBALS['weekdays'][$weekday] ?? 'día'));
                }
                Database::exec(
                    'INSERT INTO availability (branch_id, weekday, time_start, time_end, active) VALUES (?, ?, ?, ?, 1)',
                    [$branchId, $weekday, $start . ':00', $end . ':00']
                );
            }
            $pdo->commit();
            Auth::audit('availability_update', 'branch', $branchId);
            flash('success', 'Horario semanal actualizado correctamente.');
            redirect('admin/horarios.php?branch_id=' . $branchId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors['_'] = $e->getMessage();
        }
    }

    if ($action === 'save_exception') {
        $date = trim($_POST['date'] ?? '');
        $type = $_POST['type'] ?? 'closed';
        $start = trim($_POST['time_start'] ?? '');
        $end = trim($_POST['time_end'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['_'] = 'Selecciona una fecha válida.';
        } elseif (!in_array($type, ['closed','custom'], true)) {
            $errors['_'] = 'Selecciona el tipo de excepción.';
        } elseif ($type === 'custom' && (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $end <= $start)) {
            $errors['_'] = 'El horario especial debe tener hora inicial y final válidas.';
        } else {
            Database::exec(
                "INSERT INTO availability_exceptions (branch_id, date, type, time_start, time_end, reason)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE type=VALUES(type), time_start=VALUES(time_start), time_end=VALUES(time_end), reason=VALUES(reason)",
                [$branchId, $date, $type, $type === 'custom' ? $start . ':00' : null, $type === 'custom' ? $end . ':00' : null, $reason ?: null]
            );
            Auth::audit('availability_exception_save', 'branch', $branchId, ['date' => $date]);
            flash('success', 'Excepción guardada correctamente.');
            redirect('admin/horarios.php?branch_id=' . $branchId);
        }
    }

    if ($action === 'delete_exception') {
        $exceptionId = (int) ($_POST['exception_id'] ?? 0);
        Database::exec('DELETE FROM availability_exceptions WHERE id=? AND branch_id=?', [$exceptionId, $branchId]);
        Auth::audit('availability_exception_delete', 'branch', $branchId, ['exception_id' => $exceptionId]);
        flash('success', 'Excepción eliminada correctamente.');
        redirect('admin/horarios.php?branch_id=' . $branchId);
    }
}

$weeklyRows = Database::all('SELECT weekday, time_start, time_end, active FROM availability WHERE branch_id=? ORDER BY weekday, time_start', [$branchId]);
$weekly = [];
foreach ($weeklyRows as $row) {
    $weekly[(int) $row['weekday']] = $row;
}
$exceptions = Database::all(
    'SELECT * FROM availability_exceptions WHERE branch_id=? AND date >= CURDATE() ORDER BY date LIMIT 100',
    [$branchId]
);

$pageTitle = 'Horarios';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<?php if (!empty($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php endif; ?>

<div class="bnc-card mb-4">
  <div class="bnc-card-header d-flex flex-wrap align-items-center gap-2">
    <h2 class="h6 fw-bold mb-0 me-auto">Sucursal</h2>
    <form method="GET" class="d-flex gap-2">
      <select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($branches as $branch): ?>
          <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-7">
    <form method="POST" class="bnc-card">
      <?= Csrf::input() ?>
      <input type="hidden" name="action" value="save_weekly">
      <input type="hidden" name="branch_id" value="<?= (int) $branchId ?>">
      <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Horario semanal</h2></div>
      <div class="bnc-card-body">
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr><th>Día</th><th>Activo</th><th>Apertura</th><th>Cierre</th></tr></thead>
            <tbody>
              <?php foreach ($weekdays as $i => $label): $row = $weekly[$i] ?? null; ?>
                <tr>
                  <td class="fw-bold"><?= e($label) ?></td>
                  <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="days[<?= $i ?>][active]" <?= $row ? 'checked' : '' ?>></div></td>
                  <td><input type="time" name="days[<?= $i ?>][start]" class="form-control" value="<?= e($row ? substr($row['time_start'], 0, 5) : ($i === 6 ? '10:00' : '09:00')) ?>"></td>
                  <td><input type="time" name="days[<?= $i ?>][end]" class="form-control" value="<?= e($row ? substr($row['time_end'], 0, 5) : ($i === 6 ? '18:00' : '20:00')) ?>"></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="bnc-card-body border-top"><button class="btn btn-bnc-primary" type="submit"><i class="bi bi-check2-circle"></i> Guardar horario semanal</button></div>
    </form>
  </div>

  <div class="col-xl-5">
    <div class="bnc-card mb-4">
      <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Días no laborables y horarios especiales</h2></div>
      <div class="bnc-card-body">
        <form method="POST" class="row g-3">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="save_exception">
          <input type="hidden" name="branch_id" value="<?= (int) $branchId ?>">
          <div class="col-md-6"><label class="bnc-label">Fecha</label><input type="date" name="date" class="form-control" min="<?= e(date('Y-m-d')) ?>" required></div>
          <div class="col-md-6"><label class="bnc-label">Tipo</label><select name="type" class="form-select" id="exceptionType"><option value="closed">Cerrado</option><option value="custom">Horario especial</option></select></div>
          <div class="col-md-6"><label class="bnc-label">Apertura especial</label><input type="time" name="time_start" class="form-control"></div>
          <div class="col-md-6"><label class="bnc-label">Cierre especial</label><input type="time" name="time_end" class="form-control"></div>
          <div class="col-12"><label class="bnc-label">Motivo</label><input name="reason" class="form-control" maxlength="255" placeholder="Ej. Festivo, mantenimiento, evento interno"></div>
          <div class="col-12"><button class="btn btn-bnc-primary" type="submit">Guardar excepción</button></div>
        </form>
      </div>
    </div>

    <div class="bnc-card">
      <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Próximas excepciones</h2></div>
      <div class="bnc-card-body">
        <?php if (!$exceptions): ?>
          <p class="text-muted small mb-0">No hay excepciones futuras para esta sucursal.</p>
        <?php else: foreach ($exceptions as $exception): ?>
          <div class="d-flex align-items-center gap-2 py-2 border-bottom">
            <div class="flex-grow-1">
              <strong><?= e(fmt_dt($exception['date'] . ' 00:00:00', false)) ?></strong>
              <div class="small text-muted">
                <?= $exception['type'] === 'closed' ? 'Cerrado' : 'Horario ' . e(substr($exception['time_start'], 0, 5)) . ' - ' . e(substr($exception['time_end'], 0, 5)) ?>
                <?= $exception['reason'] ? ' · ' . e($exception['reason']) : '' ?>
              </div>
            </div>
            <form method="POST">
              <?= Csrf::input() ?>
              <input type="hidden" name="action" value="delete_exception">
              <input type="hidden" name="branch_id" value="<?= (int) $branchId ?>">
              <input type="hidden" name="exception_id" value="<?= (int) $exception['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash3"></i></button>
            </form>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
