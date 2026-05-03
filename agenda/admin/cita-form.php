<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$admin = Auth::user();
$appointmentId = (int) ($_GET['id'] ?? $_POST['appointment_id'] ?? 0);
$isEdit = $appointmentId > 0;
$errors = [];

function admin_appointment_form_nonce(): string
{
    if (empty($_SESSION['admin_appointment_form_nonce'])) {
        $_SESSION['admin_appointment_form_nonce'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_appointment_form_nonce'];
}

// Asegura schema de Profesionales (auto-migracion suave)
AppointmentService::ensureProfessionalSchema();

$statuses = Database::all('SELECT id, slug, name FROM appointment_statuses ORDER BY id');
$statusBySlug = [];
foreach ($statuses as $status) {
    $statusBySlug[$status['slug']] = (int) $status['id'];
}

$branches = Database::all('SELECT id, name FROM branches WHERE active = 1 ORDER BY display_order, name');

// Profesionales activos con sus sucursales (para selector dinamico via JS)
$professionalsRows = Database::all(
    "SELECT u.id, u.name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE r.slug = 'professional' AND u.active = 1
     ORDER BY u.name"
);
$proBranchRows = $professionalsRows
    ? Database::all(
        "SELECT user_id, branch_id FROM user_branches
         WHERE user_id IN (" . implode(',', array_fill(0, count($professionalsRows), '?')) . ")",
        array_column($professionalsRows, 'id')
      )
    : [];
$proBranches = [];
foreach ($proBranchRows as $r) {
    $proBranches[(int) $r['user_id']][] = (int) $r['branch_id'];
}
$professionals = array_map(fn($p) => [
    'id'       => (int) $p['id'],
    'name'     => $p['name'],
    'branches' => $proBranches[(int) $p['id']] ?? [],
], $professionalsRows);
$services = Database::all(
    'SELECT id, name, duration_min, price_mxn FROM services WHERE active = 1 ORDER BY display_order, name'
);
$clients = Database::all(
    "SELECT u.id, u.name, u.email, u.phone
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE r.slug = 'cliente' AND u.active = 1
     ORDER BY u.name
     LIMIT 300"
);
$sourceOptions = AppointmentService::sourceOptions();

$appointment = null;
if ($isEdit) {
    $appointment = Database::one(
        "SELECT a.*, u.name AS client_name, u.email AS client_email, u.phone AS client_phone,
                s.name AS service_name, st.slug AS status_slug, st.name AS status_name
         FROM appointments a
         JOIN users u ON u.id = a.user_id
         JOIN services s ON s.id = a.service_id
         JOIN appointment_statuses st ON st.id = a.status_id
         WHERE a.id = ? LIMIT 1",
        [$appointmentId]
    );
    if (!$appointment) {
        flash('danger', 'Cita no encontrada.');
        redirect('admin/');
    }
}

$form = [
    'client_mode' => $_POST['client_mode'] ?? 'existing',
    'user_id' => $_POST['user_id'] ?? ($appointment['user_id'] ?? ''),
    'client_name' => $_POST['client_name'] ?? '',
    'client_email' => $_POST['client_email'] ?? '',
    'client_phone' => $_POST['client_phone'] ?? '',
    'branch_id' => $_POST['branch_id'] ?? ($appointment['branch_id'] ?? ($branches[0]['id'] ?? '')),
    'service_id' => $_POST['service_id'] ?? ($appointment['service_id'] ?? ''),
    'professional_id' => $_POST['professional_id'] ?? ($appointment['professional_id'] ?? ''),
    'status_id' => $_POST['status_id'] ?? ($appointment['status_id'] ?? ($statusBySlug['programada'] ?? '')),
    'source' => $_POST['source'] ?? ($appointment['source'] ?? 'phone'),
    'start_at' => $_POST['start_at'] ?? ($appointment ? date('Y-m-d\TH:i', strtotime($appointment['start_at'])) : ''),
    'notes_admin' => $_POST['notes_admin'] ?? ($appointment['notes_admin'] ?? ''),
    'cancel_reason' => $_POST['cancel_reason'] ?? '',
];

function admin_validate_client_input(array $data): array
{
    $name = trim($data['client_name'] ?? '');
    $email = strtolower(trim($data['client_email'] ?? ''));
    $phone = preg_replace('/\D+/', '', $data['client_phone'] ?? '');
    $errors = [];

    if (mb_strlen($name) < 2) {
        $errors['client_name'] = 'Ingresa el nombre del cliente.';
    }
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['client_email'] = 'Ingresa un correo valido.';
    } elseif (Database::one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
        $errors['client_email'] = 'Ya existe un cliente con ese correo.';
    }
    if (strlen($phone) < 10) {
        $errors['client_phone'] = 'Ingresa un telefono de al menos 10 digitos.';
    }

    return ['ok' => !$errors, 'errors' => $errors, 'data' => compact('name', 'email', 'phone')];
}

function admin_create_client(array $data): int
{
    $validated = admin_validate_client_input($data);
    if (!$validated['ok']) {
        throw new RuntimeException('CLIENT_INVALID');
    }
    $client = $validated['data'];

    $roleId = (int) Database::one("SELECT id FROM roles WHERE slug = 'cliente' LIMIT 1")['id'];
    Database::exec(
        'INSERT INTO users (role_id, name, email, phone, password_hash, email_verified, active)
         VALUES (?, ?, ?, ?, ?, 1, 1)',
        [$roleId, $client['name'], $client['email'], $client['phone'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]
    );
    $userId = Database::lastId();
    Auth::audit('admin_client_create', 'user', $userId);

    return $userId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $isEdit) {
        Database::exec('DELETE FROM appointments WHERE id = ?', [$appointmentId]);
        Auth::audit('appointment_delete', 'appointment', $appointmentId, ['code' => $appointment['code'] ?? null]);
        flash('success', 'Cita eliminada definitivamente.');
        redirect('admin/');
    }

    if ($action === 'cancel' && $isEdit) {
        $cancelId = $statusBySlug['cancelada'] ?? 0;
        if (!$cancelId) {
            $errors['_'] = 'No existe el estado Cancelada.';
        } else {
            Database::exec(
                'UPDATE appointments
                 SET status_id = ?, cancelled_at = NOW(), cancelled_by_user_id = ?, cancel_reason = ?
                 WHERE id = ?',
                [$cancelId, $admin['id'], trim($_POST['cancel_reason'] ?? '') ?: null, $appointmentId]
            );
            Auth::audit('appointment_cancel_admin', 'appointment', $appointmentId, [
                'reason' => trim($_POST['cancel_reason'] ?? ''),
            ]);
            flash('success', 'Cita cancelada. El horario quedo liberado.');
            redirect('admin/cita-form.php?id=' . $appointmentId);
        }
    }

    if ($action === 'save') {
        if (!$isEdit) {
            $submittedNonce = (string) ($_POST['form_nonce'] ?? '');
            $sessionNonce = (string) ($_SESSION['admin_appointment_form_nonce'] ?? '');
            if (!$submittedNonce || !$sessionNonce || !hash_equals($sessionNonce, $submittedNonce)) {
                flash('warning', 'Esta cita ya fue procesada o el formulario expiró. Revisa el listado antes de crear una nueva.');
                redirect('admin/citas.php');
            }
            unset($_SESSION['admin_appointment_form_nonce']);
        }

        $clientMode = $_POST['client_mode'] ?? 'existing';
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($clientMode === 'new') {
            $validatedClient = admin_validate_client_input($_POST);
            if (!$validatedClient['ok']) {
                $errors = array_merge($errors, $validatedClient['errors']);
            }
        } elseif (!$userId || !Database::one(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.active = 1 AND r.slug = 'cliente' LIMIT 1",
            [$userId]
        )) {
            $errors['user_id'] = 'Selecciona un cliente.';
        }

        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $statusId = (int) ($_POST['status_id'] ?? 0);
        $source = $_POST['source'] ?? 'phone';
        $startAt = trim($_POST['start_at'] ?? '');
        $notesAdmin = trim($_POST['notes_admin'] ?? '');
        $startTs = $startAt ? strtotime(str_replace('T', ' ', $startAt)) : false;

        if (!isset($sourceOptions[$source])) {
            $errors['source'] = 'Selecciona un canal de origen valido.';
        }
        if (!$statusId || !Database::one('SELECT id FROM appointment_statuses WHERE id = ? LIMIT 1', [$statusId])) {
            $errors['status_id'] = 'Selecciona un estado valido.';
        }
        if ($startTs && $startTs < time()) {
            $sameStoredTime = $isEdit && date('Y-m-d H:i', strtotime($appointment['start_at'])) === date('Y-m-d H:i', $startTs);
            if (!$sameStoredTime) {
                $errors['start_at'] = 'No se pueden programar citas en una fecha u hora pasada.';
            }
        }

        $schedule = AppointmentService::validateSchedule($branchId, $serviceId, $startAt, $isEdit ? $appointmentId : null);
        if (!$schedule['ok']) {
            $errors = array_merge($errors, $schedule['errors']);
        }

        // Validacion de profesional (obligatorio si status = confirmada o atendida)
        $professionalId = (int) ($_POST['professional_id'] ?? 0);
        $statusSlug = '';
        foreach ($statuses as $st) { if ((int) $st['id'] === $statusId) { $statusSlug = $st['slug']; break; } }
        $professionalRequired = in_array($statusSlug, ['confirmada','atendida'], true);

        if ($professionalId > 0 && $schedule['ok']) {
            $vp = AppointmentService::validateProfessionalAssignment(
                $professionalId,
                $branchId,
                $schedule['start_sql'],
                $schedule['end_sql'],
                $isEdit ? $appointmentId : null
            );
            if (!$vp['ok']) {
                $errors['professional_id'] = $vp['error'];
            }
        } elseif ($professionalRequired) {
            $errors['professional_id'] = 'Asigna un profesional para confirmar la cita.';
        }

        if (!$errors) {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            try {
                if (AppointmentService::hasConflict($branchId, $schedule['start_sql'], $schedule['end_sql'], $isEdit ? $appointmentId : null, true)) {
                    throw new RuntimeException('SLOT_TAKEN');
                }

                if ($clientMode === 'new') {
                    $userId = admin_create_client($_POST);
                }

                if ($isEdit) {
                    $cancelSql = '';
                    $cancelParams = [];
                    $cancelStatusId = $statusBySlug['cancelada'] ?? -1;
                    $previousStatusId = (int) $appointment['status_id'];
                    if ($statusId === $cancelStatusId && $previousStatusId !== $cancelStatusId) {
                        $cancelSql = ', cancelled_at = NOW(), cancelled_by_user_id = ?';
                        $cancelParams[] = $admin['id'];
                    } elseif ($statusId !== $cancelStatusId && $previousStatusId === $cancelStatusId) {
                        $cancelSql = ', cancelled_at = NULL, cancelled_by_user_id = NULL, cancel_reason = NULL';
                    }

                    $before = [
                        'user_id' => (int) $appointment['user_id'],
                        'branch_id' => (int) $appointment['branch_id'],
                        'service_id' => (int) $appointment['service_id'],
                        'status_id' => (int) $appointment['status_id'],
                        'start_at' => $appointment['start_at'],
                        'end_at' => $appointment['end_at'],
                    ];
                    $updateParams = [
                        $userId,
                        $professionalId ?: null,
                        $branchId,
                        $serviceId,
                        $statusId,
                        $schedule['start_sql'],
                        $schedule['end_sql'],
                        $source,
                        $notesAdmin ?: null,
                    ];
                    $updateParams = array_merge($updateParams, $cancelParams, [$appointmentId]);
                    Database::exec(
                        'UPDATE appointments
                         SET user_id = ?, professional_id = ?, branch_id = ?, service_id = ?, status_id = ?,
                             start_at = ?, end_at = ?, source = ?, notes_admin = ?
                             ' . $cancelSql . '
                         WHERE id = ?',
                        $updateParams
                    );
                    $pdo->commit();
                    Auth::audit('appointment_update', 'appointment', $appointmentId, [
                        'before' => $before,
                        'after' => [
                            'user_id' => $userId,
                            'branch_id' => $branchId,
                            'service_id' => $serviceId,
                            'status_id' => $statusId,
                            'start_at' => $schedule['start_sql'],
                            'end_at' => $schedule['end_sql'],
                        ],
                    ]);
                    flash('success', 'Cita actualizada correctamente.');
                    redirect('admin/cita-form.php?id=' . $appointmentId);
                }

                $code = generate_appointment_code();
                $duplicate = Database::one(
                    "SELECT a.id, a.code
                     FROM appointments a
                     JOIN appointment_statuses st ON st.id = a.status_id
                     WHERE a.user_id = ?
                       AND a.branch_id = ?
                       AND a.service_id = ?
                       AND a.start_at = ?
                       AND a.end_at = ?
                       AND st.slug NOT IN ('cancelada','no_asistio')
                     LIMIT 1
                     FOR UPDATE",
                    [$userId, $branchId, $serviceId, $schedule['start_sql'], $schedule['end_sql']]
                );
                if ($duplicate) {
                    throw new RuntimeException('DUPLICATE_APPOINTMENT');
                }

                Database::exec(
                    'INSERT INTO appointments
                       (code, user_id, professional_id, branch_id, service_id, status_id, start_at, end_at, source, notes_admin, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $code,
                        $userId,
                        $professionalId ?: null,
                        $branchId,
                        $serviceId,
                        $statusId,
                        $schedule['start_sql'],
                        $schedule['end_sql'],
                        $source,
                        $notesAdmin ?: null,
                        $admin['id'],
                    ]
                );
                $newId = Database::lastId();
                $pdo->commit();
                Auth::audit('appointment_create_admin', 'appointment', $newId, ['code' => $code]);
                flash('success', 'Cita registrada correctamente. Código: ' . $code);
                redirect('admin/citas.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                if ($e->getMessage() === 'SLOT_TAKEN') {
                    $errors['start_at'] = 'Ese horario ya no tiene cabinas disponibles.';
                } elseif ($e->getMessage() === 'DUPLICATE_APPOINTMENT') {
                    $errors['_'] = 'Ya existe una cita igual para ese cliente, servicio y horario. Revisa el listado antes de crear otra.';
                } else {
                    error_log('[admin/cita-form] ' . $e->getMessage());
                    $errors['_'] = 'No fue posible guardar la cita. Intenta nuevamente.';
                }
            }
        }
    }
}

if (!$isEdit && empty($_SESSION['admin_appointment_form_nonce'])) {
    admin_appointment_form_nonce();
}

$pageTitle = $isEdit ? 'Editar cita' : 'Nueva cita';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a href="<?= url('admin/') ?>" class="btn btn-sm btn-bnc-outline"><i class="bi bi-arrow-left"></i> Dashboard</a>
  <a href="<?= url('admin/calendario.php') ?>" class="btn btn-sm btn-bnc-outline"><i class="bi bi-calendar3"></i> Calendario</a>
</div>

<?php if (!empty($errors['_'])): ?>
  <div class="alert alert-danger"><?= e($errors['_']) ?></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">
    <form method="POST" class="bnc-card" id="appointmentForm">
      <?= Csrf::input() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($isEdit): ?><input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>"><?php endif; ?>
      <?php if (!$isEdit): ?><input type="hidden" name="form_nonce" value="<?= e(admin_appointment_form_nonce()) ?>"><?php endif; ?>

      <div class="bnc-card-header">
        <h2 class="h6 fw-bold mb-0"><?= $isEdit ? 'Datos de la cita' : 'Crear cita administrativa' ?></h2>
      </div>
      <div class="bnc-card-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="bnc-label d-block">Cliente</label>
            <?php if (!$isEdit): ?>
              <div class="btn-group mb-3" role="group">
                <input type="radio" class="btn-check" name="client_mode" id="clientExisting" value="existing" <?= $form['client_mode'] !== 'new' ? 'checked' : '' ?>>
                <label class="btn btn-bnc-outline" for="clientExisting"><i class="bi bi-person-check"></i> Existente</label>
                <input type="radio" class="btn-check" name="client_mode" id="clientNew" value="new" <?= $form['client_mode'] === 'new' ? 'checked' : '' ?>>
                <label class="btn btn-bnc-outline" for="clientNew"><i class="bi bi-person-plus"></i> Nuevo</label>
              </div>
            <?php endif; ?>

            <div id="existingClientBox">
              <select name="user_id" class="form-select <?= isset($errors['user_id']) ? 'is-invalid' : '' ?>">
                <option value="">Seleccionar cliente...</option>
                <?php foreach ($clients as $client): ?>
                  <option value="<?= (int) $client['id'] ?>" <?= (int) $form['user_id'] === (int) $client['id'] ? 'selected' : '' ?>>
                    <?= e($client['name']) ?> - <?= e($client['phone'] ?: $client['email']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['user_id'])): ?><div class="invalid-feedback"><?= e($errors['user_id']) ?></div><?php endif; ?>
            </div>

            <div id="newClientBox" class="row g-3 d-none">
              <div class="col-md-4">
                <input class="form-control <?= isset($errors['client_name']) ? 'is-invalid' : '' ?>" name="client_name" value="<?= e($form['client_name']) ?>" placeholder="Nombre completo">
                <?php if (isset($errors['client_name'])): ?><div class="invalid-feedback"><?= e($errors['client_name']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-4">
                <input type="email" class="form-control <?= isset($errors['client_email']) ? 'is-invalid' : '' ?>" name="client_email" value="<?= e($form['client_email']) ?>" placeholder="correo@cliente.com">
                <?php if (isset($errors['client_email'])): ?><div class="invalid-feedback"><?= e($errors['client_email']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-4">
                <input class="form-control <?= isset($errors['client_phone']) ? 'is-invalid' : '' ?>" name="client_phone" value="<?= e($form['client_phone']) ?>" placeholder="Telefono">
                <?php if (isset($errors['client_phone'])): ?><div class="invalid-feedback"><?= e($errors['client_phone']) ?></div><?php endif; ?>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <label class="bnc-label">Sucursal</label>
            <select name="branch_id" class="form-select <?= isset($errors['branch_id']) ? 'is-invalid' : '' ?>">
              <?php foreach ($branches as $branch): ?>
                <option value="<?= (int) $branch['id'] ?>" <?= (int) $form['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['branch_id'])): ?><div class="invalid-feedback"><?= e($errors['branch_id']) ?></div><?php endif; ?>
          </div>

          <div class="col-md-6">
            <label class="bnc-label">Servicio</label>
            <select name="service_id" class="form-select <?= isset($errors['service_id']) ? 'is-invalid' : '' ?>">
              <option value="">Seleccionar servicio...</option>
              <?php foreach ($services as $service): ?>
                <option value="<?= (int) $service['id'] ?>" <?= (int) $form['service_id'] === (int) $service['id'] ? 'selected' : '' ?>>
                  <?= e($service['name']) ?> - <?= (int) $service['duration_min'] ?> min
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['service_id'])): ?><div class="invalid-feedback"><?= e($errors['service_id']) ?></div><?php endif; ?>
          </div>

          <div class="col-md-4">
            <label class="bnc-label">Fecha y hora</label>
            <input type="datetime-local" name="start_at" id="startAtInput" value="<?= e($form['start_at']) ?>" <?= !$isEdit ? 'min="' . e(date('Y-m-d\TH:i')) . '"' : '' ?> class="form-control <?= isset($errors['start_at']) ? 'is-invalid' : '' ?>">
            <?php if (isset($errors['start_at'])): ?><div class="invalid-feedback"><?= e($errors['start_at']) ?></div><?php endif; ?>
          </div>

          <div class="col-md-4">
            <label class="bnc-label">Estado</label>
            <select name="status_id" class="form-select <?= isset($errors['status_id']) ? 'is-invalid' : '' ?>">
              <?php foreach ($statuses as $status): ?>
                <option value="<?= (int) $status['id'] ?>" <?= (int) $form['status_id'] === (int) $status['id'] ? 'selected' : '' ?>><?= e($status['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['status_id'])): ?><div class="invalid-feedback"><?= e($errors['status_id']) ?></div><?php endif; ?>
          </div>

          <div class="col-md-4">
            <label class="bnc-label">Canal de origen</label>
            <select name="source" class="form-select <?= isset($errors['source']) ? 'is-invalid' : '' ?>">
              <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $form['source'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['source'])): ?><div class="invalid-feedback"><?= e($errors['source']) ?></div><?php endif; ?>
          </div>

          <div class="col-12">
            <label class="bnc-label">
              Profesional asignado
              <span class="text-muted small">(obligatorio para Confirmada / Atendida)</span>
            </label>
            <select name="professional_id" id="professionalSelect" class="form-select <?= isset($errors['professional_id']) ? 'is-invalid' : '' ?>">
              <option value="">Sin asignar</option>
              <?php foreach ($professionals as $p): ?>
                <option value="<?= $p['id'] ?>"
                        data-branches="<?= e(implode(',', $p['branches'])) ?>"
                        <?= (int) $form['professional_id'] === $p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['professional_id'])): ?>
              <div class="invalid-feedback"><?= e($errors['professional_id']) ?></div>
            <?php else: ?>
              <div class="form-text">Solo se muestran los profesionales asignados a la sucursal seleccionada. Los inactivos no aparecen.</div>
            <?php endif; ?>
            <?php if (!$professionals): ?>
              <div class="alert alert-warning small mt-2 mb-0">
                Aun no hay profesionales registrados. <a href="<?= url('admin/profesionales.php') ?>">Crea el primero</a>.
              </div>
            <?php endif; ?>
          </div>

          <div class="col-12">
            <div class="d-flex flex-wrap align-items-end gap-2 mb-2">
              <div>
                <label class="bnc-label" for="availabilityDateInput">Fecha para disponibilidad</label>
                <input type="date" id="availabilityDateInput" class="form-control form-control-sm" min="<?= e(date('Y-m-d')) ?>" value="<?= e($form['start_at'] ? substr($form['start_at'], 0, 10) : date('Y-m-d')) ?>">
              </div>
              <button type="button" class="btn btn-sm btn-bnc-outline mb-1" id="loadSlotsBtn"><i class="bi bi-clock"></i> Ver horarios libres</button>
              <span class="small text-muted mb-2">Selecciona un horario para llenar la fecha y hora de la cita.</span>
            </div>
            <div id="slotsBox" class="mb-3"></div>
          </div>

          <div class="col-12">
            <label class="bnc-label">Notas internas</label>
            <textarea name="notes_admin" rows="4" maxlength="2000" class="form-control" placeholder="Indicaciones para recepcion, seguimiento o contexto de la reserva."><?= e($form['notes_admin']) ?></textarea>
          </div>
        </div>
      </div>
      <div class="bnc-card-body border-top d-flex flex-wrap gap-2 justify-content-between">
        <button type="submit" class="btn btn-bnc-primary" id="saveAppointmentBtn"><i class="bi bi-check2-circle"></i> Guardar cita</button>
        <?php if ($isEdit): ?>
          <div class="d-flex flex-wrap gap-2">
            <?php if (($appointment['status_slug'] ?? '') !== 'cancelada'): ?>
              <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-calendar-x"></i> Cancelar</button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#deleteModal"><i class="bi bi-trash3"></i> Eliminar</button>
          </div>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="col-xl-4">
    <div class="bnc-card">
      <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Resumen operativo</h2></div>
      <div class="bnc-card-body">
        <?php if ($isEdit): ?>
          <div class="mb-3">
            <small class="text-muted text-uppercase d-block">Codigo</small>
            <code><?= e($appointment['code']) ?></code>
          </div>
          <div class="mb-3">
            <small class="text-muted text-uppercase d-block">Cliente actual</small>
            <strong><?= e($appointment['client_name']) ?></strong>
            <div class="small text-muted"><?= e($appointment['client_phone']) ?> <?= e($appointment['client_email']) ?></div>
          </div>
          <div class="mb-3">
            <small class="text-muted text-uppercase d-block">Estado</small>
            <span class="bnc-status <?= e($appointment['status_slug']) ?>"><?= e($appointment['status_name']) ?></span>
          </div>
          <?php
            $assignedProName = null;
            if (!empty($appointment['professional_id'])) {
                $rowProf = Database::one('SELECT name FROM users WHERE id = ? LIMIT 1', [(int) $appointment['professional_id']]);
                $assignedProName = $rowProf['name'] ?? null;
            }
          ?>
          <div class="mb-3">
            <small class="text-muted text-uppercase d-block">Profesional</small>
            <?php if ($assignedProName): ?>
              <strong><?= e($assignedProName) ?></strong>
            <?php else: ?>
              <span class="text-muted">Sin asignar</span>
            <?php endif; ?>
          </div>
          <?php if ($appointment['cancel_reason']): ?>
            <div class="alert alert-warning small mb-0"><strong>Motivo de cancelacion:</strong><br><?= e($appointment['cancel_reason']) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-muted small mb-0">Las citas creadas aqui quedan registradas como origen administrativo y pasan por las mismas reglas de disponibilidad que el flujo del cliente.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($isEdit): ?>
  <div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius:16px">
        <form method="POST">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
          <div class="modal-header">
            <h5 class="modal-title">Cancelar cita</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="mb-3">La cita se conserva para historial y estadisticas, pero el horario quedara libre.</p>
            <label class="bnc-label">Motivo (opcional)</label>
            <textarea name="cancel_reason" class="form-control" rows="3" maxlength="255"></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Mantener cita</button>
            <button type="submit" class="btn btn-danger">Confirmar cancelacion</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius:16px">
        <form method="POST">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">
          <div class="modal-header">
            <h5 class="modal-title">Eliminar definitivamente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger mb-0">Esta accion elimina el registro de forma permanente. Para operacion normal usa Cancelar.</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
            <button type="submit" class="btn btn-danger">Eliminar cita</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  (function () {
    const existing = document.getElementById('existingClientBox');
    const created = document.getElementById('newClientBox');
    const radios = document.querySelectorAll('input[name="client_mode"]');
    const branchSelect = document.querySelector('select[name="branch_id"]');
    const serviceSelect = document.querySelector('select[name="service_id"]');
    const startInput = document.getElementById('startAtInput');
    const availabilityDateInput = document.getElementById('availabilityDateInput');
    const loadSlotsBtn = document.getElementById('loadSlotsBtn');
    const appointmentForm = document.getElementById('appointmentForm');
    const saveAppointmentBtn = document.getElementById('saveAppointmentBtn');
    const slotsBox = document.getElementById('slotsBox');
    let slotRequest = null;
    let appointmentSubmitting = false;

    function syncClientMode() {
      const mode = document.querySelector('input[name="client_mode"]:checked')?.value || 'existing';
      existing.classList.toggle('d-none', mode === 'new');
      created.classList.toggle('d-none', mode !== 'new');
    }

    function setFieldState(field, isInvalid) {
      field.classList.toggle('is-invalid', isInvalid);
    }

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[char]));
    }

    function renderSlotMessage(type, message) {
      const klass = type === 'danger' ? 'alert-danger' : type === 'success' ? 'alert-success' : 'alert-warning';
      slotsBox.innerHTML = `<div class="alert ${klass} small py-2 mb-0">${escapeHtml(message)}</div>`;
    }

    function selectedDate() {
      return availabilityDateInput.value || (startInput.value || '').slice(0, 10);
    }

    function localIsoDate(date) {
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${date.getFullYear()}-${month}-${day}`;
    }

    function resetSlots(message = '') {
      slotsBox.innerHTML = message ? `<div class="text-muted small">${message}</div>` : '';
    }

    async function loadSlots() {
      const branchId = branchSelect.value;
      const serviceId = serviceSelect.value;
      const date = selectedDate();
      const todayIso = localIsoDate(new Date());

      setFieldState(branchSelect, !branchId);
      setFieldState(serviceSelect, !serviceId);
      setFieldState(availabilityDateInput, !date || date < todayIso);

      if (!branchId || !serviceId || !date) {
        renderSlotMessage('warning', 'Selecciona sucursal, servicio y fecha para ver horarios.');
        return;
      }
      if (date < todayIso) {
        renderSlotMessage('warning', 'Selecciona una fecha actual o futura.');
        return;
      }

      if (slotRequest) slotRequest.abort();
      slotRequest = new AbortController();
      loadSlotsBtn.disabled = true;
      loadSlotsBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Consultando';
      slotsBox.innerHTML = '<div class="text-muted small">Consultando horarios disponibles...</div>';

      try {
        const url = new URL('<?= url('api/disponibilidad.php') ?>', window.location.origin);
        url.searchParams.set('branch', branchId);
        url.searchParams.set('service', serviceId);
        url.searchParams.set('date', date);
        <?php if ($isEdit): ?>url.searchParams.set('ignore', '<?= (int) $appointmentId ?>');<?php endif; ?>
        const response = await fetch(url, {
          headers: { 'Accept': 'application/json' },
          signal: slotRequest.signal
        });
        const data = await response.json();

        if (!response.ok || !data.ok) {
          renderSlotMessage('danger', data.error || 'No fue posible consultar la disponibilidad.');
          return;
        }
        if (!data.slots || !data.slots.length) {
          renderSlotMessage('warning', data.note || 'No hay horarios disponibles para esa fecha.');
          return;
        }

        const currentValue = startInput.value;
        slotsBox.innerHTML = `
          <div class="small text-muted mb-2">${data.count || data.slots.length} horario(s) disponible(s). Selecciona uno para llenar fecha y hora.</div>
          <div class="d-flex flex-wrap gap-1">
            ${data.slots.map(slot => {
              const value = slot.start.replace(' ', 'T').slice(0, 16);
              const active = value === currentValue ? ' btn-bnc-primary active' : '';
              const cabins = slot.available_cabins ? ` · ${slot.available_cabins} cabina(s)` : '';
              return `<button type="button" class="btn btn-bnc-outline btn-sm slot-btn${active}" data-start="${escapeHtml(value)}" title="${escapeHtml((slot.label_long || slot.label) + cabins)}">${escapeHtml(slot.label)}${escapeHtml(cabins)}</button>`;
            }).join('')}
          </div>
        `;
        slotsBox.querySelectorAll('.slot-btn').forEach(button => {
          button.addEventListener('click', () => {
            startInput.value = button.dataset.start;
            availabilityDateInput.value = button.dataset.start.slice(0, 10);
            startInput.classList.remove('is-invalid');
            availabilityDateInput.classList.remove('is-invalid');
            slotsBox.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('btn-bnc-primary', 'active'));
            button.classList.add('btn-bnc-primary', 'active');
          });
        });
      } catch (err) {
        if (err.name !== 'AbortError') {
          renderSlotMessage('danger', 'No fue posible cargar la disponibilidad. Revisa tu conexion e intenta nuevamente.');
        }
      } finally {
        loadSlotsBtn.disabled = false;
        loadSlotsBtn.innerHTML = '<i class="bi bi-clock"></i> Ver horarios libres';
      }
    }

    // Filtra profesionales segun la sucursal seleccionada
    const professionalSelect = document.getElementById('professionalSelect');
    function syncProfessionals() {
      if (!professionalSelect) return;
      const branchId = String(branchSelect.value || '');
      const current  = professionalSelect.value;
      let firstVisible = null;
      Array.from(professionalSelect.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        const list = (opt.dataset.branches || '').split(',').filter(Boolean);
        const visible = !branchId || list.includes(branchId);
        opt.hidden = !visible;
        opt.disabled = !visible;
        if (visible && firstVisible === null) firstVisible = opt.value;
      });
      // Si el profesional actual ya no es valido para esa sucursal, lo limpia
      const curOpt = professionalSelect.querySelector(`option[value="${current}"]`);
      if (current && curOpt && curOpt.hidden) professionalSelect.value = '';
    }
    if (branchSelect && professionalSelect) {
      branchSelect.addEventListener('change', syncProfessionals);
      syncProfessionals();
    }

    radios.forEach(radio => radio.addEventListener('change', syncClientMode));
    appointmentForm.addEventListener('submit', function (event) {
      if (appointmentSubmitting) {
        event.preventDefault();
        return;
      }
      if (!appointmentForm.checkValidity()) {
        return;
      }
      appointmentSubmitting = true;
      saveAppointmentBtn.disabled = true;
      saveAppointmentBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando...';
      appointmentForm.querySelectorAll('button, input, select, textarea').forEach(control => {
        if (control !== saveAppointmentBtn && control.type !== 'hidden') control.readOnly = true;
      });
    });
    loadSlotsBtn.addEventListener('click', loadSlots);
    [branchSelect, serviceSelect].forEach(field => {
      field.addEventListener('change', () => resetSlots('La disponibilidad se actualizara al consultar de nuevo.'));
    });
    startInput.addEventListener('change', () => {
      if (startInput.value) availabilityDateInput.value = startInput.value.slice(0, 10);
      resetSlots('La disponibilidad se actualizara al consultar de nuevo.');
    });
    availabilityDateInput.addEventListener('change', () => resetSlots('La disponibilidad se actualizara al consultar de nuevo.'));
    syncClientMode();
  })();
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
