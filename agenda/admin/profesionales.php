<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

// Asegura tabla user_branches + columna appointments.professional_id + rol professional
AppointmentService::ensureProfessionalSchema();

$errors = [];
$editingId = 0;

function prof_payload(array $data): array
{
    return [
        'name'  => trim($data['name']  ?? ''),
        'email' => strtolower(trim($data['email'] ?? '')),
        'phone' => preg_replace('/\D+/', '', $data['phone'] ?? ''),
        'branches' => array_map('intval', (array) ($data['branches'] ?? [])),
    ];
}

function prof_validate(array $data, int $ignoreId = 0): array
{
    $p = prof_payload($data);
    $errs = [];

    if (mb_strlen($p['name']) < 2) {
        $errs['name'] = 'Ingresa el nombre completo.';
    }
    if (!$p['email'] || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        $errs['email'] = 'Ingresa un correo valido.';
    } else {
        $params = [$p['email']];
        $ignoreSql = '';
        if ($ignoreId) { $ignoreSql = ' AND id <> ?'; $params[] = $ignoreId; }
        if (Database::one("SELECT id FROM users WHERE email = ?{$ignoreSql} LIMIT 1", $params)) {
            $errs['email'] = 'Ya existe un usuario con ese correo.';
        }
    }
    if (strlen($p['phone']) !== 10) {
        $errs['phone'] = 'Ingresa un telefono de exactamente 10 digitos.';
    } else {
        $params = [$p['phone']];
        $ignoreSql = '';
        if ($ignoreId) { $ignoreSql = ' AND id <> ?'; $params[] = $ignoreId; }
        if (Database::one("SELECT id FROM users WHERE phone = ?{$ignoreSql} LIMIT 1", $params)) {
            $errs['phone'] = 'Ya existe un usuario con ese telefono.';
        }
    }
    if (!$p['branches']) {
        $errs['branches'] = 'Asigna al menos una sucursal.';
    }

    return ['ok' => !$errs, 'errors' => $errs, 'data' => $p];
}

