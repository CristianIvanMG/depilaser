<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
PaymentService::ensureSchema();
ServiceCatalogService::ensureSchema();

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
    $itemType = ServiceCatalogService::normalizeType($d['item_type'] ?? ServiceCatalogService::TYPE_SERVICE);
    $packageItems = ServiceCatalogService::normalizePackageItems($d['package_services'] ?? [], $d['package_sessions'] ?? []);
    $submittedPrice = (float) ($d['price_mxn'] ?? 0);
    $packageBasePrice = $itemType === ServiceCatalogService::TYPE_PACKAGE
        ? ServiceCatalogService::calculatePackageBasePrice($packageItems)
        : null;
    $hasPackageFinal = array_key_exists('precio_final', $d) && trim((string) $d['precio_final']) !== '';
    $packageFinalPrice = $itemType === ServiceCatalogService::TYPE_PACKAGE
        ? ($hasPackageFinal ? (float) $d['precio_final'] : $packageBasePrice)
        : null;
    $service = [
        'name' => trim($d['name'] ?? ''),
        'slug' => trim($d['slug'] ?? '') ?: service_slug($d['name'] ?? ''),
        'category' => trim($d['category'] ?? 'depilacion_laser') ?: 'depilacion_laser',
        'description' => trim($d['description'] ?? ''),
        'item_type' => $itemType,
        'duration_min' => (int) ($d['duration_min'] ?? 30),
        'price_mxn' => $itemType === ServiceCatalogService::TYPE_PACKAGE ? ($packageFinalPrice ?? 0.0) : $submittedPrice,
        'precio_base_calculado' => $packageBasePrice,
        'precio_final' => $packageFinalPrice,
        'sessions_count' => max(1, (int) ($d['sessions_count'] ?? 1)),
        'payment_required' => isset($d['payment_required']) ? 1 : 0,
        'payment_mode' => in_array(($d['payment_mode'] ?? 'none'), ['none','deposit','full'], true) ? $d['payment_mode'] : 'none',
        'price_locked' => $itemType === ServiceCatalogService::TYPE_PACKAGE ? 1 : (isset($d['price_locked']) ? 1 : 0),
        'deposit_amount_mxn' => (float) ($d['deposit_amount_mxn'] ?? 0),
        'display_order' => (int) ($d['display_order'] ?? 0),
        'active' => isset($d['active']) ? 1 : 0,
        'branches' => array_map('intval', $d['branches'] ?? []),
        'package_items' => $packageItems,
    ];
    $service['slug'] = service_slug($service['slug']);
    $errors = [];

    if (mb_strlen($service['name']) < 3) $errors['name'] = 'Ingresa el nombre.';
    if ($service['duration_min'] < 5 || $service['duration_min'] > 480) $errors['duration_min'] = 'La duracion debe estar entre 5 y 480 minutos.';
    if ($service['price_mxn'] < 0) $errors['price_mxn'] = 'El precio no puede ser negativo.';
    if ($service['item_type'] === ServiceCatalogService::TYPE_PACKAGE) {
        if (!$service['package_items']) {
            $errors['package_items'] = 'Selecciona al menos un servicio incluido en el paquete.';
        }
        if ($service['precio_base_calculado'] < 0) {
            $errors['precio_base_calculado'] = 'El precio base calculado no puede ser negativo.';
        }
        if ($service['precio_final'] === null || $service['precio_final'] < 0) {
            $errors['precio_final'] = 'Ingresa un precio final valido para el paquete.';
        }
        if ($service['sessions_count'] < 1) {
            $errors['sessions_count'] = 'El paquete debe tener al menos una sesion.';
        }
    } else {
        $service['sessions_count'] = 1;
        $service['package_items'] = [];
        $service['precio_base_calculado'] = null;
        $service['precio_final'] = null;
    }
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
        $errors['slug'] = 'Ya existe un registro con ese identificador.';
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
                    $errors['active'] = 'No se puede desactivar un registro con citas activas.';
                }
            }
            if (!$errors) {
                if ($serviceId) {
                    Database::exec(
                        'UPDATE services
                         SET slug=?, name=?, category=?, description=?, item_type=?, duration_min=?, price_mxn=?,
                             precio_base_calculado=?, precio_final=?, sessions_count=?,
                             payment_required=?, payment_mode=?, price_locked=?, deposit_amount_mxn=?, active=?, display_order=?
                         WHERE id=?',
                        [
                            $s['slug'], $s['name'], $s['category'], $s['description'] ?: null, $s['item_type'],
                            $s['duration_min'], $s['price_mxn'], $s['precio_base_calculado'], $s['precio_final'],
                            $s['sessions_count'], $s['payment_required'],
                            $s['payment_mode'], $s['price_locked'], $s['deposit_amount_mxn'], $s['active'],
                            $s['display_order'], $serviceId,
                        ]
                    );
                    Database::exec('DELETE FROM service_branches WHERE service_id=?', [$serviceId]);
                    Auth::audit('service_update', 'service', $serviceId, ['item_type' => $s['item_type']]);
                    flash('success', ServiceCatalogService::typeLabel($s['item_type']) . ' actualizado correctamente.');
                } else {
                    Database::exec(
                        'INSERT INTO services
                            (slug, name, category, description, item_type, duration_min, price_mxn,
                             precio_base_calculado, precio_final, sessions_count,
                             payment_required, payment_mode, price_locked, deposit_amount_mxn, active, display_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $s['slug'], $s['name'], $s['category'], $s['description'] ?: null, $s['item_type'],
                            $s['duration_min'], $s['price_mxn'], $s['precio_base_calculado'], $s['precio_final'],
                            $s['sessions_count'], $s['payment_required'],
                            $s['payment_mode'], $s['price_locked'], $s['deposit_amount_mxn'], $s['active'],
                            $s['display_order'],
                        ]
                    );
                    $serviceId = Database::lastId();
                    Auth::audit('service_create', 'service', $serviceId, ['item_type' => $s['item_type']]);
                    flash('success', ServiceCatalogService::typeLabel($s['item_type']) . ' creado correctamente.');
                }
                foreach ($s['branches'] as $branchId) {
                    Database::exec('INSERT IGNORE INTO service_branches (service_id, branch_id) VALUES (?, ?)', [$serviceId, $branchId]);
                }
                if ($s['item_type'] === ServiceCatalogService::TYPE_PACKAGE) {
                    ServiceCatalogService::savePackageItems($serviceId, $s['package_items']);
                    ServiceCatalogService::updatePackagePrices($serviceId, (float) $s['precio_base_calculado'], (float) $s['precio_final']);
                } else {
                    ServiceCatalogService::savePackageItems($serviceId, []);
                    ServiceCatalogService::refreshPackagesContainingService($serviceId);
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
            flash('warning', 'No se puede desactivar un registro con citas activas.');
        } else {
            Database::exec('UPDATE services SET active=0 WHERE id=?', [$serviceId]);
            Auth::audit('service_deactivate', 'service', $serviceId);
            flash('success', 'Registro desactivado correctamente.');
        }
        redirect('admin/servicios.php');
    }
}

