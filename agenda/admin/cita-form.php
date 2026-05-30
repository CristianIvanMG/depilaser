<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$admin = Auth::user();
$appointmentId = (int) ($_GET['id'] ?? $_POST['appointment_id'] ?? 0);
$isEdit = $appointmentId > 0;
$rewardId = !$isEdit ? (int) ($_GET['reward_id'] ?? $_POST['reward_id'] ?? 0) : 0;
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
AppointmentService::ensureAppointmentDurationSchema();
AppointmentService::ensurePackageBillingSchema();
AppointmentService::ensureMachinerySchema();
EmailNotificationService::ensureSchema();
PaymentService::ensureSchema();
ServiceCatalogService::ensureSchema();
RewardsService::ensureSchema();

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
    "SELECT id, name, duration_min, price_mxn,
            COALESCE(item_type, 'service') AS item_type,
            COALESCE(sessions_count, 1) AS sessions_count
     FROM services
     WHERE active = 1
     ORDER BY item_type, display_order, name"
);
$clients = Database::all(
    "SELECT u.id, u.name, u.email, u.phone
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE r.slug = 'cliente' AND u.active = 1
     ORDER BY u.name"
);
$sourceOptions = AppointmentService::sourceOptions();
$defaultRewardSource = isset($sourceOptions['presencial']) ? 'presencial' : 'phone';

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

$rewardAppointment = null;
if ($rewardId > 0) {
    $rewardAppointment = Database::one(
        "SELECT cr.*, u.name AS client_name, u.email AS client_email, u.phone AS client_phone
         FROM client_rewards cr
         JOIN users u ON u.id = cr.client_id
         WHERE cr.id = ? AND cr.status = 'pendiente'
         LIMIT 1",
        [$rewardId]
    );
    if (!$rewardAppointment && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        flash('warning', 'La recompensa no esta pendiente o ya no existe.');
        redirect('admin/recompensas.php');
    }
}

$form = [
    'client_mode' => $_POST['client_mode'] ?? 'existing',
    'user_id' => $_POST['user_id'] ?? ($appointment['user_id'] ?? ($rewardAppointment['client_id'] ?? '')),
    'client_first_name' => $_POST['client_first_name'] ?? '',
    'client_last_name' => $_POST['client_last_name'] ?? '',
    'client_name' => $_POST['client_name'] ?? '',
    'client_email' => $_POST['client_email'] ?? '',
    'client_phone' => $_POST['client_phone'] ?? '',
    'branch_id' => $_POST['branch_id'] ?? ($appointment['branch_id'] ?? ($branches[0]['id'] ?? '')),
    'service_id' => $_POST['service_id'] ?? ($appointment['service_id'] ?? ''),
    'professional_id' => $_POST['professional_id'] ?? ($appointment['professional_id'] ?? ''),
    'status_id' => $_POST['status_id'] ?? ($appointment['status_id'] ?? ($statusBySlug['programada'] ?? '')),
    'source' => $_POST['source'] ?? ($appointment['source'] ?? ($rewardAppointment ? $defaultRewardSource : 'phone')),
    'start_at' => $_POST['start_at'] ?? ($appointment ? date('Y-m-d\TH:i', strtotime($appointment['start_at'])) : ''),
    'notes_admin' => $_POST['notes_admin'] ?? ($appointment['notes_admin'] ?? ($rewardAppointment ? ('Cita generada desde recompensa #' . (int) $rewardAppointment['id'] . ': ' . (string) $rewardAppointment['description']) : '')),
    'billing_type' => $_POST['billing_type'] ?? ($appointment['billing_type'] ?? 'standard'),
    'package_session_number' => $_POST['package_session_number'] ?? ($appointment['package_session_number'] ?? ''),
    'package_total_sessions' => $_POST['package_total_sessions'] ?? ($appointment['package_total_sessions'] ?? ''),
    'package_parent_appointment_id' => $_POST['package_parent_appointment_id'] ?? ($appointment['package_parent_appointment_id'] ?? ''),
    'cancel_reason' => $_POST['cancel_reason'] ?? '',
];

$selectedClientIds = array_values(array_unique(array_filter([
    (int) ($appointment['user_id'] ?? 0),
    (int) ($form['user_id'] ?? 0),
])));
if ($selectedClientIds) {
    $loadedClientIds = array_map('intval', array_column($clients, 'id'));
    $missingClientIds = array_values(array_diff($selectedClientIds, $loadedClientIds));
    if ($missingClientIds) {
        $missingClients = Database::all(
            "SELECT u.id, u.name, u.email, u.phone
             FROM users u
             WHERE u.id IN (" . implode(',', array_fill(0, count($missingClientIds), '?')) . ")
             ORDER BY u.name",
            $missingClientIds
        );
        $clients = array_merge($missingClients, $clients);
    }
}
$selectedClient = null;
foreach ($clients as $client) {
    if ((int) $client['id'] === (int) ($form['user_id'] ?? 0)) {
        $selectedClient = $client;
        break;
    }
}
$selectedClientLabel = $selectedClient
    ? trim($selectedClient['name'] . ' - ' . (($selectedClient['phone'] ?? '') ?: ($selectedClient['email'] ?? '')))
    : '';
$clientSearchOptions = array_map(static fn($client) => [
    'id' => (int) $client['id'],
    'name' => (string) ($client['name'] ?? ''),
    'email' => (string) ($client['email'] ?? ''),
    'phone' => (string) ($client['phone'] ?? ''),
    'label' => trim(($client['name'] ?? '') . ' - ' . (($client['phone'] ?? '') ?: ($client['email'] ?? ''))),
], $clients);
$selectedService = null;
foreach ($services as $service) {
    if ((int) $service['id'] === (int) ($form['service_id'] ?? 0)) {
        $selectedService = $service;
        break;
    }
}
$serviceSearchOptions = array_map(static function ($service) {
    $type = ServiceCatalogService::normalizeType($service['item_type'] ?? 'service');
    $sessions = (int) ($service['sessions_count'] ?? 1);
    $label = $service['name'] . ' - ' . (int) $service['duration_min'] . ' min';
    if ($type === ServiceCatalogService::TYPE_PACKAGE) {
        $label .= ' - ' . $sessions . ' sesion(es)';
    }
    return [
        'id' => (int) $service['id'],
        'name' => (string) ($service['name'] ?? ''),
        'duration' => (int) ($service['duration_min'] ?? 0),
        'sessions' => $sessions,
        'type' => $type,
        'typeLabel' => ServiceCatalogService::typeLabel($service['item_type'] ?? 'service'),
        'label' => $label,
    ];
}, $services);
$selectedServiceLabel = '';
if ($selectedService) {
    $selectedServiceType = ServiceCatalogService::normalizeType($selectedService['item_type'] ?? 'service');
    $selectedServiceLabel = $selectedService['name'] . ' - ' . (int) $selectedService['duration_min'] . ' min';
    if ($selectedServiceType === ServiceCatalogService::TYPE_PACKAGE) {
        $selectedServiceLabel .= ' - ' . (int) $selectedService['sessions_count'] . ' sesion(es)';
    }
}
$selectedBillingType = (string) ($form['billing_type'] ?? 'standard');
$selectedPackageSessionNumber = max(1, (int) ($form['package_session_number'] ?: 1));
$selectedPackageTotalSessions = max(1, (int) ($form['package_total_sessions'] ?: ($selectedService['sessions_count'] ?? 1)));
$selectedPackageParentId = (int) ($form['package_parent_appointment_id'] ?: 0);

