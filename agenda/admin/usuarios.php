<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$errors = [];
$editingId = 0;

function admin_client_payload(array $data): array
{
    return [
        'name' => trim($data['name'] ?? ''),
        'email' => strtolower(trim($data['email'] ?? '')),
        'phone' => preg_replace('/\D+/', '', $data['phone'] ?? ''),
    ];
}

function admin_validate_client(array $data, int $ignoreId = 0): array
{
    $client = admin_client_payload($data);
    $errors = [];

    if (mb_strlen($client['name']) < 2) {
        $errors['name'] = 'Ingresa el nombre completo.';
    }
    if (!$client['email'] || !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Ingresa un correo válido.';
    } else {
        $params = [$client['email']];
        $ignoreSql = '';
        if ($ignoreId) {
            $ignoreSql = ' AND id <> ?';
            $params[] = $ignoreId;
        }
        if (Database::one("SELECT id FROM users WHERE email = ?{$ignoreSql} LIMIT 1", $params)) {
            $errors['email'] = 'Ya existe un cliente con ese correo.';
        }
    }
    if (strlen($client['phone']) < 10) {
        $errors['phone'] = 'Ingresa un teléfono de al menos 10 dígitos.';
    } else {
        $params = [$client['phone']];
        $ignoreSql = '';
        if ($ignoreId) {
            $ignoreSql = ' AND id <> ?';
            $params[] = $ignoreId;
        }
        if (Database::one("SELECT id FROM users WHERE phone = ?{$ignoreSql} LIMIT 1", $params)) {
            $errors['phone'] = 'Ya existe un cliente con ese teléfono.';
        }
    }

    return ['ok' => !$errors, 'errors' => $errors, 'client' => $client];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = $_POST['action'] ?? '';
    $clientId = (int) ($_POST['client_id'] ?? 0);

    if ($action === 'save') {
        $editingId = $clientId;
        $validated = admin_validate_client($_POST, $clientId);
        if ($validated['ok']) {
            $client = $validated['client'];
            if ($clientId) {
                Database::exec(
                    'UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?',
                    [$client['name'], $client['email'], $client['phone'], $clientId]
                );
                Auth::audit('admin_client_update', 'user', $clientId);
                flash('success', 'Cliente actualizado correctamente.');
            } else {
                $roleId = (int) Database::one("SELECT id FROM roles WHERE slug = 'cliente' LIMIT 1")['id'];
                Database::exec(
                    'INSERT INTO users (role_id, name, email, phone, password_hash, email_verified, active)
                     VALUES (?, ?, ?, ?, ?, 1, 1)',
                    [$roleId, $client['name'], $client['email'], $client['phone'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]
                );
                $clientId = Database::lastId();
                Auth::audit('admin_client_create', 'user', $clientId);
                flash('success', 'Cliente creado correctamente.');
            }
            redirect('admin/usuarios.php');
        }
        $errors = $validated['errors'];
    }

    if ($action === 'delete' && $clientId) {
        $activeAppointments = (int) (Database::one(
            "SELECT COUNT(*) AS n
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.user_id = ?
               AND a.start_at >= NOW()
               AND st.slug IN ('programada','confirmada')",
            [$clientId]
        )['n'] ?? 0);

        if ($activeAppointments > 0) {
            flash('warning', 'No se puede desactivar el cliente porque tiene citas activas.');
        } else {
            Database::exec('UPDATE users SET active = 0 WHERE id = ?', [$clientId]);
            Auth::audit('admin_client_deactivate', 'user', $clientId);
            flash('success', 'Cliente desactivado correctamente.');
        }
        redirect('admin/usuarios.php');
    }
}

$q = trim($_GET['q'] ?? '');
$where = ["r.slug = 'cliente'"];
$params = [];
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$clients = Database::all(
    "SELECT u.id, u.name, u.email, u.phone, u.active, u.created_at,
            COUNT(a.id) AS appointment_count,
            SUM(CASE WHEN a.start_at >= NOW() AND st.slug IN ('programada','confirmada') THEN 1 ELSE 0 END) AS active_appointments
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN appointments a ON a.user_id = u.id
     LEFT JOIN appointment_statuses st ON st.id = a.status_id
     WHERE " . implode(' AND ', $where) . "
     GROUP BY u.id
     ORDER BY u.active DESC, u.name
     LIMIT 300",
    $params
);

$clientIds = array_column($clients, 'id');
$histories = [];
if ($clientIds) {
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $historyRows = Database::all(
        "SELECT a.user_id, a.code, a.start_at, s.name AS service_name, b.name AS branch_name,
                st.slug AS status_slug, st.name AS status_name
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         JOIN branches b ON b.id = a.branch_id
         JOIN appointment_statuses st ON st.id = a.status_id
         WHERE a.user_id IN ($placeholders)
         ORDER BY a.start_at DESC",
        $clientIds
    );
    foreach ($historyRows as $row) {
        $histories[(int) $row['user_id']][] = $row;
    }
}

$pageTitle = 'Clientes';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <button class="btn btn-bnc-primary btn-sm" data-bs-toggle="modal" data-bs-target="#clientCreateModal"><i class="bi bi-person-plus"></i> Nuevo cliente</button>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">Revisa los campos marcados antes de guardar.</div>
<?php endif; ?>

<div class="bnc-card mb-4">
  <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Buscar clientes</h2></div>
  <div class="bnc-card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-8">
        <label class="bnc-label">Nombre, correo o teléfono</label>
        <input name="q" class="form-control" value="<?= e($q) ?>" placeholder="Buscar cliente">
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button class="btn btn-bnc-primary" type="submit"><i class="bi bi-search"></i> Buscar</button>
        <a class="btn btn-bnc-outline" href="<?= url('admin/usuarios.php') ?>">Limpiar</a>
      </div>
    </form>
  </div>
</div>

<div class="bnc-card">
  <div class="bnc-card-header d-flex align-items-center">
    <h2 class="h6 fw-bold mb-0 me-auto">Listado de clientes</h2>
    <span class="badge bg-secondary"><?= count($clients) ?> resultado(s)</span>
  </div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Contacto</th>
          <th>Citas</th>
          <th>Estado</th>
          <th>Alta</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$clients): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No hay clientes con esa búsqueda.</td></tr>
        <?php else: foreach ($clients as $client): ?>
          <tr>
            <td class="fw-bold"><?= e($client['name']) ?></td>
            <td><?= e($client['phone']) ?><br><small class="text-muted"><?= e($client['email']) ?></small></td>
            <td>
              <?= (int) $client['appointment_count'] ?> total<br>
              <small class="text-muted"><?= (int) $client['active_appointments'] ?> activa(s)</small>
            </td>
            <td><?= $client['active'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
            <td><?= e(fmt_dt_short($client['created_at'])) ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-bnc-outline" data-bs-toggle="modal" data-bs-target="#historyModal-<?= (int) $client['id'] ?>"><i class="bi bi-clock-history"></i></button>
                <button class="btn btn-bnc-outline" data-bs-toggle="modal" data-bs-target="#clientEditModal-<?= (int) $client['id'] ?>"><i class="bi bi-pencil"></i></button>
                <?php if ($client['active']): ?>
                  <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#clientDeleteModal-<?= (int) $client['id'] ?>"><i class="bi bi-person-dash"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="clientCreateModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px">
      <form method="POST">
        <?= Csrf::input() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header">
          <h5 class="modal-title">Nuevo cliente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="bnc-label">Nombre completo</label>
            <input name="name" class="form-control <?= isset($errors['name']) && !$editingId ? 'is-invalid' : '' ?>" value="<?= !$editingId ? e($_POST['name'] ?? '') : '' ?>">
            <?php if (isset($errors['name']) && !$editingId): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="bnc-label">Correo</label>
            <input type="email" name="email" class="form-control <?= isset($errors['email']) && !$editingId ? 'is-invalid' : '' ?>" value="<?= !$editingId ? e($_POST['email'] ?? '') : '' ?>">
            <?php if (isset($errors['email']) && !$editingId): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="bnc-label">Teléfono</label>
            <input name="phone" class="form-control <?= isset($errors['phone']) && !$editingId ? 'is-invalid' : '' ?>" value="<?= !$editingId ? e($_POST['phone'] ?? '') : '' ?>">
            <?php if (isset($errors['phone']) && !$editingId): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-bnc-primary" type="submit">Guardar cliente</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foreach ($clients as $client): $history = $histories[(int) $client['id']] ?? []; ?>
  <div class="modal fade" id="clientEditModal-<?= (int) $client['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius:16px">
        <form method="POST">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
          <div class="modal-header">
            <h5 class="modal-title">Editar cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="bnc-label">Nombre completo</label>
              <input name="name" class="form-control <?= isset($errors['name']) && $editingId === (int) $client['id'] ? 'is-invalid' : '' ?>" value="<?= e($editingId === (int) $client['id'] ? ($_POST['name'] ?? $client['name']) : $client['name']) ?>">
              <?php if (isset($errors['name']) && $editingId === (int) $client['id']): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
              <label class="bnc-label">Correo</label>
              <input type="email" name="email" class="form-control <?= isset($errors['email']) && $editingId === (int) $client['id'] ? 'is-invalid' : '' ?>" value="<?= e($editingId === (int) $client['id'] ? ($_POST['email'] ?? $client['email']) : $client['email']) ?>">
              <?php if (isset($errors['email']) && $editingId === (int) $client['id']): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
              <label class="bnc-label">Teléfono</label>
              <input name="phone" class="form-control <?= isset($errors['phone']) && $editingId === (int) $client['id'] ? 'is-invalid' : '' ?>" value="<?= e($editingId === (int) $client['id'] ? ($_POST['phone'] ?? $client['phone']) : $client['phone']) ?>">
              <?php if (isset($errors['phone']) && $editingId === (int) $client['id']): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
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

  <div class="modal fade" id="historyModal-<?= (int) $client['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content" style="border-radius:16px">
        <div class="modal-header">
          <h5 class="modal-title">Historial de <?= e($client['name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if (!$history): ?>
            <p class="text-muted mb-0">Este cliente aún no tiene citas registradas.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead><tr><th>Fecha</th><th>Servicio</th><th>Sucursal</th><th>Estado</th><th>Código</th></tr></thead>
                <tbody>
                  <?php foreach (array_slice($history, 0, 20) as $h): ?>
                    <tr>
                      <td><?= e(fmt_dt_short($h['start_at'])) ?></td>
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

  <div class="modal fade" id="clientDeleteModal-<?= (int) $client['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius:16px">
        <form method="POST">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
          <div class="modal-header">
            <h5 class="modal-title">Desactivar cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <?php if ((int) $client['active_appointments'] > 0): ?>
              <div class="alert alert-warning mb-0">Este cliente tiene citas activas. Cancela o atiende esas citas antes de desactivarlo.</div>
            <?php else: ?>
              <p class="mb-0">El cliente dejará de aparecer como activo, pero se conserva su historial clínico y administrativo.</p>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
            <button class="btn btn-danger" type="submit" <?= (int) $client['active_appointments'] > 0 ? 'disabled' : '' ?>>Desactivar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($errors): ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const modal = document.getElementById('<?= $editingId ? 'clientEditModal-' . (int) $editingId : 'clientCreateModal' ?>');
      if (modal) new bootstrap.Modal(modal).show();
    });
  </script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