$services = Database::all(
    "SELECT s.*,
            COALESCE(s.item_type, 'service') AS item_type,
            COALESCE(s.sessions_count, 1) AS sessions_count,
            COALESCE(s.price_locked, 0) AS price_locked,
            GROUP_CONCAT(DISTINCT b.name ORDER BY b.display_order SEPARATOR ', ') AS branch_names,
            COUNT(DISTINCT a.id) AS appointment_count
     FROM services s
     LEFT JOIN service_branches sb ON sb.service_id=s.id
     LEFT JOIN branches b ON b.id=sb.branch_id
     LEFT JOIN appointments a ON a.service_id=s.id
     GROUP BY s.id
     ORDER BY s.active DESC, s.item_type, s.display_order, s.name"
);
$serviceBranchRows = Database::all('SELECT DISTINCT service_id, branch_id FROM service_branches');
$serviceBranches = [];
foreach ($serviceBranchRows as $row) {
    $serviceBranches[(int) $row['service_id']][] = (int) $row['branch_id'];
}
$packageItemsByService = ServiceCatalogService::packageItemsForServices(array_column($services, 'id'));
$simpleServices = ServiceCatalogService::simpleServicesForPackageOptions();

$pageTitle = 'Servicios';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h1 class="h4 fw-bold mb-1">Servicios y paquetes</h1>
    <p class="text-muted small mb-0">Gestiona tratamientos individuales y paquetes con precio controlado.</p>
  </div>
  <button class="btn btn-bnc-primary btn-sm" data-bs-toggle="modal" data-bs-target="#serviceCreateModal"><i class="bi bi-plus-lg"></i> Nuevo</button>
