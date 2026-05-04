<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
PaymentService::ensureSchema();

$errors = [];
$editingId = 0;
$branches = Database::all('SELECT id, name FROM branches WHERE active=1 ORDER BY display_order, name');

function service_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'servicio';
}

function service_validate(array $d, int $ignoreId = 0): array
{
    $service = [
        'name' => trim($d['name'] ?? ''),
        'slug' => trim($d['slug'] ?? '') ?: service_slug($d['name'] ?? ''),
        'category' => trim($d['category'] ?? 'depilacion_laser') ?: 'depilacion_laser',
        'description' => trim($d['description'] ?? ''),
        'duration_min' => (int) ($d['duration_min'] ?? 30),
        'price_mxn' => (float) ($d['price_mxn'] ?? 0),
        'payment_required' => isset($d['payment_required']) ? 1 : 0,
        'payment_mode' => in_array(($d['payment_mode'] ?? 'none'), ['none','deposit','full'], true) ? $d['payment_mode'] : 'none',
        'deposit_amount_mxn' => (float) ($d['deposit_amount_mxn'] ?? 0),
        'display_order' => (int) ($d['display_order'] ?? 0),
        'active' => isset($d['active']) ? 1 : 0,
        'branches' => array_map('intval', $d['branches'] ?? []),
    ];
    $service['slug'] = service_slug($service['slug']);
    $errors = [];
    if (mb_strlen($service['name']) < 3) $errors['name'] = 'Ingresa el nombre del servicio.';
    if ($service['duration_min'] < 5 || $service['duration_min'] > 240) $errors['duration_min'] = 'La duración debe estar entre 5 y 240 minutos.';
    if ($service['price_mxn'] < 0) $errors['price_mxn'] = 'El precio no puede ser negativo.';
    if (!$service['payment_required']) {
        $service['payment_mode'] = 'none';
        $service['deposit_amount_mxn'] = null;
    } elseif ($service['payment_mode'] === 'none') {
        $errors['payment_mode'] = 'Selecciona si se cobra anticipo o pago total.';
    } elseif ($service['payment_mode'] === 'deposit' && ($service['deposit_amount_mxn'] <= 0 || $service['deposit_amount_mxn'] > $service['price_mxn'])) {
        $errors['deposit_amount_mxn'] = 'El anticipo debe ser mayor a cero y no superar el precio.';
    }
    if (!$service['branches']) $errors['branches'] = 'Selecciona al menos una sucursal.';

    $params = [$service['slug']];
    $ignoreSql = '';
    if ($ignoreId) {
        $ignoreSql = ' AND id <> ?';
        $params[] = $ignoreId;
    }
    if (Database::one("SELECT id FROM services WHERE slug = ?{$ignoreSql} LIMIT 1", $params)) {
        $errors['slug'] = 'Ya existe un servicio con ese identificador.';
    }
    return ['ok' => !$errors, 'errors' => $errors, 'service' => $service];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = $_POST['action'] ?? '';
    $serviceId = (int) ($_POST['service_id'] ?? 0);

    if ($action === 'save') {
        $editingId = $serviceId;
        $validated = service_validate($_POST, $serviceId);
        if ($validated['ok']) {
            $s = $validated['service'];
            if ($serviceId && !$s['active']) {
                $activeAppointments = (int) (Database::one(
                    "SELECT COUNT(*) AS n
                     FROM appointments a
                     JOIN appointment_statuses st ON st.id = a.status_id
                     WHERE a.service_id=? AND a.start_at >= NOW() AND st.slug IN ('programada','confirmada')",
                    [$serviceId]
                )['n'] ?? 0);
                if ($activeAppointments > 0) {
                    $errors['active'] = 'No se puede desactivar un servicio con citas activas.';
                }
            }
            if (!$errors) {
                if ($serviceId) {
                    Database::exec(
                        'UPDATE services SET slug=?, name=?, category=?, description=?, duration_min=?, price_mxn=?, payment_required=?, payment_mode=?, deposit_amount_mxn=?, active=?, display_order=? WHERE id=?',
                        [$s['slug'], $s['name'], $s['category'], $s['description'] ?: null, $s['duration_min'], $s['price_mxn'], $s['payment_required'], $s['payment_mode'], $s['deposit_amount_mxn'], $s['active'], $s['display_order'], $serviceId]
                    );
                    Database::exec('DELETE FROM service_branches WHERE service_id=?', [$serviceId]);
                    Auth::audit('service_update', 'service', $serviceId);
                    flash('success', 'Servicio actualizado correctamente.');
                } else {
                    Database::exec(
                        'INSERT INTO services (slug, name, category, description, duration_min, price_mxn, payment_required, payment_mode, deposit_amount_mxn, active, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$s['slug'], $s['name'], $s['category'], $s['description'] ?: null, $s['duration_min'], $s['price_mxn'], $s['payment_required'], $s['payment_mode'], $s['deposit_amount_mxn'], $s['active'], $s['display_order']]
                    );
                    $serviceId = Database::lastId();
                    Auth::audit('service_create', 'service', $serviceId);
                    flash('success', 'Servicio creado correctamente.');
                }
                foreach ($s['branches'] as $branchId) {
                    Database::exec('INSERT IGNORE INTO service_branches (service_id, branch_id) VALUES (?, ?)', [$serviceId, $branchId]);
                }
                redirect('admin/servicios.php');
            }
        }
        $errors = array_merge($validated['errors'], $errors);
    }

    if ($action === 'deactivate' && $serviceId) {
        $activeAppointments = (int) (Database::one(
            "SELECT COUNT(*) AS n
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.service_id=? AND a.start_at >= NOW() AND st.slug IN ('programada','confirmada')",
            [$serviceId]
        )['n'] ?? 0);
        if ($activeAppointments > 0) {
            flash('warning', 'No se puede desactivar un servicio con citas activas.');
        } else {
            Database::exec('UPDATE services SET active=0 WHERE id=?', [$serviceId]);
            Auth::audit('service_deactivate', 'service', $serviceId);
            flash('success', 'Servicio desactivado correctamente.');
        }
        redirect('admin/servicios.php');
    }
}

