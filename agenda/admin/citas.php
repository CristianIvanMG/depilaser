<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$admin = Auth::user();

// Asegura columna professional_id (idempotente)
AppointmentService::ensureProfessionalSchema();

$branches = Database::all('SELECT id, name FROM branches WHERE active = 1 ORDER BY display_order, name');
$statuses = Database::all('SELECT id, slug, name FROM appointment_statuses ORDER BY id');
$professionals = Database::all(
    "SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id
     WHERE r.slug = 'professional' AND u.active = 1 ORDER BY u.name"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = $_POST['action'] ?? '';
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);

    $appointment = Database::one(
        "SELECT a.id, a.code, st.slug AS status_slug
         FROM appointments a
         JOIN appointment_statuses st ON st.id = a.status_id
         WHERE a.id = ? LIMIT 1",
        [$appointmentId]
    );

    if (!$appointment) {
        flash('danger', 'Cita no encontrada.');
        redirect('admin/citas.php');
    }

    if ($action === 'cancel') {
        if ($appointment['status_slug'] === 'cancelada') {
            flash('warning', 'La cita ya se encuentra cancelada.');
        } else {
            $cancelStatus = Database::one("SELECT id FROM appointment_statuses WHERE slug = 'cancelada' LIMIT 1");
            Database::exec(
                'UPDATE appointments
                 SET status_id = ?, cancelled_at = NOW(), cancelled_by_user_id = ?, cancel_reason = ?
                 WHERE id = ?',
                [(int) $cancelStatus['id'], $admin['id'], trim($_POST['cancel_reason'] ?? '') ?: null, $appointmentId]
            );
            Auth::audit('appointment_cancel_admin', 'appointment', $appointmentId, [
                'code' => $appointment['code'],
                'reason' => trim($_POST['cancel_reason'] ?? ''),
            ]);
            flash('success', 'Cita cancelada correctamente. El horario queda disponible si hay cabina.');
        }
        redirect('admin/citas.php?' . http_build_query($_GET));
    }

    if ($action === 'delete') {
        Database::exec('DELETE FROM appointments WHERE id = ?', [$appointmentId]);
        Auth::audit('appointment_delete', 'appointment', $appointmentId, ['code' => $appointment['code']]);
        flash('success', 'Cita eliminada definitivamente.');
        redirect('admin/citas.php?' . http_build_query($_GET));
    }
}