</div>

<?php if ($errors): ?><div class="alert alert-danger">Revisa los campos marcados antes de guardar.</div><?php endif; ?>

<div class="bnc-card">
  <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Catalogo operativo</h2></div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead><tr><th>Nombre</th><th>Tipo</th><th>Duracion</th><th>Precio</th><th>Sucursales</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($services as $service): ?>
          <?php $isPackage = ServiceCatalogService::normalizeType($service['item_type']) === ServiceCatalogService::TYPE_PACKAGE; ?>
          <tr>
            <td>
              <strong><?= e($service['name']) ?></strong><br>
              <small class="text-muted"><?= e($service['category']) ?> · <?= e($service['slug']) ?></small>
              <?php if ($isPackage && !empty($packageItemsByService[(int) $service['id']])): ?>
                <div class="small text-muted mt-1">
                  Incluye:
                  <?= e(implode(', ', array_map(
                      fn($i) => (int) $i['sessions_count'] . 'x ' . $i['name'],
                      $packageItemsByService[(int) $service['id']]
                  ))) ?>
                </div>
              <?php endif; ?>
            </td>
            <td><?= $isPackage ? '<span class="badge bg-primary">Paquete</span>' : '<span class="badge bg-light text-dark border">Servicio</span>' ?></td>
            <td><?= (int) $service['duration_min'] ?> min<?= $isPackage ? '<br><small class="text-muted">' . (int) $service['sessions_count'] . ' sesion(es)</small>' : '' ?></td>
            <td>
              <?= fmt_price((float) $service['price_mxn']) ?>
              <?php if ($isPackage): ?>
                <br><small class="text-muted">base <?= fmt_price((float) ($service['precio_base_calculado'] ?? $service['price_mxn'])) ?> · final</small>
              <?php endif; ?>
            </td>
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
$emptyService = [
    'id'=>0,'name'=>'','slug'=>'','category'=>'depilacion_laser','description'=>'',
    'item_type'=>ServiceCatalogService::TYPE_SERVICE,'duration_min'=>30,'price_mxn'=>'0.00',
    'precio_base_calculado'=>null,'precio_final'=>null,
    'sessions_count'=>1,'payment_required'=>0,'payment_mode'=>'none','price_locked'=>0,
    'deposit_amount_mxn'=>null,'display_order'=>0,'active'=>1,
];
$modalServices = array_merge([$emptyService], $services);
foreach ($modalServices as $service):
  $isCreate = (int) $service['id'] === 0;
  $modalId = $isCreate ? 'serviceCreateModal' : 'serviceEditModal-' . (int) $service['id'];
  $isErrored = $errors && $editingId === (int) $service['id'];
  $currentType = ServiceCatalogService::normalizeType($isErrored ? ($_POST['item_type'] ?? '') : ($service['item_type'] ?? 'service'));
  $selectedBranches = $isErrored ? array_map('intval', $_POST['branches'] ?? []) : ($serviceBranches[(int) $service['id']] ?? []);
  $selectedPackageItems = $isErrored
      ? ServiceCatalogService::normalizePackageItems($_POST['package_services'] ?? [], $_POST['package_sessions'] ?? [])
      : array_column($packageItemsByService[(int) $service['id']] ?? [], 'sessions_count', 'included_service_id');
  $packageBaseValue = $isErrored
      ? ServiceCatalogService::calculatePackageBasePrice($selectedPackageItems)
      : ($service['precio_base_calculado'] ?? ServiceCatalogService::calculatePackageBasePrice($selectedPackageItems));
  $packageFinalValue = $isErrored
      ? ($_POST['precio_final'] ?? ($_POST['price_mxn'] ?? $packageBaseValue))
      : ($service['precio_final'] ?? $service['price_mxn'] ?? $packageBaseValue);