$services = Database::all(
    "SELECT s.*,
            GROUP_CONCAT(b.name ORDER BY b.display_order SEPARATOR ', ') AS branch_names,
            COUNT(DISTINCT a.id) AS appointment_count
     FROM services s
     LEFT JOIN service_branches sb ON sb.service_id=s.id
     LEFT JOIN branches b ON b.id=sb.branch_id
     LEFT JOIN appointments a ON a.service_id=s.id
     GROUP BY s.id
     ORDER BY s.active DESC, s.display_order, s.name"
);
$serviceBranchRows = Database::all('SELECT service_id, branch_id FROM service_branches');
$serviceBranches = [];
foreach ($serviceBranchRows as $row) {
    $serviceBranches[(int) $row['service_id']][] = (int) $row['branch_id'];
}

$pageTitle = 'Servicios';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <button class="btn btn-bnc-primary btn-sm" data-bs-toggle="modal" data-bs-target="#serviceCreateModal"><i class="bi bi-plus-lg"></i> Nuevo servicio</button>
</div>

<?php if ($errors): ?><div class="alert alert-danger">Revisa los campos marcados antes de guardar.</div><?php endif; ?>

<div class="bnc-card">
  <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Catálogo de servicios</h2></div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead><tr><th>Servicio</th><th>Duración</th><th>Precio</th><th>Sucursales</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($services as $service): ?>
          <tr>
            <td><strong><?= e($service['name']) ?></strong><br><small class="text-muted"><?= e($service['category']) ?> · <?= e($service['slug']) ?></small></td>
            <td><?= (int) $service['duration_min'] ?> min</td>
            <td><?= fmt_price((float) $service['price_mxn']) ?></td>
            <td><?= e($service['branch_names'] ?: 'Sin sucursales') ?></td>
            <td><?= $service['active'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-bnc-outline" data-bs-toggle="modal" data-bs-target="#serviceEditModal-<?= (int) $service['id'] ?>"><i class="bi bi-pencil"></i></button>
                <?php if ($service['active']): ?>
                  <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#serviceDeactivateModal-<?= (int) $service['id'] ?>"><i class="bi bi-eye-slash"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$emptyService = ['id'=>0,'name'=>'','slug'=>'','category'=>'depilacion_laser','description'=>'','duration_min'=>30,'price_mxn'=>'0.00','display_order'=>0,'active'=>1];
$modalServices = array_merge([$emptyService], $services);
foreach ($modalServices as $service):
  $isCreate = (int) $service['id'] === 0;
  $modalId = $isCreate ? 'serviceCreateModal' : 'serviceEditModal-' . (int) $service['id'];
  $isErrored = $errors && $editingId === (int) $service['id'];
  $selectedBranches = $isErrored ? array_map('intval', $_POST['branches'] ?? []) : ($serviceBranches[(int) $service['id']] ?? []);
?>
  <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content" style="border-radius:16px">
        <form method="POST">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
          <div class="modal-header">
            <h5 class="modal-title"><?= $isCreate ? 'Nuevo servicio' : 'Editar servicio' ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6"><label class="bnc-label">Nombre</label><input name="name" class="form-control <?= isset($errors['name']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['name'] ?? '') : $service['name']) ?>"><?php if(isset($errors['name']) && $isErrored): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?></div>
              <div class="col-md-6"><label class="bnc-label">Identificador</label><input name="slug" class="form-control <?= isset($errors['slug']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['slug'] ?? '') : $service['slug']) ?>"><?php if(isset($errors['slug']) && $isErrored): ?><div class="invalid-feedback"><?= e($errors['slug']) ?></div><?php endif; ?></div>
              <div class="col-md-4"><label class="bnc-label">Categoría</label><input name="category" class="form-control" value="<?= e($isErrored ? ($_POST['category'] ?? '') : $service['category']) ?>"></div>
              <div class="col-md-4"><label class="bnc-label">Duración min</label><input type="number" min="5" max="240" name="duration_min" class="form-control <?= isset($errors['duration_min']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['duration_min'] ?? 30) : $service['duration_min']) ?>"></div>
              <div class="col-md-4"><label class="bnc-label">Precio MXN</label><input type="number" min="0" step="0.01" name="price_mxn" class="form-control <?= isset($errors['price_mxn']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['price_mxn'] ?? 0) : $service['price_mxn']) ?>"></div>
              <div class="col-md-9"><label class="bnc-label">Descripción</label><input name="description" class="form-control" value="<?= e($isErrored ? ($_POST['description'] ?? '') : $service['description']) ?>"></div>
              <div class="col-md-3"><label class="bnc-label">Orden</label><input type="number" name="display_order" class="form-control" value="<?= e($isErrored ? ($_POST['display_order'] ?? 0) : $service['display_order']) ?>"></div>
              <div class="col-12">
                <label class="bnc-label d-block">Sucursales</label>
                <?php foreach ($branches as $branch): ?>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="branches[]" value="<?= (int) $branch['id'] ?>" id="svc-<?= e($modalId) ?>-<?= (int) $branch['id'] ?>" <?= in_array((int) $branch['id'], $selectedBranches, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="svc-<?= e($modalId) ?>-<?= (int) $branch['id'] ?>"><?= e($branch['name']) ?></label>
                  </div>
                <?php endforeach; ?>
                <?php if(isset($errors['branches']) && $isErrored): ?><div class="text-danger small mt-1"><?= e($errors['branches']) ?></div><?php endif; ?>
              </div>
              <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" id="svc-active-<?= e($modalId) ?>" <?= ($isErrored ? isset($_POST['active']) : $service['active']) ? 'checked' : '' ?>><label class="form-check-label" for="svc-active-<?= e($modalId) ?>">Servicio activo</label></div></div>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-bnc-primary">Guardar</button></div>
        </form>
      </div>
    </div>
  </div>
<?php if (!$isCreate): ?>
  <div class="modal fade" id="serviceDeactivateModal-<?= (int) $service['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border-radius:16px"><form method="POST"><?= Csrf::input() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>"><div class="modal-header"><h5 class="modal-title">Desactivar servicio</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="mb-0">El servicio dejará de estar disponible para nuevas citas. Su historial se conserva.</p></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button><button type="submit" class="btn btn-danger">Desactivar</button></div></form></div></div>
  </div>
<?php endif; endforeach; ?>

<?php if ($errors): ?>
  <script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal(document.getElementById('<?= e($editingId ? 'serviceEditModal-' . $editingId : 'serviceCreateModal') ?>')).show());</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
