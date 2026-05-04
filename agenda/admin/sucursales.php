<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireSuperAdmin();
AppointmentService::ensureMachinerySchema();

$errors = [];
$editingId = 0;

function branch_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'sucursal';
}

function branch_validate(array $d, int $ignoreId = 0): array
{
    $branch = [
        'name' => trim($d['name'] ?? ''),
        'slug' => trim($d['slug'] ?? '') ?: branch_slug($d['name'] ?? ''),
        'address' => trim($d['address'] ?? ''),
        'city' => trim($d['city'] ?? ''),
        'state' => trim($d['state'] ?? ''),
        'phone' => preg_replace('/\D+/', '', $d['phone'] ?? ''),
        'whatsapp' => preg_replace('/\D+/', '', $d['whatsapp'] ?? ''),
        'email' => strtolower(trim($d['email'] ?? '')),
        'gmaps_url' => trim($d['gmaps_url'] ?? ''),
        'cabin_capacity' => max(1, min(10, (int) ($d['cabin_capacity'] ?? 3))),
        'laser_machine_capacity' => max(0, min(10, (int) ($d['laser_machine_capacity'] ?? 1))),
        'display_order' => (int) ($d['display_order'] ?? 0),
        'active' => isset($d['active']) ? 1 : 0,
    ];
    $branch['slug'] = branch_slug($branch['slug']);

    $errors = [];
    if (mb_strlen($branch['name']) < 3) $errors['name'] = 'Ingresa el nombre de la sucursal.';
    if (mb_strlen($branch['address']) < 5) $errors['address'] = 'Ingresa la dirección.';
    if (!$branch['city']) $errors['city'] = 'Ingresa la ciudad.';
    if (!$branch['state']) $errors['state'] = 'Ingresa el estado.';
    if ($branch['phone'] && strlen($branch['phone']) < 10) $errors['phone'] = 'El teléfono debe tener al menos 10 dígitos.';
    if ($branch['email'] && !filter_var($branch['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Correo inválido.';

    if ($branch['laser_machine_capacity'] > $branch['cabin_capacity']) {
        $errors['laser_machine_capacity'] = 'Las máquinas no pueden superar el número de cabinas.';
    }

    $params = [$branch['slug']];
    $ignoreSql = '';
    if ($ignoreId) {
        $ignoreSql = ' AND id <> ?';
        $params[] = $ignoreId;
    }
    if (Database::one("SELECT id FROM branches WHERE slug = ?{$ignoreSql} LIMIT 1", $params)) {
        $errors['slug'] = 'Ya existe una sucursal con ese identificador.';
    }

    return ['ok' => !$errors, 'errors' => $errors, 'branch' => $branch];
}

function branch_save_laser_capacity(int $branchId, int $capacity): void
{
    Database::exec(
        "INSERT INTO branch_service_resources (branch_id, resource_key, name, capacity, active)
         VALUES (?, 'depilacion_laser', 'Máquina de depilación láser', ?, 1)
         ON DUPLICATE KEY UPDATE capacity = VALUES(capacity), active = 1, updated_at = NOW()",
        [$branchId, $capacity]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = $_POST['action'] ?? '';
    $branchId = (int) ($_POST['branch_id'] ?? 0);

    if ($action === 'save') {
        $editingId = $branchId;
        $validated = branch_validate($_POST, $branchId);
        if ($validated['ok']) {
            $b = $validated['branch'];
            if ($branchId) {
                Database::exec(
                    'UPDATE branches SET slug=?, name=?, address=?, city=?, state=?, phone=?, whatsapp=?, email=?, gmaps_url=?, cabin_capacity=?, active=?, display_order=? WHERE id=?',
                    [$b['slug'], $b['name'], $b['address'], $b['city'], $b['state'], $b['phone'] ?: null, $b['whatsapp'] ?: null, $b['email'] ?: null, $b['gmaps_url'] ?: null, $b['cabin_capacity'], $b['active'], $b['display_order'], $branchId]
                );
                branch_save_laser_capacity($branchId, $b['laser_machine_capacity']);
                Auth::audit('branch_update', 'branch', $branchId);
                flash('success', 'Sucursal actualizada correctamente.');
            } else {
                Database::exec(
                    'INSERT INTO branches (slug, name, address, city, state, phone, whatsapp, email, gmaps_url, cabin_capacity, active, display_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$b['slug'], $b['name'], $b['address'], $b['city'], $b['state'], $b['phone'] ?: null, $b['whatsapp'] ?: null, $b['email'] ?: null, $b['gmaps_url'] ?: null, $b['cabin_capacity'], $b['active'], $b['display_order']]
                );
                $branchId = Database::lastId();
                branch_save_laser_capacity($branchId, $b['laser_machine_capacity']);
                Auth::audit('branch_create', 'branch', $branchId);
                flash('success', 'Sucursal creada correctamente.');
            }
            redirect('admin/sucursales.php');
        }
        $errors = $validated['errors'];
    }
}

$branches = Database::all(
    "SELECT b.id, b.slug, b.name, b.address, b.city, b.state, b.phone, b.whatsapp,
            b.email, b.gmaps_url, b.cabin_capacity, b.active, b.display_order,
            COALESCE(laser.capacity, 1) AS laser_machine_capacity,
            b.created_at, b.updated_at,
            COALESCE(ac.appointment_count, 0) AS appointment_count
     FROM branches b
     LEFT JOIN branch_service_resources laser
        ON laser.branch_id = b.id
       AND laser.resource_key = 'depilacion_laser'
       AND laser.active = 1
     LEFT JOIN (
        SELECT branch_id, COUNT(*) AS appointment_count
        FROM appointments
        GROUP BY branch_id
     ) ac ON ac.branch_id = b.id
     ORDER BY b.display_order, b.name"
);

$pageTitle = 'Sucursales';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <button class="btn btn-bnc-primary btn-sm" data-bs-toggle="modal" data-bs-target="#branchCreateModal"><i class="bi bi-shop"></i> Nueva sucursal</button>
</div>

<?php if ($errors): ?><div class="alert alert-danger">Revisa los campos marcados antes de guardar.</div><?php endif; ?>

<div class="bnc-card">
  <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Sucursales y cabinas</h2></div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead><tr><th>Sucursal</th><th>Ubicación</th><th>Cabinas</th><th>Contacto</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($branches as $branch): ?>
          <tr>
            <td><strong><?= e($branch['name']) ?></strong><br><small class="text-muted"><?= e($branch['slug']) ?></small></td>
            <td><?= e($branch['address']) ?><br><small class="text-muted"><?= e($branch['city']) ?>, <?= e($branch['state']) ?></small></td>
            <td>
              <span class="badge bg-primary"><?= (int) $branch['cabin_capacity'] ?> cabina(s)</span><br>
              <span class="badge bg-info text-dark mt-1"><?= (int) $branch['laser_machine_capacity'] ?> máquina(s) láser</span>
            </td>
            <td><?= e($branch['phone']) ?><br><small class="text-muted"><?= e($branch['email']) ?></small></td>
            <td><?= $branch['active'] ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-secondary">Inactiva</span>' ?></td>
            <td class="text-end"><button class="btn btn-sm btn-bnc-outline" data-bs-toggle="modal" data-bs-target="#branchEditModal-<?= (int) $branch['id'] ?>"><i class="bi bi-pencil"></i> Editar</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$emptyBranch = ['id'=>0,'name'=>'','slug'=>'','address'=>'','city'=>'','state'=>'','phone'=>'','whatsapp'=>'','email'=>'','gmaps_url'=>'','cabin_capacity'=>3,'laser_machine_capacity'=>1,'display_order'=>0,'active'=>1];
$modalBranches = array_merge([$emptyBranch], $branches);
foreach ($modalBranches as $branch):
  $isCreate = (int) $branch['id'] === 0;
  $modalId = $isCreate ? 'branchCreateModal' : 'branchEditModal-' . (int) $branch['id'];
  $isErrored = $errors && $editingId === (int) $branch['id'];
?>
  <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content" style="border-radius:16px">
        <form method="POST">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="branch_id" value="<?= (int) $branch['id'] ?>">
          <div class="modal-header">
            <h5 class="modal-title"><?= $isCreate ? 'Nueva sucursal' : 'Editar sucursal' ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="bnc-label">Nombre</label>
                <input name="name" class="form-control <?= isset($errors['name']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['name'] ?? '') : $branch['name']) ?>">
                <?php if (isset($errors['name']) && $isErrored): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-6">
                <label class="bnc-label">Identificador</label>
                <input name="slug" class="form-control <?= isset($errors['slug']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['slug'] ?? '') : $branch['slug']) ?>">
                <?php if (isset($errors['slug']) && $isErrored): ?><div class="invalid-feedback"><?= e($errors['slug']) ?></div><?php endif; ?>
              </div>
              <div class="col-12">
                <label class="bnc-label">Dirección</label>
                <input name="address" class="form-control <?= isset($errors['address']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['address'] ?? '') : $branch['address']) ?>">
                <?php if (isset($errors['address']) && $isErrored): ?><div class="invalid-feedback"><?= e($errors['address']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-4"><label class="bnc-label">Ciudad</label><input name="city" class="form-control" value="<?= e($isErrored ? ($_POST['city'] ?? '') : $branch['city']) ?>"></div>
              <div class="col-md-4"><label class="bnc-label">Estado</label><input name="state" class="form-control" value="<?= e($isErrored ? ($_POST['state'] ?? '') : $branch['state']) ?>"></div>
              <div class="col-md-4"><label class="bnc-label">Cabinas</label><input type="number" min="1" max="10" name="cabin_capacity" class="form-control" value="<?= e($isErrored ? ($_POST['cabin_capacity'] ?? 3) : $branch['cabin_capacity']) ?>"></div>
              <div class="col-md-4">
                <label class="bnc-label">Máquinas láser</label>
                <input type="number" min="0" max="10" name="laser_machine_capacity" class="form-control <?= isset($errors['laser_machine_capacity']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['laser_machine_capacity'] ?? 1) : ($branch['laser_machine_capacity'] ?? 1)) ?>">
                <?php if (isset($errors['laser_machine_capacity']) && $isErrored): ?><div class="invalid-feedback"><?= e($errors['laser_machine_capacity']) ?></div><?php endif; ?>
                <div class="form-text">Controla cuántas citas de depilación láser pueden ocurrir al mismo tiempo en esta sucursal.</div>
              </div>
              <div class="col-md-4"><label class="bnc-label">Teléfono</label><input name="phone" class="form-control" value="<?= e($isErrored ? ($_POST['phone'] ?? '') : $branch['phone']) ?>"></div>
              <div class="col-md-4"><label class="bnc-label">WhatsApp</label><input name="whatsapp" class="form-control" value="<?= e($isErrored ? ($_POST['whatsapp'] ?? '') : $branch['whatsapp']) ?>"></div>
              <div class="col-md-4"><label class="bnc-label">Correo</label><input type="email" name="email" class="form-control" value="<?= e($isErrored ? ($_POST['email'] ?? '') : $branch['email']) ?>"></div>
              <div class="col-md-8"><label class="bnc-label">Google Maps</label><input name="gmaps_url" class="form-control" value="<?= e($isErrored ? ($_POST['gmaps_url'] ?? '') : $branch['gmaps_url']) ?>"></div>
              <div class="col-md-2"><label class="bnc-label">Orden</label><input type="number" name="display_order" class="form-control" value="<?= e($isErrored ? ($_POST['display_order'] ?? 0) : $branch['display_order']) ?>"></div>
              <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="active" id="active-<?= e($modalId) ?>" <?= ($isErrored ? isset($_POST['active']) : $branch['active']) ? 'checked' : '' ?>><label class="form-check-label" for="active-<?= e($modalId) ?>">Activa</label></div></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-bnc-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($errors): ?>
  <script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal(document.getElementById('<?= e($editingId ? 'branchEditModal-' . $editingId : 'branchCreateModal') ?>')).show());</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