function admin_validate_client_input(array $data): array
{
    $identity = ClientProfile::normalizeName($data);
    $firstName = $identity['first_name'];
    $lastName = $identity['last_name'];
    $name = $identity['name'];
    $email = strtolower(trim($data['client_email'] ?? ''));
    $phone = preg_replace('/\D+/', '', $data['client_phone'] ?? '');
    $errors = [];

    if (mb_strlen($firstName) < 2) {
        $errors['client_first_name'] = 'Ingresa el nombre del cliente.';
    }
    if (mb_strlen($lastName) < 2) {
        $errors['client_last_name'] = 'Ingresa los apellidos del cliente.';
    }
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['client_email'] = 'Ingresa un correo valido.';
    } elseif (Database::one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
        $errors['client_email'] = 'Ya existe un cliente con ese correo.';
    }
    if (strlen($phone) !== 10) {
        $errors['client_phone'] = 'Ingresa un telefono de exactamente 10 digitos.';
    }

    return ['ok' => !$errors, 'errors' => $errors, 'data' => [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
    ]];
}

function admin_create_client(array $data): int
{
    $validated = admin_validate_client_input($data);
    if (!$validated['ok']) {
        throw new RuntimeException('CLIENT_INVALID');
    }
    $client = $validated['data'];

    $roleId = (int) Database::one("SELECT id FROM roles WHERE slug = 'cliente' LIMIT 1")['id'];
    $cols = ['role_id', 'name', 'email', 'phone'];
    $vals = [$roleId, $client['name'], $client['email'], $client['phone']];
    $nameExtra = ClientProfile::nameSqlFragment($client);
    foreach ($nameExtra['cols'] as $i => $col) {
        $cols[] = $col;
        $vals[] = $nameExtra['values'][$i];
    }
    $cols[] = 'password_hash'; $vals[] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $cols[] = 'email_verified'; $vals[] = 1;
    $cols[] = 'active'; $vals[] = 1;
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    Database::exec(
        'INSERT INTO users (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')',
        $vals
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
        $result = AppointmentService::transitionStatus(
            $appointmentId,
            'cancelada',
            (int) $admin['id'],
            trim($_POST['cancel_reason'] ?? '') ?: null
        );
        if (!$result['ok']) {
            $errors['_'] = $result['error'] ?? 'No fue posible cancelar la cita.';
        } else {
            EmailNotificationService::sendForAppointment($appointmentId, 'appointment_cancelled');
            $msg = 'Cita cancelada. El horario quedó liberado.';
            if (!empty($result['waitlist']['ok'])) {
                $msg = 'Cita cancelada. Se promovió automáticamente a un cliente de la lista de espera.';
            }
            flash('success', $msg);
            redirect('admin/cita-form.php?id=' . $appointmentId);
        }
    }

    if ($action === 'save') {
        if ($isEdit && in_array((string) ($appointment['status_slug'] ?? ''), ['atendida', 'cancelada', 'no_asistio'], true)) {
            flash('warning', 'Esta cita esta en un estado final y ya no permite modificar sus datos.');
            redirect('admin/cita-form.php?id=' . $appointmentId);
        }
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
        $rewardToUse = null;

        if ($clientMode === 'new') {
            $validatedClient = admin_validate_client_input($_POST);
            if (!$validatedClient['ok']) {
                $errors = array_merge($errors, $validatedClient['errors']);
            }
        } else {
            $selectedUser = $userId ? Database::one(
                "SELECT u.id, u.active FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE u.id = ? AND r.slug = 'cliente' LIMIT 1",
                [$userId]
            ) : null;
            if (!$selectedUser) {
                $errors['user_id'] = 'Selecciona un cliente.';
            } elseif ((int) $selectedUser['active'] !== 1) {
                $errors['user_id'] = 'Este cliente esta inactivo. Activalo primero desde Clientes para poder agendar.';
            }
        }

        if (!$isEdit && $rewardId > 0) {
            $rewardToUse = Database::one(
                "SELECT * FROM client_rewards WHERE id = ? AND status = 'pendiente' LIMIT 1",
                [$rewardId]
            );
            if (!$rewardToUse) {
                $errors['_'] = 'La recompensa ya no esta disponible para agendar.';
            } elseif ($clientMode !== 'existing' || (int) $rewardToUse['client_id'] !== $userId) {
                $errors['user_id'] = 'La cita de recompensa debe conservar el cliente que gano el beneficio.';
            }
        }

        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $statusId = (int) ($_POST['status_id'] ?? 0);
        $source = $_POST['source'] ?? 'phone';
        $startAt = trim($_POST['start_at'] ?? '');
        $notesAdmin = trim($_POST['notes_admin'] ?? '');
        $selectedServiceForBilling = null;
        foreach ($services as $svcRow) {
            if ((int) $svcRow['id'] === $serviceId) {
                $selectedServiceForBilling = $svcRow;
                break;
            }
        }
        $isPackageAppointment = $selectedServiceForBilling
            && ServiceCatalogService::normalizeType($selectedServiceForBilling['item_type'] ?? 'service') === ServiceCatalogService::TYPE_PACKAGE;
        $billingMode = (string) ($_POST['package_billing_mode'] ?? '');
        $billingType = 'standard';
        $packageSessionNumber = null;
        $packageTotalSessions = null;
        $packageParentAppointmentId = null;
        if ($isPackageAppointment) {
            $packageTotalSessions = max(1, (int) ($_POST['package_total_sessions'] ?? ($selectedServiceForBilling['sessions_count'] ?? 1)));
            $packageSessionNumber = max(1, min($packageTotalSessions, (int) ($_POST['package_session_number'] ?? 1)));
            $packageParentAppointmentId = (int) ($_POST['package_parent_appointment_id'] ?? 0) ?: null;
            $billingType = $billingMode === 'included' ? 'package_session' : 'package_sale';
            if ($billingType === 'package_session' && $packageSessionNumber === 1) {
                $packageSessionNumber = min(2, $packageTotalSessions);
            }
        }
        if (
            $isEdit
            && $billingType === 'package_session'
            && (
                (string) ($appointment['payment_status'] ?? '') === 'paid'
                || !empty($appointment['receipt_folio'])
            )
        ) {
            $errors['_'] = 'Esta cita ya tiene pago o recibo registrado; no puede convertirse en sesion incluida de paquete.';
        }
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
        $professionalRequired = $statusSlug !== 'cancelada';
        $statusChanged = !$isEdit || (int) ($appointment['status_id'] ?? 0) !== $statusId;
        if ($isEdit && $statusChanged && $statusSlug) {
            $allowedStatusSlugs = AppointmentService::allowedTransitions((string) $appointment['status_slug']);
            if (!in_array($statusSlug, $allowedStatusSlugs, true)) {
                $errors['status_id'] = 'Ese cambio de estado no es válido para la cita actual.';
            }
        }
        if ($schedule['ok'] && $statusChanged) {
            $timingError = AppointmentService::statusTimingError($statusSlug, $schedule['start_sql'], $schedule['end_sql']);
            if ($timingError) {
                $errors['status_id'] = $timingError;
            }
        }

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
            $errors['professional_id'] = 'Asigna un profesional para esta cita.';
        }

        if (!$errors) {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            try {
                if (AppointmentService::hasConflict($branchId, $schedule['start_sql'], $schedule['end_sql'], $isEdit ? $appointmentId : null, true)) {
                    throw new RuntimeException('SLOT_TAKEN');
                }
                if (AppointmentService::hasMachineryConflict($branchId, $serviceId, $schedule['start_sql'], $schedule['end_sql'], $isEdit ? $appointmentId : null, true)) {
                    throw new RuntimeException('MACHINE_TAKEN');
                }
                if ($professionalId > 0 && AppointmentService::professionalHasConflict($professionalId, $schedule['start_sql'], $schedule['end_sql'], $isEdit ? $appointmentId : null, true)) {
                    throw new RuntimeException('PROFESSIONAL_TAKEN');
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
                    $resetsReminder = $appointment['start_at'] !== $schedule['start_sql']
                        || $appointment['end_at'] !== $schedule['end_sql']
                        || (int) $appointment['branch_id'] !== $branchId
                        || (int) $appointment['service_id'] !== $serviceId;
                    $reminderSql = $resetsReminder
                        ? ', email_reminder_sent = 0, email_reminder_sent_at = NULL, email_reminder_attempts = 0, email_reminder_last_error = NULL'
                        : '';
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
                        $billingType,
                        $packageParentAppointmentId,
                        $packageSessionNumber,
                        $packageTotalSessions,
                    ];
                    $updateParams = array_merge($updateParams, $cancelParams, [$appointmentId]);
                    Database::exec(
                        'UPDATE appointments
                         SET user_id = ?, professional_id = ?, branch_id = ?, service_id = ?, status_id = ?,
                             start_at = ?, end_at = ?, source = ?, notes_admin = ?,
                             billing_type = ?, package_parent_appointment_id = ?,
                             package_session_number = ?, package_total_sessions = ?,
                             payment_required = CASE WHEN ? = \'package_session\' THEN 0 ELSE payment_required END,
                             payment_status = CASE WHEN ? = \'package_session\' THEN \'not_required\' ELSE payment_status END,
                             payment_amount_mxn = CASE WHEN ? = \'package_session\' THEN 0.00 ELSE payment_amount_mxn END
                             ' . $cancelSql . $reminderSql . '
                         WHERE id = ?',
                        array_merge(
                            array_slice($updateParams, 0, 13),
                            [$billingType, $billingType, $billingType],
                            array_slice($updateParams, 13)
                        )
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
                    $postSaveWarning = null;
                    if ($previousStatusId !== $statusId) {
                        $emailType = match ($statusSlug) {
                            'confirmada' => 'appointment_confirmed',
                            'cancelada' => 'appointment_cancelled',
                            'no_asistio' => 'appointment_no_show',
                            'atendida' => null,
                            default => 'appointment_status_changed',
                        };
                        if ($emailType) {
                            EmailNotificationService::sendForAppointment($appointmentId, $emailType);
                        }
                        if ($statusSlug === 'atendida') {
                            $paymentResult = PaymentService::registerManualPayment(
                                $appointmentId,
                                (int) $admin['id'],
                                'manual',
                                null,
                                null,
                                true
                            );
                            if (empty($paymentResult['ok'])) {
                                $postSaveWarning = $paymentResult['error'] ?? 'La cita se marco como Atendida, pero el pago no pudo registrarse.';
                            } elseif (!empty($paymentResult['receipt_warning'])) {
                                $postSaveWarning = 'La cita se marco como Atendida y pagada, pero el recibo no pudo enviarse: ' . $paymentResult['receipt_warning'];
                            }
                        }
                        if ($statusSlug === 'cancelada') {
                            WaitlistService::promoteForCancelledAppointment($appointmentId);
                        }
                    }
                    if ($postSaveWarning) {
                        flash('warning', $postSaveWarning);
                    } else {
                        flash('success', 'Cita actualizada correctamente.');
                    }
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
                       (code, user_id, professional_id, branch_id, service_id, status_id, start_at, end_at, source, notes_admin, created_by_user_id,
                        billing_type, package_parent_appointment_id, package_session_number, package_total_sessions,
                        payment_required, payment_status, payment_amount_mxn)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                        $billingType,
                        $packageParentAppointmentId,
                        $packageSessionNumber,
                        $packageTotalSessions,
                        0,
                        'not_required',
                        0.0,
                    ]
                );
                $newId = Database::lastId();
                if ($rewardToUse) {
                    RewardsService::updateRewardStatus((int) $rewardToUse['id'], 'usado');
                    Auth::audit('reward_appointment_create', 'client_reward', (int) $rewardToUse['id'], [
                        'appointment_id' => $newId,
                        'client_id' => $userId,
                    ]);
                }
                $pdo->commit();
                Auth::audit('appointment_create_admin', 'appointment', $newId, ['code' => $code]);
                $emailType = $statusSlug === 'confirmada' ? 'appointment_confirmed' : 'appointment_created';
                EmailNotificationService::sendForAppointment($newId, $emailType);
                flash('success', 'Cita registrada correctamente. Código: ' . $code);
                redirect('admin/citas.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                if ($e->getMessage() === 'SLOT_TAKEN') {
                    $errors['start_at'] = 'Ese horario ya no tiene cabinas disponibles.';
                } elseif ($e->getMessage() === 'MACHINE_TAKEN') {
                    $errors['start_at'] = 'La maquinaria necesaria para ese servicio ya está ocupada en ese horario.';
                } elseif ($e->getMessage() === 'PROFESSIONAL_TAKEN') {
                    $errors['professional_id'] = 'Este profesional ya tiene una cita en ese horario, por favor selecciona otro horario o profesional.';
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

$finalStatus = $isEdit && in_array((string) ($appointment['status_slug'] ?? ''), ['atendida', 'cancelada', 'no_asistio'], true);
$pageTitle = $isEdit ? 'Editar cita' : 'Nueva cita';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<style>
  .bnc-client-combobox {
    position: relative;
  }

  .bnc-client-search {
    padding-right: 2.75rem;
  }

  .bnc-client-toggle {
    align-items: center;
    background: transparent;
    border: 0;
    color: #374151;
    display: flex;
    height: 100%;
    justify-content: center;
    position: absolute;
    right: .65rem;
    top: 0;
    width: 2rem;
  }

  .bnc-client-menu {
    background: #fff;
    border: 1px solid #f0cfe0;
    border-radius: 14px;
    box-shadow: 0 18px 40px rgba(58, 12, 43, .16);
    display: none;
    overflow-y: auto;
    padding: .35rem;
    position: fixed;
    z-index: 1080;
  }

  .bnc-client-menu.show {
    display: block;
  }

  .bnc-client-option {
    background: transparent;
    border: 0;
    border-radius: 10px;
    color: #15051a;
    display: block;
    padding: .7rem .8rem;
    text-align: left;
    width: 100%;
  }

  .bnc-client-option:hover,
  .bnc-client-option.active {
    background: #fff1f7;
  }

  .bnc-client-name {
    display: block;
    font-weight: 700;
    line-height: 1.2;
  }

  .bnc-client-meta {
    color: #6b7280;
    display: block;
    font-size: .85rem;
    line-height: 1.35;
    margin-top: .2rem;
    word-break: break-word;
  }

  .bnc-client-empty {
    color: #6b7280;
    padding: .75rem .85rem;
  }

  .bnc-package-billing-card {
    background: #f8fffb;
    border: 1px solid #bbf7d0;
    border-radius: 16px;
    padding: 1rem;
  }

  .bnc-package-billing-card .btn-check:checked + .btn {
    background: #16834a;
    border-color: #16834a;
    color: #fff;
  }

  .bnc-package-session-grid {
    display: grid;
    gap: .75rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .bnc-slot-grid {
    display: grid;
    gap: .5rem;
    grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
  }

  .bnc-slot-grid .slot-btn {
    min-height: 44px;
    white-space: normal;
  }

  .bnc-slot-grid .slot-btn[disabled] {
    opacity: .58;
  }

  @media (max-width: 768px) {
    .bnc-package-session-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

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
      <?php if ($rewardAppointment): ?><input type="hidden" name="reward_id" value="<?= (int) $rewardAppointment['id'] ?>"><?php endif; ?>

      <div class="bnc-card-header">
        <h2 class="h6 fw-bold mb-0"><?= $isEdit ? 'Datos de la cita' : 'Crear cita administrativa' ?></h2>
      </div>
      <div class="bnc-card-body">
        <?php if ($rewardAppointment): ?>
          <div class="alert alert-success d-flex flex-wrap gap-2 align-items-center mb-4">
            <i class="bi bi-gift-fill"></i>
            <div>
              <strong>Cita por recompensa pendiente</strong>
              <div class="small">Cliente: <?= e($rewardAppointment['client_name']) ?>. Esta cita se registra desde administracion, sin pago en linea, y la recompensa quedara marcada como usada al guardar.</div>
            </div>
          </div>
        <?php endif; ?>
        <div class="row g-3">
          <div class="col-12">
            <div class="bnc-admin-form-section">
              <span>1</span>
              <div><strong>Datos básicos</strong><small>Selecciona quién se atiende, dónde y con qué profesional.</small></div>
            </div>
          </div>
          <div class="col-12">
            <label class="bnc-label d-block">Cliente</label>
            <?php if (!$isEdit && !$rewardAppointment): ?>
              <div class="btn-group mb-3" role="group">
                <input type="radio" class="btn-check" name="client_mode" id="clientExisting" value="existing" <?= $form['client_mode'] !== 'new' ? 'checked' : '' ?>>
                <label class="btn btn-bnc-outline" for="clientExisting"><i class="bi bi-person-check"></i> Existente</label>
                <input type="radio" class="btn-check" name="client_mode" id="clientNew" value="new" <?= $form['client_mode'] === 'new' ? 'checked' : '' ?>>
                <label class="btn btn-bnc-outline" for="clientNew"><i class="bi bi-person-plus"></i> Nuevo</label>
              </div>
            <?php endif; ?>

            <div id="existingClientBox">
              <input type="hidden" name="user_id" id="clientUserIdInput" value="<?= e((string) $form['user_id']) ?>">
              <div class="bnc-client-combobox">
                <input
                  type="text"
                  id="clientSearchInput"
                  class="form-control bnc-client-search <?= isset($errors['user_id']) ? 'is-invalid' : '' ?>"
                  value="<?= e($selectedClientLabel) ?>"
                  placeholder="Buscar cliente por nombre, correo o telefono..."
                  autocomplete="off"
                  role="combobox"
                  aria-autocomplete="list"
                  aria-expanded="false"
                  aria-controls="clientSearchMenu">
                <button class="bnc-client-toggle" type="button" id="clientSearchToggle" aria-label="Mostrar clientes">
                  <i class="bi bi-chevron-down"></i>
                </button>
              </div>
              <div id="clientSearchMenu" class="bnc-client-menu" role="listbox"></div>
              <div class="form-text" id="clientSelectedHint">
                <?= $selectedClient ? 'Cliente seleccionado: ' . e($selectedClient['name']) : 'Escribe para buscar entre los clientes activos.' ?>
              </div>
              <?php if (isset($errors['user_id'])): ?><div class="invalid-feedback d-block"><?= e($errors['user_id']) ?></div><?php endif; ?>
            </div>

            <div id="newClientBox" class="row g-3 d-none">
              <div class="col-md-3">
                <input class="form-control <?= isset($errors['client_first_name']) ? 'is-invalid' : '' ?>" name="client_first_name" value="<?= e($form['client_first_name']) ?>" placeholder="Nombres" autocomplete="given-name">
                <?php if (isset($errors['client_first_name'])): ?><div class="invalid-feedback"><?= e($errors['client_first_name']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-3">
                <input class="form-control <?= isset($errors['client_last_name']) ? 'is-invalid' : '' ?>" name="client_last_name" value="<?= e($form['client_last_name']) ?>" placeholder="Apellidos" autocomplete="family-name">
                <?php if (isset($errors['client_last_name'])): ?><div class="invalid-feedback"><?= e($errors['client_last_name']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-3">
                <input type="email" class="form-control <?= isset($errors['client_email']) ? 'is-invalid' : '' ?>" name="client_email" value="<?= e($form['client_email']) ?>" placeholder="correo@cliente.com">
                <?php if (isset($errors['client_email'])): ?><div class="invalid-feedback"><?= e($errors['client_email']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-3">
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
            <label class="bnc-label">Servicio o paquete</label>
            <input type="hidden" name="service_id" id="serviceIdInput" value="<?= e((string) $form['service_id']) ?>">
            <div class="bnc-client-combobox">
              <input
                type="text"
                id="serviceSearchInput"
                class="form-control bnc-client-search <?= isset($errors['service_id']) ? 'is-invalid' : '' ?>"
                value="<?= e($selectedServiceLabel) ?>"
                placeholder="Buscar servicio o paquete..."
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded="false"
                aria-controls="serviceSearchMenu">
              <button class="bnc-client-toggle" type="button" id="serviceSearchToggle" aria-label="Mostrar servicios">
                <i class="bi bi-chevron-down"></i>
              </button>
            </div>
            <div id="serviceSearchMenu" class="bnc-client-menu" role="listbox"></div>
            <div class="form-text" id="serviceSelectedHint">
              <?= $selectedService ? 'Seleccionado: ' . e(ServiceCatalogService::typeLabel($selectedService['item_type'] ?? 'service')) : 'Escribe para buscar servicios y paquetes activos.' ?>
            </div>
            <?php if (isset($errors['service_id'])): ?><div class="invalid-feedback d-block"><?= e($errors['service_id']) ?></div><?php endif; ?>
          </div>

          <input type="hidden" name="start_at" id="startAtInput" value="<?= e($form['start_at']) ?>">

          <div class="col-12 d-none" id="packageBillingBox">
            <div class="bnc-package-billing-card">
              <input type="hidden" name="billing_type" id="billingTypeInput" value="<?= e($selectedBillingType) ?>">
              <div class="d-flex flex-wrap gap-2 align-items-start mb-3">
                <div class="me-auto">
                  <strong>Control de paquete</strong>
                  <div class="small text-muted">Usa "sesion incluida" para citas que ya fueron pagadas en la primera venta. No generan pago, recibo ni ingreso duplicado.</div>
                </div>
                <span class="badge bg-success">Anti doble cobro</span>
              </div>
              <div class="btn-group w-100 mb-3" role="group" aria-label="Tipo de cobro del paquete">
                <input type="radio" class="btn-check" name="package_billing_mode" id="packageBillingSale" value="sale" <?= $selectedBillingType !== 'package_session' ? 'checked' : '' ?>>
                <label class="btn btn-outline-success" for="packageBillingSale"><i class="bi bi-receipt"></i> Primera venta / cobrar</label>
                <input type="radio" class="btn-check" name="package_billing_mode" id="packageBillingIncluded" value="included" <?= $selectedBillingType === 'package_session' ? 'checked' : '' ?>>
                <label class="btn btn-outline-success" for="packageBillingIncluded"><i class="bi bi-check2-circle"></i> Sesion incluida ya pagada</label>
              </div>
              <div class="bnc-package-session-grid" id="packageIncludedFields">
                <div>
                  <label class="bnc-label">Sesion numero</label>
                  <input type="number" min="1" name="package_session_number" id="packageSessionNumberInput" class="form-control" value="<?= e((string) $selectedPackageSessionNumber) ?>">
                </div>
                <div>
                  <label class="bnc-label">Total vendidas</label>
                  <input type="number" min="1" name="package_total_sessions" id="packageTotalSessionsInput" class="form-control" value="<?= e((string) $selectedPackageTotalSessions) ?>">
                </div>
                <div>
                  <label class="bnc-label">Cita de venta <span class="text-muted fw-normal">(opcional)</span></label>
                  <input type="number" min="1" name="package_parent_appointment_id" class="form-control" value="<?= $selectedPackageParentId ? e((string) $selectedPackageParentId) : '' ?>" placeholder="ID primera cita">
                </div>
              </div>
            </div>
          </div>

          <div class="col-12">
            <label class="bnc-label">
              Profesional asignado
              <span class="text-muted small">(obligatorio)</span>
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
            <div class="bnc-admin-form-section">
              <span>2</span>
              <div><strong>Fecha</strong><small>La fecha filtra la disponibilidad real de sucursal, servicio y maquinaria.</small></div>
            </div>
          </div>

          <div class="col-12">
            <div class="bnc-admin-availability">
              <div class="bnc-admin-date-box">
                <label class="bnc-label" for="availabilityDateInput">Fecha de atención</label>
                <input type="date" id="availabilityDateInput" class="form-control <?= isset($errors['start_at']) ? 'is-invalid' : '' ?>" <?= !$isEdit ? 'min="' . e(date('Y-m-d')) . '"' : '' ?> value="<?= e($form['start_at'] ? substr($form['start_at'], 0, 10) : date('Y-m-d')) ?>">
                <?php if (isset($errors['start_at'])): ?><div class="invalid-feedback d-block"><?= e($errors['start_at']) ?></div><?php endif; ?>
              </div>
              <button type="button" class="btn btn-bnc-outline" id="loadSlotsBtn"><i class="bi bi-clock"></i> Ver horarios libres</button>
              <div class="bnc-selected-slot" id="selectedSlotSummary">
                <small>Horario elegido</small>
                <strong><?= $form['start_at'] ? e(fmt_dt(str_replace('T', ' ', $form['start_at']))) : 'Pendiente de seleccionar' ?></strong>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="bnc-admin-form-section">
              <span>3</span>
              <div><strong>Horarios disponibles</strong><small>Solo se muestran horarios que cumplen cabinas, profesional y maquinaria.</small></div>
            </div>
            <div id="slotsBox" class="mb-3"></div>
          </div>

          <div class="col-12">
            <div class="bnc-admin-form-section">
              <span>4</span>
              <div><strong>Datos administrativos</strong><small>Estado, canal y notas internas para recepción.</small></div>
            </div>
          </div>

          <div class="col-md-6">
            <label class="bnc-label">Estado</label>
            <?php if ($finalStatus): ?>
              <input type="hidden" name="status_id" value="<?= (int) $form['status_id'] ?>">
            <?php endif; ?>
            <select name="status_id" class="form-select <?= isset($errors['status_id']) ? 'is-invalid' : '' ?>" <?= $finalStatus ? 'disabled' : '' ?>>
              <?php foreach ($statuses as $status): ?>
                <option value="<?= (int) $status['id'] ?>" data-slug="<?= e($status['slug']) ?>" <?= (int) $form['status_id'] === (int) $status['id'] ? 'selected' : '' ?>><?= e($status['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($finalStatus): ?><div class="form-text">Esta cita esta en un estado final y ya no permite cambiar estado.</div><?php endif; ?>
            <?php if (isset($errors['status_id'])): ?><div class="invalid-feedback"><?= e($errors['status_id']) ?></div><?php endif; ?>
          </div>

          <div class="col-md-6">
            <label class="bnc-label">Canal de origen</label>
            <select name="source" class="form-select <?= isset($errors['source']) ? 'is-invalid' : '' ?>">
              <?php foreach ($sourceOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $form['source'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['source'])): ?><div class="invalid-feedback"><?= e($errors['source']) ?></div><?php endif; ?>
          </div>

          <div class="col-12">
            <label class="bnc-label">Notas internas</label>
            <textarea name="notes_admin" rows="4" maxlength="2000" class="form-control" placeholder="Indicaciones para recepcion, seguimiento o contexto de la reserva."><?= e($form['notes_admin']) ?></textarea>
          </div>
        </div>
      </div>
      <div class="bnc-card-body border-top d-flex flex-wrap gap-2 justify-content-between">
        <?php if ($finalStatus): ?>
          <div class="alert alert-warning mb-0 py-2">Esta cita ya esta en un estado final. Sus datos quedan bloqueados para evitar modificaciones posteriores.</div>
        <?php else: ?>
          <button type="submit" class="btn btn-bnc-primary" id="saveAppointmentBtn"><i class="bi bi-check2-circle"></i> Guardar cita</button>
        <?php endif; ?>
        <?php if ($isEdit): ?>
          <div class="d-flex flex-wrap gap-2">
            <?php if (!$finalStatus): ?>
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
    const clientUserIdInput = document.getElementById('clientUserIdInput');
    const clientSearchInput = document.getElementById('clientSearchInput');
    const clientSearchToggle = document.getElementById('clientSearchToggle');
    const clientSearchMenu = document.getElementById('clientSearchMenu');
    const clientSelectedHint = document.getElementById('clientSelectedHint');
    const serviceIdInput = document.getElementById('serviceIdInput');
    const serviceSearchInput = document.getElementById('serviceSearchInput');
    const serviceSearchToggle = document.getElementById('serviceSearchToggle');
    const serviceSearchMenu = document.getElementById('serviceSearchMenu');
    const serviceSelectedHint = document.getElementById('serviceSelectedHint');
    const branchSelect = document.querySelector('select[name="branch_id"]');
    const serviceSelect = serviceIdInput;
    const statusSelect = document.querySelector('select[name="status_id"]');
    const startInput = document.getElementById('startAtInput');
    const availabilityDateInput = document.getElementById('availabilityDateInput');
    const loadSlotsBtn = document.getElementById('loadSlotsBtn');
    const appointmentForm = document.getElementById('appointmentForm');
    const saveAppointmentBtn = document.getElementById('saveAppointmentBtn');
    const slotsBox = document.getElementById('slotsBox');
    const selectedSlotSummary = document.getElementById('selectedSlotSummary');
    const packageBillingBox = document.getElementById('packageBillingBox');
    const billingTypeInput = document.getElementById('billingTypeInput');
    const packageIncludedFields = document.getElementById('packageIncludedFields');
    const packageSessionNumberInput = document.getElementById('packageSessionNumberInput');
    const packageTotalSessionsInput = document.getElementById('packageTotalSessionsInput');
    const packageBillingModeInputs = document.querySelectorAll('input[name="package_billing_mode"]');
    const clientSearchData = <?= json_encode($clientSearchOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const serviceSearchData = <?= json_encode($serviceSearchOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const currentStatusSlug = <?= json_encode((string) ($appointment['status_slug'] ?? 'programada')) ?>;
    const clientResultLimit = 60;
    let clientVisibleOptions = [];
    let clientActiveIndex = -1;
    let serviceVisibleOptions = [];
    let serviceActiveIndex = -1;
    let slotRequest = null;
    let appointmentSubmitting = false;
    let selectedBusyProfessionals = [];

    function syncClientMode() {
      const mode = document.querySelector('input[name="client_mode"]:checked')?.value || 'existing';
      existing.classList.toggle('d-none', mode === 'new');
      created.classList.toggle('d-none', mode !== 'new');
      if (clientSearchInput) clientSearchInput.disabled = mode === 'new';
      if (mode === 'new') closeClientMenu();
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

    function normalizeClientText(value) {
      return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
    }

    function clientSearchHaystack(client) {
      return normalizeClientText(`${client.name} ${client.email} ${client.phone}`);
    }

    function positionClientMenu() {
      if (!clientSearchInput || !clientSearchMenu || !clientSearchMenu.classList.contains('show')) return;
      const rect = clientSearchInput.getBoundingClientRect();
      const sideGap = 12;
      const left = Math.max(sideGap, rect.left);
      const availableWidth = Math.max(220, window.innerWidth - left - sideGap);
      const width = Math.min(Math.max(rect.width, 240), availableWidth);
      const spaceBelow = window.innerHeight - rect.bottom - 14;
      clientSearchMenu.style.left = `${left}px`;
      clientSearchMenu.style.top = `${rect.bottom + 6}px`;
      clientSearchMenu.style.width = `${width}px`;
      clientSearchMenu.style.maxHeight = `${Math.max(120, Math.min(360, spaceBelow))}px`;
    }

    function openClientMenu() {
      if (!clientSearchInput || !clientSearchMenu || clientSearchInput.disabled) return;
      if (window.innerHeight - clientSearchInput.getBoundingClientRect().bottom < 150) {
        clientSearchInput.scrollIntoView({ block: 'center' });
      }
      clientSearchMenu.classList.add('show');
      clientSearchInput.setAttribute('aria-expanded', 'true');
      requestAnimationFrame(positionClientMenu);
    }

    function closeClientMenu() {
      if (!clientSearchInput || !clientSearchMenu) return;
      clientSearchMenu.classList.remove('show');
      clientSearchInput.setAttribute('aria-expanded', 'false');
      clientActiveIndex = -1;
      updateClientActiveOption();
    }

    function updateClientActiveOption() {
      if (!clientSearchMenu) return;
      clientSearchMenu.querySelectorAll('.bnc-client-option').forEach((option, index) => {
        option.classList.toggle('active', index === clientActiveIndex);
        if (index === clientActiveIndex) option.scrollIntoView({ block: 'nearest' });
      });
    }

    function renderClientOptions(query = '') {
      if (!clientSearchMenu) return;
      const normalizedQuery = normalizeClientText(query);
      const tokens = normalizedQuery.split(/\s+/).filter(Boolean);
      const matches = clientSearchData.filter(client => {
        if (!tokens.length) return true;
        const haystack = client.searchText || clientSearchHaystack(client);
        return tokens.every(token => haystack.includes(token));
      });
      clientVisibleOptions = matches.slice(0, clientResultLimit);
      clientActiveIndex = clientVisibleOptions.length ? 0 : -1;

      if (!clientVisibleOptions.length) {
        clientSearchMenu.innerHTML = '<div class="bnc-client-empty">No se encontraron clientes con esa busqueda.</div>';
        return;
      }

      const moreLabel = matches.length > clientResultLimit
        ? `<div class="bnc-client-empty">Mostrando ${clientResultLimit} de ${matches.length}. Escribe mas para afinar la busqueda.</div>`
        : '';
      clientSearchMenu.innerHTML = clientVisibleOptions.map((client, index) => {
        const meta = [client.phone, client.email].filter(Boolean).join(' - ');
        return `
          <button type="button" class="bnc-client-option ${index === clientActiveIndex ? 'active' : ''}" role="option" data-client-index="${index}">
            <span class="bnc-client-name">${escapeHtml(client.name || 'Cliente sin nombre')}</span>
            <span class="bnc-client-meta">${escapeHtml(meta || `ID ${client.id}`)}</span>
          </button>
        `;
      }).join('') + moreLabel;
    }

    function selectClient(client) {
      if (!client || !clientUserIdInput || !clientSearchInput) return;
      clientUserIdInput.value = client.id;
      clientSearchInput.value = client.label || client.name || '';
      clientSearchInput.classList.remove('is-invalid');
      if (clientSelectedHint) clientSelectedHint.textContent = `Cliente seleccionado: ${client.name || client.label}`;
      closeClientMenu();
    }

    function serviceSearchHaystack(service) {
      return normalizeClientText(`${service.name} ${service.typeLabel} ${service.label} ${service.duration} ${service.sessions}`);
    }

    function positionServiceMenu() {
      if (!serviceSearchInput || !serviceSearchMenu || !serviceSearchMenu.classList.contains('show')) return;
      const rect = serviceSearchInput.getBoundingClientRect();
      const sideGap = 12;
      const left = Math.max(sideGap, rect.left);
      const availableWidth = Math.max(220, window.innerWidth - left - sideGap);
      const width = Math.min(Math.max(rect.width, 240), availableWidth);
      const spaceBelow = window.innerHeight - rect.bottom - 14;
      serviceSearchMenu.style.left = `${left}px`;
      serviceSearchMenu.style.top = `${rect.bottom + 6}px`;
      serviceSearchMenu.style.width = `${width}px`;
      serviceSearchMenu.style.maxHeight = `${Math.max(120, Math.min(360, spaceBelow))}px`;
    }

    function openServiceMenu() {
      if (!serviceSearchInput || !serviceSearchMenu) return;
      if (window.innerHeight - serviceSearchInput.getBoundingClientRect().bottom < 150) {
        serviceSearchInput.scrollIntoView({ block: 'center' });
      }
      serviceSearchMenu.classList.add('show');
      serviceSearchInput.setAttribute('aria-expanded', 'true');
      requestAnimationFrame(positionServiceMenu);
    }

    function closeServiceMenu() {
      if (!serviceSearchInput || !serviceSearchMenu) return;
      serviceSearchMenu.classList.remove('show');
      serviceSearchInput.setAttribute('aria-expanded', 'false');
      serviceActiveIndex = -1;
      updateServiceActiveOption();
    }

    function updateServiceActiveOption() {
      if (!serviceSearchMenu) return;
      serviceSearchMenu.querySelectorAll('.bnc-client-option').forEach((option, index) => {
        option.classList.toggle('active', index === serviceActiveIndex);
        if (index === serviceActiveIndex) option.scrollIntoView({ block: 'nearest' });
      });
    }

    function renderServiceOptions(query = '') {
      if (!serviceSearchMenu) return;
      const normalizedQuery = normalizeClientText(query);
      const tokens = normalizedQuery.split(/\s+/).filter(Boolean);
      const matches = serviceSearchData.filter(service => {
        if (!tokens.length) return true;
        const haystack = service.searchText || serviceSearchHaystack(service);
        return tokens.every(token => haystack.includes(token));
      });
      serviceVisibleOptions = matches.slice(0, clientResultLimit);
      serviceActiveIndex = serviceVisibleOptions.length ? 0 : -1;

      if (!serviceVisibleOptions.length) {
        serviceSearchMenu.innerHTML = '<div class="bnc-client-empty">No se encontraron servicios o paquetes con esa busqueda.</div>';
        return;
      }

      const moreLabel = matches.length > clientResultLimit
        ? `<div class="bnc-client-empty">Mostrando ${clientResultLimit} de ${matches.length}. Escribe mas para afinar la busqueda.</div>`
        : '';
      serviceSearchMenu.innerHTML = serviceVisibleOptions.map((service, index) => `
        <button type="button" class="bnc-client-option ${index === serviceActiveIndex ? 'active' : ''}" role="option" data-service-index="${index}">
          <span class="bnc-client-name">${escapeHtml(service.name || 'Servicio sin nombre')}</span>
          <span class="bnc-client-meta">${escapeHtml(`${service.typeLabel} - ${service.duration} min${service.type === 'package' ? ` - ${service.sessions} sesion(es)` : ''}`)}</span>
        </button>
      `).join('') + moreLabel;
    }

    function selectService(service) {
      if (!service || !serviceIdInput || !serviceSearchInput) return;
      serviceIdInput.value = service.id;
      serviceSearchInput.value = service.label || service.name || '';
      serviceSearchInput.classList.remove('is-invalid');
      if (serviceSelectedHint) serviceSelectedHint.textContent = `Seleccionado: ${service.typeLabel || 'Servicio'}`;
      closeServiceMenu();
      if (service.type === 'package' && packageTotalSessionsInput) {
        packageTotalSessionsInput.value = String(Math.max(1, Number(service.sessions || 1)));
      }
      syncPackageBilling(service);
      serviceIdInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function currentPackageMode() {
      return document.querySelector('input[name="package_billing_mode"]:checked')?.value || 'sale';
    }

    function syncPackageBilling(service = null) {
      const selected = service || serviceSearchData.find(item => String(item.id) === String(serviceIdInput?.value || ''));
      const isPackage = selected?.type === 'package';
      if (packageBillingBox) packageBillingBox.classList.toggle('d-none', !isPackage);
      if (!isPackage) {
        if (billingTypeInput) billingTypeInput.value = 'standard';
        return;
      }
      const total = Math.max(1, Number(selected.sessions || packageTotalSessionsInput?.value || 1));
      if (packageTotalSessionsInput && (!packageTotalSessionsInput.value || Number(packageTotalSessionsInput.value) < 1)) {
        packageTotalSessionsInput.value = String(total);
      }
      const included = currentPackageMode() === 'included';
      if (billingTypeInput) billingTypeInput.value = included ? 'package_session' : 'package_sale';
      if (packageIncludedFields) packageIncludedFields.classList.toggle('d-none', !included);
      if (included && packageSessionNumberInput && Number(packageSessionNumberInput.value || 0) < 2 && total > 1) {
        packageSessionNumberInput.value = '2';
      }
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

    function formatSelectedSlot(value) {
      if (!value) return 'Pendiente de seleccionar';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return value.replace('T', ' ');
      return new Intl.DateTimeFormat('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        hour: 'numeric',
        minute: '2-digit'
      }).format(date);
    }

    function syncSelectedSlot() {
      if (!selectedSlotSummary) return;
      selectedSlotSummary.querySelector('strong').textContent = formatSelectedSlot(startInput.value);
    }

    function busyProfessionalSet() {
      return new Set((selectedBusyProfessionals || []).map(String));
    }

    function canCloseOutSelectedTime() {
      if (!startInput.value) return false;
      const start = new Date(startInput.value);
      if (Number.isNaN(start.getTime())) return false;
      const selectedService = serviceSearchData.find(service => String(service.id) === String(serviceSelect?.value || ''));
      const duration = Math.max(0, Number(selectedService?.duration || 0));
      const end = duration ? new Date(start.getTime() + duration * 60000) : start;
      const now = new Date();
      return now >= end;
    }

    function statusOptionBlocked(slug, canClose) {
      if (!slug) return false;
      if (['atendida', 'cancelada', 'no_asistio'].includes(currentStatusSlug)) {
        return slug !== currentStatusSlug;
      }
      if (currentStatusSlug === 'confirmada') {
        if (slug === 'programada') return true;
        if (['atendida', 'no_asistio'].includes(slug)) return !canClose;
        return !['confirmada', 'atendida', 'no_asistio', 'cancelada'].includes(slug);
      }
      if (currentStatusSlug === 'programada') {
        if (['atendida', 'no_asistio'].includes(slug)) return true;
        return !['programada', 'confirmada', 'cancelada'].includes(slug);
      }
      return ['atendida', 'no_asistio'].includes(slug) && !canClose;
    }

    function syncStatusOptions() {
      if (!statusSelect) return;
      const canClose = canCloseOutSelectedTime();
      Array.from(statusSelect.options).forEach(option => {
        const slug = option.dataset.slug || '';
        const blocked = statusOptionBlocked(slug, canClose) && !option.selected;
        option.disabled = blocked;
      });
      if (statusSelect.selectedOptions[0]?.disabled) {
        const fallback = Array.from(statusSelect.options).find(option => option.dataset.slug === currentStatusSlug && !option.disabled)
          || Array.from(statusSelect.options).find(option => option.dataset.slug === 'programada' && !option.disabled);
        if (fallback) statusSelect.value = fallback.value;
      }
    }

    async function loadSlots() {
      const branchId = branchSelect.value;
      const serviceId = serviceSelect.value;
      const date = selectedDate();
      const todayIso = localIsoDate(new Date());

      setFieldState(branchSelect, !branchId);
      setFieldState(serviceSelect, !serviceId);
      if (serviceSearchInput) setFieldState(serviceSearchInput, !serviceId);
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
        url.searchParams.set('include_unavailable', '1');
        url.searchParams.set('allow_immediate', '1');
        if (professionalSelect?.value) url.searchParams.set('professional', professionalSelect.value);
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
        const availableCount = data.count || data.slots.filter(slot => slot.available !== false).length;
        const totalCount = data.total_slots || data.slots.length;
        slotsBox.innerHTML = `
          <div class="small text-muted mb-2">${availableCount} disponible(s) de ${totalCount} horario(s). Los bloqueados muestran el motivo.</div>
          <div class="bnc-slot-grid">
            ${data.slots.map(slot => {
              const value = slot.start.replace(' ', 'T').slice(0, 16);
              const active = value === currentValue ? ' btn-bnc-primary active' : '';
              const available = slot.available !== false;
              const cabins = available && slot.available_cabins ? ` · ${slot.available_cabins} cabina(s)` : '';
              const busyPros = Array.isArray(slot.busy_professional_ids) ? slot.busy_professional_ids.join(',') : '';
              const reason = slot.reason || 'Horario no disponible.';
              const cls = available ? `btn-bnc-outline${active}` : 'btn-outline-secondary';
              const disabled = available ? '' : ' disabled aria-disabled="true"';
              const body = available
                ? `${escapeHtml(slot.label)}<small class="d-block">${escapeHtml(cabins.replace(' · ', ''))}</small>`
                : `${escapeHtml(slot.label)}<small class="d-block text-muted">${escapeHtml(reason)}</small>`;
              return `<button type="button" class="btn ${cls} btn-sm slot-btn" data-start="${escapeHtml(value)}" data-busy-pros="${escapeHtml(busyPros)}" title="${escapeHtml((slot.label_long || slot.label) + ' - ' + (available ? 'Disponible' : reason))}"${disabled}>${body}</button>`;
            }).join('')}
          </div>
        `;
        slotsBox.querySelectorAll('.slot-btn').forEach(button => {
            button.addEventListener('click', () => {
              startInput.value = button.dataset.start;
              selectedBusyProfessionals = (button.dataset.busyPros || '').split(',').filter(Boolean);
              availabilityDateInput.value = button.dataset.start.slice(0, 10);
              startInput.classList.remove('is-invalid');
              availabilityDateInput.classList.remove('is-invalid');
              syncSelectedSlot();
              syncStatusOptions();
              syncProfessionals();
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
      const busyIds = busyProfessionalSet();
      Array.from(professionalSelect.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; opt.disabled = false; return; }
        const list = (opt.dataset.branches || '').split(',').filter(Boolean);
        const availableAtSlot = !startInput.value || !busyIds.has(String(opt.value));
        const visible = (!branchId || list.includes(branchId)) && availableAtSlot;
        opt.hidden = !visible;
        opt.disabled = !visible;
        if (visible && firstVisible === null) firstVisible = opt.value;
      });
      // Si el profesional actual ya no es valido para esa sucursal u horario, lo limpia
      const curOpt = professionalSelect.querySelector(`option[value="${current}"]`);
      if (current && curOpt && curOpt.hidden) professionalSelect.value = '';
    }
    if (branchSelect && professionalSelect) {
      branchSelect.addEventListener('change', syncProfessionals);
      professionalSelect.addEventListener('change', () => {
        startInput.value = '';
        selectedBusyProfessionals = [];
        syncSelectedSlot();
        syncStatusOptions();
        professionalSelect.classList.remove('is-invalid');
        if (branchSelect.value && serviceSelect.value && availabilityDateInput.value) loadSlots();
      });
      syncProfessionals();
    }

    if (clientSearchInput && clientSearchMenu) {
      clientSearchData.forEach(client => {
        client.searchText = clientSearchHaystack(client);
      });
      renderClientOptions(clientSearchInput.value);
      clientSearchInput.addEventListener('focus', () => {
        renderClientOptions(clientSearchInput.value);
        openClientMenu();
      });
      clientSearchInput.addEventListener('input', () => {
        clientUserIdInput.value = '';
        clientSearchInput.classList.remove('is-invalid');
        if (clientSelectedHint) clientSelectedHint.textContent = 'Selecciona un cliente de la lista filtrada.';
        renderClientOptions(clientSearchInput.value);
        openClientMenu();
      });
      clientSearchInput.addEventListener('keydown', event => {
        if (!clientSearchMenu.classList.contains('show') && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
          openClientMenu();
          return;
        }
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          clientActiveIndex = Math.min(clientActiveIndex + 1, clientVisibleOptions.length - 1);
          updateClientActiveOption();
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          clientActiveIndex = Math.max(clientActiveIndex - 1, 0);
          updateClientActiveOption();
        } else if (event.key === 'Enter' && clientSearchMenu.classList.contains('show')) {
          event.preventDefault();
          selectClient(clientVisibleOptions[clientActiveIndex]);
        } else if (event.key === 'Escape') {
          closeClientMenu();
        }
      });
      clientSearchMenu.addEventListener('mousedown', event => {
        const option = event.target.closest('.bnc-client-option');
        if (!option) return;
        event.preventDefault();
        selectClient(clientVisibleOptions[Number(option.dataset.clientIndex)]);
      });
      clientSearchToggle?.addEventListener('click', () => {
        if (clientSearchMenu.classList.contains('show')) {
          closeClientMenu();
        } else {
          renderClientOptions(clientSearchInput.value);
          clientSearchInput.focus();
          openClientMenu();
        }
      });
      document.addEventListener('mousedown', event => {
        if (clientSearchInput.contains(event.target) || clientSearchMenu.contains(event.target) || clientSearchToggle?.contains(event.target)) return;
        closeClientMenu();
      });
      window.addEventListener('resize', positionClientMenu);
      window.addEventListener('scroll', positionClientMenu, true);
    }

    if (serviceSearchInput && serviceSearchMenu) {
      serviceSearchData.forEach(service => {
        service.searchText = serviceSearchHaystack(service);
      });
      renderServiceOptions(serviceSearchInput.value);
      serviceSearchInput.addEventListener('focus', () => {
        renderServiceOptions(serviceSearchInput.value);
        openServiceMenu();
        serviceSearchInput.select();
      });
      serviceSearchInput.addEventListener('click', () => {
        serviceSearchInput.select();
      });
      serviceSearchInput.addEventListener('input', () => {
        serviceIdInput.value = '';
        serviceSearchInput.classList.remove('is-invalid');
        if (serviceSelectedHint) serviceSelectedHint.textContent = 'Selecciona un servicio o paquete de la lista filtrada.';
        syncPackageBilling(null);
        renderServiceOptions(serviceSearchInput.value);
        openServiceMenu();
        serviceIdInput.dispatchEvent(new Event('change', { bubbles: true }));
      });
      serviceSearchInput.addEventListener('keydown', event => {
        if (!serviceSearchMenu.classList.contains('show') && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
          openServiceMenu();
          return;
        }
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          serviceActiveIndex = Math.min(serviceActiveIndex + 1, serviceVisibleOptions.length - 1);
          updateServiceActiveOption();
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          serviceActiveIndex = Math.max(serviceActiveIndex - 1, 0);
          updateServiceActiveOption();
        } else if (event.key === 'Enter' && serviceSearchMenu.classList.contains('show')) {
          event.preventDefault();
          selectService(serviceVisibleOptions[serviceActiveIndex]);
        } else if (event.key === 'Escape') {
          closeServiceMenu();
        }
      });
      serviceSearchMenu.addEventListener('mousedown', event => {
        const option = event.target.closest('.bnc-client-option');
        if (!option) return;
        event.preventDefault();
        selectService(serviceVisibleOptions[Number(option.dataset.serviceIndex)]);
      });
      serviceSearchToggle?.addEventListener('click', () => {
        if (serviceSearchMenu.classList.contains('show')) {
          closeServiceMenu();
        } else {
          renderServiceOptions(serviceSearchInput.value);
          serviceSearchInput.focus();
          openServiceMenu();
        }
      });
      document.addEventListener('mousedown', event => {
        if (serviceSearchInput.contains(event.target) || serviceSearchMenu.contains(event.target) || serviceSearchToggle?.contains(event.target)) return;
        closeServiceMenu();
      });
      window.addEventListener('resize', positionServiceMenu);
      window.addEventListener('scroll', positionServiceMenu, true);
    }

    packageBillingModeInputs.forEach(input => input.addEventListener('change', () => syncPackageBilling(null)));
    packageTotalSessionsInput?.addEventListener('input', () => {
      const total = Math.max(1, Number(packageTotalSessionsInput.value || 1));
      const current = Math.max(1, Number(packageSessionNumberInput?.value || 1));
      if (packageSessionNumberInput && current > total) packageSessionNumberInput.value = String(total);
      syncPackageBilling(null);
    });
    syncPackageBilling(null);

    radios.forEach(radio => radio.addEventListener('change', syncClientMode));
    appointmentForm.addEventListener('submit', function (event) {
      if (appointmentSubmitting) {
        event.preventDefault();
        return;
      }
      const clientMode = document.querySelector('input[name="client_mode"]:checked')?.value || 'existing';
      if (clientMode !== 'new' && clientUserIdInput && !clientUserIdInput.value) {
        event.preventDefault();
        clientSearchInput?.classList.add('is-invalid');
        if (clientSelectedHint) clientSelectedHint.textContent = 'Selecciona un cliente existente antes de guardar.';
        renderClientOptions(clientSearchInput?.value || '');
        openClientMenu();
        clientSearchInput?.focus();
        return;
      }
      if (serviceIdInput && !serviceIdInput.value) {
        event.preventDefault();
        serviceSearchInput?.classList.add('is-invalid');
        if (serviceSelectedHint) serviceSelectedHint.textContent = 'Selecciona un servicio o paquete antes de guardar.';
        renderServiceOptions(serviceSearchInput?.value || '');
        openServiceMenu();
        serviceSearchInput?.focus();
        return;
      }
      if (!startInput.value) {
        event.preventDefault();
        renderSlotMessage('warning', 'Selecciona un horario disponible antes de guardar la cita.');
        availabilityDateInput.classList.add('is-invalid');
        return;
      }
      if (professionalSelect?.value && busyProfessionalSet().has(String(professionalSelect.value))) {
        event.preventDefault();
        renderSlotMessage('warning', 'Este profesional ya tiene una cita en ese horario, por favor selecciona otro horario o profesional.');
        professionalSelect.classList.add('is-invalid');
        return;
      }
      if (!appointmentForm.checkValidity()) {
        return;
      }
      appointmentSubmitting = true;
      if (saveAppointmentBtn) {
        saveAppointmentBtn.disabled = true;
        saveAppointmentBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando...';
      }
      appointmentForm.querySelectorAll('button, input, select, textarea').forEach(control => {
        if (control !== saveAppointmentBtn && control.type !== 'hidden') control.readOnly = true;
      });
    });
    loadSlotsBtn.addEventListener('click', loadSlots);
    branchSelect.addEventListener('change', () => {
      startInput.value = '';
      selectedBusyProfessionals = [];
      syncSelectedSlot();
      syncStatusOptions();
      syncProfessionals();
      if (branchSelect.value && serviceSelect.value && availabilityDateInput.value) loadSlots();
      else resetSlots('Selecciona sucursal, servicio y fecha para ver horarios.');
    });
    serviceSelect.addEventListener('change', () => {
      syncStatusOptions();
      syncProfessionals();
      resetSlots('El horario elegido se mantiene. Usa "Ver horarios libres" solo si necesitas cambiarlo.');
    });
    availabilityDateInput.addEventListener('change', () => {
      startInput.value = '';
      selectedBusyProfessionals = [];
      syncSelectedSlot();
      syncStatusOptions();
      syncProfessionals();
      if (branchSelect.value && serviceSelect.value && availabilityDateInput.value) loadSlots();
      else resetSlots('Selecciona sucursal, servicio y fecha para ver horarios.');
    });
    syncClientMode();
    syncSelectedSlot();
    syncStatusOptions();
    if (branchSelect.value && serviceSelect.value && availabilityDateInput.value) {
      loadSlots();
    }
  })();
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