$dateFrom = trim($_GET['from'] ?? date('Y-m-d'));
$dateTo = trim($_GET['to'] ?? date('Y-m-d', strtotime('+14 days')));
$branchId = (int) ($_GET['branch_id'] ?? 0);
$statusId = (int) ($_GET['status_id'] ?? 0);
$professionalId = (int) ($_GET['professional_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d', strtotime('+14 days'));

$where = ['a.start_at >= ?', 'a.start_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$dateFrom, $dateTo];

if ($branchId) {
    $where[] = 'a.branch_id = ?';
    $params[] = $branchId;
}
if ($statusId) {
    $where[] = 'a.status_id = ?';
    $params[] = $statusId;
}
if ($professionalId) {
    $where[] = 'a.professional_id = ?';
    $params[] = $professionalId;
}
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR a.code LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$appointments = Database::all(
    "SELECT a.*, u.name AS client_name, u.email AS client_email, u.phone AS client_phone,
            s.name AS service_name, s.duration_min, b.name AS branch_name,
            st.slug AS status_slug, st.name AS status_name,
            pr.name AS professional_name
     FROM appointments a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN users pr ON pr.id = a.professional_id
     JOIN services s ON s.id = a.service_id
     JOIN branches b ON b.id = a.branch_id
     JOIN appointment_statuses st ON st.id = a.status_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY a.start_at DESC
     LIMIT 300",
    $params
);

$pageTitle = 'Citas';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a href="<?= url('admin/cita-form.php') ?>" class="btn btn-bnc-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva cita</a>
  <a href="<?= url('admin/calendario.php') ?>" class="btn btn-sm btn-bnc-outline"><i class="bi bi-calendar3"></i> Calendario</a>
</div>

<div class="bnc-card mb-4">
  <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Filtros de búsqueda</h2></div>
  <div class="bnc-card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-2">
        <label class="bnc-label">Desde</label>
        <input type="date" name="from" class="form-control" value="<?= e($dateFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="bnc-label">Hasta</label>
        <input type="date" name="to" class="form-control" value="<?= e($dateTo) ?>">
      </div>
      <div class="col-md-3">
        <label class="bnc-label">Sucursal</label>
        <select name="branch_id" class="form-select">
          <option value="">Todas</option>
          <?php foreach ($branches as $branch): ?>
            <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="bnc-label">Estado</label>
        <select name="status_id" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($statuses as $status): ?>
            <option value="<?= (int) $status['id'] ?>" <?= $statusId === (int) $status['id'] ? 'selected' : '' ?>><?= e($status['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="bnc-label">Profesional</label>
        <select name="professional_id" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($professionals as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $professionalId === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="bnc-label">Cliente o código</label>
        <input name="q" class="form-control" value="<?= e($q) ?>" placeholder="Nombre, correo, teléfono o código">
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-bnc-primary" type="submit"><i class="bi bi-search"></i> Buscar</button>
        <a class="btn btn-bnc-outline" href="<?= url('admin/citas.php') ?>">Limpiar</a>
      </div>
    </form>
  </div>
</div>

<div class="bnc-card">
  <div class="bnc-card-header d-flex flex-wrap align-items-center gap-2">
    <h2 class="h6 fw-bold mb-0 me-auto">Listado de citas</h2>
    <span class="badge bg-secondary"><?= count($appointments) ?> resultado(s)</span>
  </div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Cliente</th>
          <th>Servicio</th>
          <th>Sucursal</th>
          <th>Profesional</th>
          <th>Estado</th>
          <th>Origen</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$appointments): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No hay citas con esos filtros.</td></tr>
        <?php else: foreach ($appointments as $a): ?>
          <tr>
            <td>
              <strong><?= e(fmt_dt_short($a['start_at'])) ?></strong><br>
              <small class="text-muted"><?= date('H:i', strtotime($a['start_at'])) ?> - <?= date('H:i', strtotime($a['end_at'])) ?></small>
            </td>
            <td>
              <?= e($a['client_name']) ?><br>
              <small class="text-muted"><?= e($a['client_phone'] ?: $a['client_email']) ?></small>
            </td>
            <td><?= e($a['service_name']) ?><br><small class="text-muted"><?= (int) $a['duration_min'] ?> min</small></td>
            <td><?= e($a['branch_name']) ?></td>
            <td>
              <?php if (!empty($a['professional_name'])): ?>
                <?= e($a['professional_name']) ?>
              <?php else: ?>
                <span class="text-muted small fst-italic">Sin asignar</span>
              <?php endif; ?>
            </td>
            <td><span class="bnc-status <?= e($a['status_slug']) ?>"><?= e($a['status_name']) ?></span></td>
            <td><?= e(ucfirst((string) $a['source'])) ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-bnc-outline" data-bs-toggle="modal" data-bs-target="#detailModal-<?= (int) $a['id'] ?>"><i class="bi bi-eye"></i></button>
                <a class="btn btn-bnc-outline" href="<?= url('admin/cita-form.php?id=' . (int) $a['id']) ?>"><i class="bi bi-pencil"></i></a>
                <?php if ($a['status_slug'] !== 'cancelada'): ?>
                  <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal-<?= (int) $a['id'] ?>"><i class="bi bi-calendar-x"></i></button>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#deleteModal-<?= (int) $a['id'] ?>"><i class="bi bi-trash3"></i></button>
              </div>
            </td>
          </tr>

          <div class="modal fade" id="detailModal-<?= (int) $a['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content" style="border-radius:16px">
                <div class="modal-header">
                  <h5 class="modal-title">Detalle de cita</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-2"><strong>Código:</strong> <code><?= e($a['code']) ?></code></div>
                  <div class="mb-2"><strong>Cliente:</strong> <?= e($a['client_name']) ?><br><small class="text-muted"><?= e($a['client_phone']) ?> <?= e($a['client_email']) ?></small></div>
                  <div class="mb-2"><strong>Servicio:</strong> <?= e($a['service_name']) ?></div>
                  <div class="mb-2"><strong>Sucursal:</strong> <?= e($a['branch_name']) ?></div>
                  <div class="mb-2"><strong>Profesional:</strong> <?= !empty($a['professional_name']) ? e($a['professional_name']) : '<span class="text-muted">Sin asignar</span>' ?></div>
                  <div class="mb-2"><strong>Fecha:</strong> <?= e(fmt_dt($a['start_at'])) ?></div>
                  <div class="mb-2"><strong>Estado:</strong> <span class="bnc-status <?= e($a['status_slug']) ?>"><?= e($a['status_name']) ?></span></div>
                  <?php if ($a['notes_admin']): ?><hr><strong>Nota interna:</strong><br><?= nl2br(e($a['notes_admin'])) ?><?php endif; ?>
                  <?php if ($a['cancel_reason']): ?><hr><strong>Motivo de cancelación:</strong><br><?= e($a['cancel_reason']) ?><?php endif; ?>
                </div>
                <div class="modal-footer">
                  <a class="btn btn-bnc-primary" href="<?= url('admin/cita-form.php?id=' . (int) $a['id']) ?>">Editar</a>
                </div>
              </div>
            </div>
          </div>

          <div class="modal fade" id="cancelModal-<?= (int) $a['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content" style="border-radius:16px">
                <form method="POST">
                  <?= Csrf::input() ?>
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="appointment_id" value="<?= (int) $a['id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Cancelar cita</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p>La cita se conservará para historial y estadísticas.</p>
                    <label class="bnc-label">Motivo (opcional)</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" maxlength="255"></textarea>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Mantener</button>
                    <button type="submit" class="btn btn-danger">Cancelar cita</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <div class="modal fade" id="deleteModal-<?= (int) $a['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content" style="border-radius:16px">
                <form method="POST">
                  <?= Csrf::input() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="appointment_id" value="<?= (int) $a['id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Eliminar cita</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <div class="alert alert-danger mb-0">Esta acción elimina el registro definitivamente. Para operación normal usa Cancelar.</div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                    <button type="submit" class="btn btn-danger">Eliminar definitivamente</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