function prof_sync_branches(int $userId, array $branchIds): void
{
    Database::exec('DELETE FROM user_branches WHERE user_id = ?', [$userId]);
    foreach (array_unique(array_filter($branchIds)) as $bid) {
        Database::exec(
            'INSERT IGNORE INTO user_branches (user_id, branch_id) VALUES (?, ?)',
            [$userId, (int) $bid]
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = $_POST['action'] ?? '';
    $profId = (int) ($_POST['professional_id'] ?? 0);

    if ($action === 'save') {
        $editingId = $profId;
        $v = prof_validate($_POST, $profId);
        if ($v['ok']) {
            $p = $v['data'];
            $roleId = (int) Database::one("SELECT id FROM roles WHERE slug = 'professional' LIMIT 1")['id'];

            if ($profId) {
                Database::exec(
                    'UPDATE users SET name = ?, email = ?, phone = ?, role_id = ? WHERE id = ?',
                    [$p['name'], $p['email'], $p['phone'], $roleId, $profId]
                );
                prof_sync_branches($profId, $p['branches']);
                Auth::audit('admin_professional_update', 'user', $profId);
                flash('success', 'Profesional actualizado correctamente.');
            } else {
                Database::exec(
                    'INSERT INTO users (role_id, name, email, phone, password_hash, email_verified, active)
                     VALUES (?, ?, ?, ?, ?, 1, 1)',
                    [$roleId, $p['name'], $p['email'], $p['phone'],
                     password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]
                );
                $newId = Database::lastId();
                prof_sync_branches($newId, $p['branches']);
                Auth::audit('admin_professional_create', 'user', $newId);
                flash('success', 'Profesional creado correctamente.');
            }
            redirect('admin/profesionales.php');
        }
        $errors = $v['errors'];
    }

    if ($action === 'toggle' && $profId) {
        $row = Database::one('SELECT active FROM users WHERE id = ? LIMIT 1', [$profId]);
        if ($row) {
            $newState = ((int) $row['active']) ? 0 : 1;
            // Si vamos a desactivar: bloquear si tiene citas activas asignadas
            if ($newState === 0) {
                $n = (int) (Database::one(
                    "SELECT COUNT(*) AS n FROM appointments a
                     JOIN appointment_statuses st ON st.id = a.status_id
                     WHERE a.professional_id = ? AND a.start_at >= NOW()
                       AND st.slug IN ('programada','confirmada')",
                    [$profId]
                )['n'] ?? 0);
                if ($n > 0) {
                    flash('warning', "No se puede desactivar: el profesional tiene {$n} cita(s) activa(s). Reasigna o cancela primero.");
                    redirect('admin/profesionales.php');
                }
            }
            Database::exec('UPDATE users SET active = ? WHERE id = ?', [$newState, $profId]);
            Auth::audit($newState ? 'admin_professional_activate' : 'admin_professional_deactivate', 'user', $profId);
            flash('success', $newState ? 'Profesional reactivado.' : 'Profesional desactivado.');
        }
        redirect('admin/profesionales.php');
    }
}

// ── Listado ──
$q = trim($_GET['q'] ?? '');
$where = ["r.slug = 'professional'"];
$params = [];
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$pros = Database::all(
    "SELECT u.id, u.name, u.email, u.phone, u.active, u.created_at, u.last_login_at,
            (SELECT COUNT(*) FROM appointments a
              JOIN appointment_statuses st ON st.id = a.status_id
              WHERE a.professional_id = u.id
                AND a.start_at >= NOW()
                AND st.slug IN ('programada','confirmada')) AS upcoming_count,
            (SELECT COUNT(*) FROM appointments a
              WHERE a.professional_id = u.id) AS total_count
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY u.active DESC, u.name
     LIMIT 300",
    $params
);

// Sucursales por profesional (precarga eficiente)
$assignedByUser = [];
if ($pros) {
    $ids = array_column($pros, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = Database::all(
        "SELECT ub.user_id, b.id AS branch_id, b.name AS branch_name, b.slug AS branch_slug
         FROM user_branches ub
         JOIN branches b ON b.id = ub.branch_id
         WHERE ub.user_id IN ($ph)
         ORDER BY b.display_order, b.name",
        $ids
    );
    foreach ($rows as $r) {
        $assignedByUser[(int) $r['user_id']][] = $r;
    }
}

// Historial de citas por profesional (solo si pocas; limitamos)
$historiesByUser = [];
if ($pros) {
    $ids = array_column($pros, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $hRows = Database::all(
        "SELECT a.professional_id, a.code, a.start_at, s.name AS service_name, b.name AS branch_name,
                u.name AS client_name, st.slug AS status_slug, st.name AS status_name
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         JOIN branches b ON b.id = a.branch_id
         JOIN users u    ON u.id = a.user_id
         JOIN appointment_statuses st ON st.id = a.status_id
         WHERE a.professional_id IN ($ph)
         ORDER BY a.start_at DESC
         LIMIT 600",
        $ids
    );
    foreach ($hRows as $r) {
        $historiesByUser[(int) $r['professional_id']][] = $r;
    }
}

$branches = Database::all('SELECT id, name FROM branches WHERE active = 1 ORDER BY display_order, name');

$pageTitle = 'Profesionales';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <button class="btn btn-bnc-primary btn-sm" data-bs-toggle="modal" data-bs-target="#proCreateModal">
    <i class="bi bi-person-badge"></i> Nuevo profesional
  </button>
  <span class="text-muted small ms-auto"><i class="bi bi-info-circle"></i> Los profesionales atienden citas en cabina. Pueden estar en una o mas sucursales.</span>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">Revisa los campos marcados antes de guardar.</div>
<?php endif; ?>

<div class="bnc-card mb-4">
  <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Buscar profesionales</h2></div>
  <div class="bnc-card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-8">
        <label class="bnc-label">Nombre, correo o telefono</label>
        <input name="q" class="form-control" value="<?= e($q) ?>" placeholder="Buscar profesional">
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button class="btn btn-bnc-primary" type="submit"><i class="bi bi-search"></i> Buscar</button>
        <a class="btn btn-bnc-outline" href="<?= url('admin/profesionales.php') ?>">Limpiar</a>
      </div>
    </form>
  </div>
</div>

<div class="bnc-card">
  <div class="bnc-card-header d-flex align-items-center">
    <h2 class="h6 fw-bold mb-0 me-auto">Listado de profesionales</h2>
    <span class="badge bg-secondary"><?= count($pros) ?> resultado(s)</span>
  </div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead>
        <tr>
          <th>Profesional</th>
          <th>Contacto</th>
          <th>Sucursales</th>
          <th>Citas</th>
          <th>Estado</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$pros): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">
            Aun no hay profesionales. <a href="#" data-bs-toggle="modal" data-bs-target="#proCreateModal">Crear el primero</a>.
          </td></tr>
        <?php else: foreach ($pros as $pro): $aid = (int) $pro['id']; $branchesAssigned = $assignedByUser[$aid] ?? []; ?>
          <tr>
            <td class="fw-bold">
              <?= e($pro['name']) ?>
              <?php if ($pro['last_login_at']): ?>
                <div class="small text-muted">Ultimo acceso: <?= e(fmt_dt_short($pro['last_login_at'])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= e($pro['phone']) ?>
              <div class="small text-muted"><?= e($pro['email']) ?></div>
            </td>
            <td>
              <?php if (!$branchesAssigned): ?>
                <span class="badge bg-warning text-dark">Sin asignar</span>
              <?php else: foreach ($branchesAssigned as $b): ?>
                <span class="badge bg-light text-dark border me-1 mb-1"><?= e($b['branch_name']) ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td>
              <?= (int) $pro['total_count'] ?> total
              <div class="small text-muted"><?= (int) $pro['upcoming_count'] ?> proxima(s)</div>
            </td>
            <td><?= $pro['active']
                  ? '<span class="badge bg-success">Activo</span>'
                  : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-bnc-outline" data-bs-toggle="modal" data-bs-target="#proHistory-<?= $aid ?>" title="Historial"><i class="bi bi-clock-history"></i></button>
                <button class="btn btn-bnc-outline" data-bs-toggle="modal" data-bs-target="#proEdit-<?= $aid ?>" title="Editar"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#proToggle-<?= $aid ?>" title="<?= $pro['active'] ? 'Desactivar' : 'Reactivar' ?>">
                  <i class="bi <?= $pro['active'] ? 'bi-person-dash' : 'bi-person-check' ?>"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ════════ MODAL: NUEVO PROFESIONAL ════════ -->
<div class="modal fade" id="proCreateModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px">
      <form method="POST">
        <?= Csrf::input() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-badge"></i> Nuevo profesional</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="bnc-label">Nombre completo</label>
            <input name="name" class="form-control <?= isset($errors['name']) && !$editingId ? 'is-invalid' : '' ?>" value="<?= !$editingId ? e($_POST['name'] ?? '') : '' ?>">
            <?php if (isset($errors['name']) && !$editingId): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
          </div>
          <div class="row g-3">
            <div class="col-md-7">
              <label class="bnc-label">Correo</label>
              <input type="email" name="email" class="form-control <?= isset($errors['email']) && !$editingId ? 'is-invalid' : '' ?>" value="<?= !$editingId ? e($_POST['email'] ?? '') : '' ?>">
              <?php if (isset($errors['email']) && !$editingId): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="col-md-5">
              <label class="bnc-label">Telefono</label>
              <input name="phone" class="form-control <?= isset($errors['phone']) && !$editingId ? 'is-invalid' : '' ?>" value="<?= !$editingId ? e($_POST['phone'] ?? '') : '' ?>">
              <?php if (isset($errors['phone']) && !$editingId): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
            </div>
          </div>
          <div class="mt-3">
            <label class="bnc-label">Sucursales donde atiende</label>
            <div class="row g-2">
              <?php foreach ($branches as $b): $bid = (int) $b['id']; $checked = in_array($bid, (array) ($_POST['branches'] ?? []), true); ?>
                <div class="col-md-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="branches[]" id="brCreate-<?= $bid ?>" value="<?= $bid ?>" <?= $checked ? 'checked' : '' ?>>
                    <label class="form-check-label" for="brCreate-<?= $bid ?>"><?= e($b['name']) ?></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (isset($errors['branches']) && !$editingId): ?><div class="text-danger small mt-1"><?= e($errors['branches']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-bnc-primary" type="submit">Guardar profesional</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ════════ MODALES: EDITAR / DESACTIVAR / HISTORIAL ════════ -->
<?php foreach ($pros as $pro): $aid = (int) $pro['id']; $branchesAssigned = $assignedByUser[$aid] ?? []; $assignedIds = array_column($branchesAssigned, 'branch_id'); $hist = $historiesByUser[$aid] ?? []; ?>

<!-- Editar -->
<div class="modal fade" id="proEdit-<?= $aid ?>" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px">
      <form method="POST">
        <?= Csrf::input() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="professional_id" value="<?= $aid ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil"></i> Editar profesional</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="bnc-label">Nombre completo</label>
            <input name="name" class="form-control <?= isset($errors['name']) && $editingId === $aid ? 'is-invalid' : '' ?>" value="<?= e($editingId === $aid ? ($_POST['name'] ?? $pro['name']) : $pro['name']) ?>">
            <?php if (isset($errors['name']) && $editingId === $aid): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
          </div>
          <div class="row g-3">
            <div class="col-md-7">
              <label class="bnc-label">Correo</label>
              <input type="email" name="email" class="form-control <?= isset($errors['email']) && $editingId === $aid ? 'is-invalid' : '' ?>" value="<?= e($editingId === $aid ? ($_POST['email'] ?? $pro['email']) : $pro['email']) ?>">
              <?php if (isset($errors['email']) && $editingId === $aid): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="col-md-5">
              <label class="bnc-label">Telefono</label>
              <input name="phone" class="form-control <?= isset($errors['phone']) && $editingId === $aid ? 'is-invalid' : '' ?>" value="<?= e($editingId === $aid ? ($_POST['phone'] ?? $pro['phone']) : $pro['phone']) ?>">
              <?php if (isset($errors['phone']) && $editingId === $aid): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
            </div>
          </div>
          <div class="mt-3">
            <label class="bnc-label">Sucursales donde atiende</label>
            <div class="row g-2">
              <?php foreach ($branches as $b): $bid = (int) $b['id'];
                $checked = $editingId === $aid
                  ? in_array($bid, (array) ($_POST['branches'] ?? []), true)
                  : in_array($bid, $assignedIds, true);
              ?>
                <div class="col-md-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="branches[]" id="brEdit-<?= $aid ?>-<?= $bid ?>" value="<?= $bid ?>" <?= $checked ? 'checked' : '' ?>>
                    <label class="form-check-label" for="brEdit-<?= $aid ?>-<?= $bid ?>"><?= e($b['name']) ?></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (isset($errors['branches']) && $editingId === $aid): ?><div class="text-danger small mt-1"><?= e($errors['branches']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-bnc-primary" type="submit">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Activar / Desactivar -->
<div class="modal fade" id="proToggle-<?= $aid ?>" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px">
      <form method="POST">
        <?= Csrf::input() ?>
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="professional_id" value="<?= $aid ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= $pro['active'] ? 'Desactivar' : 'Reactivar' ?> profesional</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($pro['active']): ?>
            <?php if ((int) $pro['upcoming_count'] > 0): ?>
              <div class="alert alert-warning mb-0">Este profesional tiene <strong><?= (int) $pro['upcoming_count'] ?></strong> cita(s) activa(s). Reasigna o cancela esas citas antes de desactivarlo.</div>
            <?php else: ?>
              <p class="mb-0">El profesional dejara de aparecer en los selectores de citas. Su historial se conserva.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="mb-0">El profesional volvera a estar disponible para asignar a citas en sus sucursales.</p>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
          <button class="btn <?= $pro['active'] ? 'btn-danger' : 'btn-success' ?>" type="submit"
                  <?= ($pro['active'] && (int) $pro['upcoming_count'] > 0) ? 'disabled' : '' ?>>
            <?= $pro['active'] ? 'Desactivar' : 'Reactivar' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Historial -->
<div class="modal fade" id="proHistory-<?= $aid ?>" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:16px">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clock-history"></i> Historial de <?= e($pro['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if (!$hist): ?>
          <p class="text-muted mb-0">Este profesional aun no tiene citas asignadas.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Fecha</th><th>Cliente</th><th>Servicio</th><th>Sucursal</th><th>Estado</th><th>Codigo</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($hist, 0, 30) as $h): ?>
                  <tr>
                    <td><?= e(fmt_dt_short($h['start_at'])) ?></td>
                    <td><?= e($h['client_name']) ?></td>
                    <td><?= e($h['service_name']) ?></td>
                    <td><?= e($h['branch_name']) ?></td>
                    <td><span class="bnc-status <?= e($h['status_slug']) ?>"><?= e($h['status_name']) ?></span></td>
                    <td><code><?= e($h['code']) ?></code></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if ($errors): ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const modal = document.getElementById('<?= $editingId ? 'proEdit-' . (int) $editingId : 'proCreateModal' ?>');
      if (modal) new bootstrap.Modal(modal).show();
    });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