?>
  <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
      <div class="modal-content" style="border-radius:16px">
        <form method="POST" class="service-catalog-form">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
          <div class="modal-header">
            <h5 class="modal-title"><?= $isCreate ? 'Nuevo registro' : 'Editar registro' ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="bnc-label">Tipo</label>
                <select name="item_type" class="form-select service-type-select">
                  <?php foreach (ServiceCatalogService::typeOptions() as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $currentType === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4"><label class="bnc-label">Nombre</label><input name="name" class="form-control <?= isset($errors['name']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['name'] ?? '') : $service['name']) ?>"><?php if(isset($errors['name']) && $isErrored): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?></div>
              <div class="col-md-4"><label class="bnc-label">Identificador</label><input name="slug" class="form-control <?= isset($errors['slug']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['slug'] ?? '') : $service['slug']) ?>"><?php if(isset($errors['slug']) && $isErrored): ?><div class="invalid-feedback"><?= e($errors['slug']) ?></div><?php endif; ?></div>
              <div class="col-md-3"><label class="bnc-label">Categoria</label><input name="category" class="form-control" value="<?= e($isErrored ? ($_POST['category'] ?? '') : $service['category']) ?>"></div>
              <div class="col-md-3"><label class="bnc-label">Duracion min</label><input type="number" min="5" max="480" name="duration_min" class="form-control <?= isset($errors['duration_min']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['duration_min'] ?? 30) : $service['duration_min']) ?>"></div>
              <div class="col-md-3 service-only"><label class="bnc-label">Precio MXN</label><input type="number" min="0" step="0.01" name="price_mxn" class="form-control <?= isset($errors['price_mxn']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['price_mxn'] ?? 0) : $service['price_mxn']) ?>"></div>
              <div class="col-md-3 package-only"><label class="bnc-label">Precio base calculado</label><input type="number" min="0" step="0.01" class="form-control package-base-field" value="<?= e($packageBaseValue) ?>" readonly></div>
              <div class="col-md-3 package-only"><label class="bnc-label">Precio final MXN</label><input type="number" min="0" step="0.01" name="precio_final" class="form-control package-final-field <?= isset($errors['precio_final']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($packageFinalValue) ?>"></div>
              <div class="col-md-3 package-only"><label class="bnc-label">Sesiones del paquete</label><input type="number" min="1" name="sessions_count" class="form-control <?= isset($errors['sessions_count']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['sessions_count'] ?? 1) : ($service['sessions_count'] ?? 1)) ?>"></div>
              <div class="col-md-9"><label class="bnc-label">Descripcion</label><input name="description" class="form-control" value="<?= e($isErrored ? ($_POST['description'] ?? '') : $service['description']) ?>"></div>
              <div class="col-md-3"><label class="bnc-label">Orden</label><input type="number" name="display_order" class="form-control" value="<?= e($isErrored ? ($_POST['display_order'] ?? 0) : $service['display_order']) ?>"></div>

              <div class="col-12 package-only">
                <label class="bnc-label d-block">Servicios incluidos</label>
                <div class="row g-2">
                  <?php foreach ($simpleServices as $item): ?>
                    <?php if ((int) $item['id'] === (int) $service['id']) continue; ?>
                    <?php $checked = array_key_exists((int) $item['id'], $selectedPackageItems); ?>
                    <div class="col-md-6">
                      <div class="border rounded p-2 h-100">
                        <div class="form-check">
                          <input class="form-check-input package-service-check" type="checkbox" name="package_services[]" value="<?= (int) $item['id'] ?>" data-price="<?= e($item['price_mxn']) ?>" id="pkg-<?= e($modalId) ?>-<?= (int) $item['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                          <label class="form-check-label fw-semibold" for="pkg-<?= e($modalId) ?>-<?= (int) $item['id'] ?>"><?= e($item['name']) ?></label>
                        </div>
                        <div class="d-flex gap-2 align-items-center mt-2">
                          <label class="small text-muted mb-0" for="pkg-sessions-<?= e($modalId) ?>-<?= (int) $item['id'] ?>">Sesiones</label>
                          <input class="form-control form-control-sm package-session-input" style="max-width:90px" type="number" min="1" name="package_sessions[<?= (int) $item['id'] ?>]" id="pkg-sessions-<?= e($modalId) ?>-<?= (int) $item['id'] ?>" value="<?= (int) ($selectedPackageItems[(int) $item['id']] ?? 1) ?>">
                          <span class="small text-muted"><?= (int) $item['duration_min'] ?> min · <?= fmt_price((float) $item['price_mxn']) ?></span>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if(isset($errors['package_items']) && $isErrored): ?><div class="text-danger small mt-1"><?= e($errors['package_items']) ?></div><?php endif; ?>
              </div>

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
              <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="payment_required" id="svc-pay-<?= e($modalId) ?>" <?= ($isErrored ? isset($_POST['payment_required']) : ($service['payment_required'] ?? 0)) ? 'checked' : '' ?>><label class="form-check-label" for="svc-pay-<?= e($modalId) ?>">Requiere pago en agenda</label></div></div>
              <div class="col-md-4">
                <label class="bnc-label">Modo de pago</label>
                <select name="payment_mode" class="form-select <?= isset($errors['payment_mode']) && $isErrored ? 'is-invalid' : '' ?>">
                  <?php $payMode = $isErrored ? ($_POST['payment_mode'] ?? 'none') : ($service['payment_mode'] ?? 'none'); ?>
                  <option value="none" <?= $payMode === 'none' ? 'selected' : '' ?>>Sin cobro</option>
                  <option value="deposit" <?= $payMode === 'deposit' ? 'selected' : '' ?>>Anticipo</option>
                  <option value="full" <?= $payMode === 'full' ? 'selected' : '' ?>>Pago total</option>
                </select>
              </div>
              <div class="col-md-4"><label class="bnc-label">Anticipo MXN</label><input type="number" min="0" step="0.01" name="deposit_amount_mxn" class="form-control <?= isset($errors['deposit_amount_mxn']) && $isErrored ? 'is-invalid' : '' ?>" value="<?= e($isErrored ? ($_POST['deposit_amount_mxn'] ?? 0) : ($service['deposit_amount_mxn'] ?? 0)) ?>"></div>
              <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" id="svc-active-<?= e($modalId) ?>" <?= ($isErrored ? isset($_POST['active']) : $service['active']) ? 'checked' : '' ?>><label class="form-check-label" for="svc-active-<?= e($modalId) ?>">Activo para nuevas citas</label></div></div>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-bnc-primary">Guardar</button></div>
        </form>
      </div>
    </div>
  </div>
<?php if (!$isCreate): ?>
  <div class="modal fade" id="serviceDeactivateModal-<?= (int) $service['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border-radius:16px"><form method="POST"><?= Csrf::input() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>"><div class="modal-header"><h5 class="modal-title">Desactivar registro</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="mb-0">Dejara de estar disponible para nuevas citas. El historial se conserva.</p></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button><button type="submit" class="btn btn-danger">Desactivar</button></div></form></div></div>
  </div>
<?php endif; endforeach; ?>

<script>
  document.querySelectorAll('.service-catalog-form').forEach(form => {
    const select = form.querySelector('.service-type-select');
    const baseField = form.querySelector('.package-base-field');
    const finalField = form.querySelector('.package-final-field');
    let finalTouched = select.value === 'package' && finalField?.value !== '';
    function recalcPackageBase() {
      if (!baseField) return;
      let total = 0;
      form.querySelectorAll('.package-service-check').forEach(check => {
        if (!check.checked) return;
        const row = check.closest('.border');
        const qty = Math.max(1, Number(row?.querySelector('.package-session-input')?.value || 1));
        total += Number(check.dataset.price || 0) * qty;
      });
      baseField.value = total.toFixed(2);
      if (finalField && !finalTouched) finalField.value = total.toFixed(2);
    }
    const sync = () => {
      const isPackage = select.value === 'package';
      form.querySelectorAll('.package-only').forEach(node => node.classList.toggle('d-none', !isPackage));
      form.querySelectorAll('.service-only').forEach(node => node.classList.toggle('d-none', isPackage));
      if (isPackage) recalcPackageBase();
    };
    select.addEventListener('change', sync);
    finalField?.addEventListener('input', () => { finalTouched = true; });
    form.querySelectorAll('.package-service-check, .package-session-input').forEach(control => {
      control.addEventListener('change', recalcPackageBase);
      control.addEventListener('input', recalcPackageBase);
    });
    sync();
  });
</script>

<?php if ($errors): ?>
  <script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal(document.getElementById('<?= e($editingId ? 'serviceEditModal-' . $editingId : 'serviceCreateModal') ?>')).show());</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
