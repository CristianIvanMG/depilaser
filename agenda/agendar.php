<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();
Auth::requireVerifiedEmail();
$user = Auth::user();

global $CONFIG;
$cfg = $CONFIG['business'];

// ── Validaciones de límites ──
$activos = (int) (Database::one(
    "SELECT COUNT(*) AS n FROM appointments a
     JOIN appointment_statuses s ON s.id = a.status_id
     WHERE a.user_id = ? AND a.start_at >= NOW() AND s.slug NOT IN ('cancelada','no_asistio')",
    [$user['id']]
)['n'] ?? 0);

if ($activos >= $cfg['max_active_per_user']) {
    flash('warning', "Tienes {$activos} citas activas. Cancela alguna antes de agendar otra (máx. {$cfg['max_active_per_user']}).");
    redirect('mis-citas.php');
}

// ── Procesar POST (paso 3 → confirmar) ──
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');

    $branchId  = (int) ($_POST['branch_id']  ?? 0);
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $startAt   = trim($_POST['start_at']     ?? '');
    $notes     = trim($_POST['notes']        ?? '');

    $errors = Validator::appointmentCreate($_POST);
    if (!$errors) {
        // Verificar service y branch existen
        $svc = Database::one('SELECT id, duration_min, name FROM services WHERE id = ? AND active = 1', [$serviceId]);
        $br  = Database::one('SELECT id FROM branches WHERE id = ? AND active = 1', [$branchId]);
        if (!$svc || !$br) {
            $errors['_'] = 'Servicio o sucursal inválido.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $startAt)) {
            $errors['_'] = 'Fecha/hora con formato inválido.';
        } else {
            $startTs = strtotime($startAt);
            $endTs   = $startTs + ((int) $svc['duration_min']) * 60;
            $startSql = date('Y-m-d H:i:s', $startTs);
            $endSql   = date('Y-m-d H:i:s', $endTs);

            // Reglas de negocio
            if ($startTs < time() + $cfg['booking_min_hours'] * 3600) {
                $errors['_'] = "Debes agendar con al menos {$cfg['booking_min_hours']} horas de anticipación.";
            } elseif ($startTs > time() + $cfg['booking_max_days'] * 86400) {
                $errors['_'] = "Solo puedes agendar hasta {$cfg['booking_max_days']} días en el futuro.";
            }

            if (!$errors) {
                // Anti doble-reserva con transacción + verificación final
                $pdo = Database::pdo();
                $pdo->beginTransaction();
                try {
                    // Lock pesimista: bloquea filas que pudieran solapar
                    $stmt = $pdo->prepare(
                        "SELECT id FROM appointments
                         WHERE branch_id = ?
                           AND status_id IN (SELECT id FROM appointment_statuses WHERE slug NOT IN ('cancelada','no_asistio'))
                           AND start_at < ? AND end_at > ?
                         FOR UPDATE"
                    );
                    $stmt->execute([$branchId, $endSql, $startSql]);
                    if ($stmt->fetch()) {
                        throw new RuntimeException('SLOT_TAKEN');
                    }

                    // Estado inicial: programada
                    $statusId = (int) Database::one("SELECT id FROM appointment_statuses WHERE slug = 'programada'")['id'];
                    $code = generate_appointment_code();

                    Database::exec(
                        'INSERT INTO appointments (code, user_id, branch_id, service_id, status_id, start_at, end_at, notes_client, source, created_by_user_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "web", ?)',
                        [$code, $user['id'], $branchId, $serviceId, $statusId, $startSql, $endSql, $notes ?: null, $user['id']]
                    );
                    $apptId = Database::lastId();
                    $pdo->commit();

                    Auth::audit('appointment_create', 'appointment', $apptId, ['code' => $code]);

                    flash('success', "¡Cita confirmada! Tu código es <strong>{$code}</strong>");
                    redirect('mis-citas.php');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    if ($e->getMessage() === 'SLOT_TAKEN') {
                        $errors['_'] = 'Ese horario acaba de ser tomado. Por favor elige otro.';
                    } else {
                        error_log('[agendar] ' . $e->getMessage());
                        $errors['_'] = 'Hubo un problema. Intenta de nuevo en unos segundos.';
                    }
                }
            }
        }
    }
}

// ── Datos para wizard ──
$branches = Database::all('SELECT id, slug, name, address, city, state FROM branches WHERE active = 1 ORDER BY display_order, name');

$selectedBranch  = (int) ($_GET['branch']  ?? $_POST['branch_id']  ?? 0);
$selectedService = (int) ($_GET['service'] ?? $_POST['service_id'] ?? 0);
$selectedDate    = $_GET['date'] ?? $_POST['date'] ?? '';

$services = [];
if ($selectedBranch) {
    $services = Database::all(
        'SELECT s.id, s.slug, s.name, s.description, s.duration_min, s.price_mxn
         FROM services s
         JOIN service_branches sb ON sb.service_id = s.id
         WHERE sb.branch_id = ? AND s.active = 1
         ORDER BY s.display_order, s.name',
        [$selectedBranch]
    );
}

$step = 1;
if ($selectedBranch)              $step = 2;
if ($selectedBranch && $selectedService) $step = 3;

$pageTitle = 'Agendar cita';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container py-4 py-md-5">

  <h1 class="h3 fw-bold mb-1">Agendar nueva cita</h1>
  <p class="text-muted mb-4">Sigue los 3 pasos. Tu cita queda confirmada al instante.</p>

  <!-- Stepper -->
  <div class="bnc-stepper">
    <div class="bnc-step <?= $step === 1 ? 'active' : ($step > 1 ? 'done' : '') ?>"><span class="num">1</span> Sucursal</div>
    <div class="bnc-step <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>"><span class="num">2</span> Servicio</div>
    <div class="bnc-step <?= $step === 3 ? 'active' : '' ?>"><span class="num">3</span> Fecha y hora</div>
  </div>

  <?php if (!empty($errors['_'])): ?>
    <div class="alert alert-danger"><?= e($errors['_']) ?></div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
    <!-- ════════ PASO 1: ELIGE SUCURSAL ════════ -->
    <div class="row g-3">
      <?php foreach ($branches as $b): ?>
        <div class="col-12 col-md-4">
          <a href="?branch=<?= (int) $b['id'] ?>" class="text-decoration-none text-reset">
            <div class="bnc-card h-100" style="cursor:pointer; transition:.2s;" onmouseover="this.style.borderColor='var(--bnc-pink)'" onmouseout="this.style.borderColor=''">
              <div class="bnc-card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                  <div class="bnc-stat-icon"><i class="bi bi-shop"></i></div>
                  <h2 class="h6 fw-bold mb-0"><?= e($b['name']) ?></h2>
                </div>
                <p class="small text-muted mb-2"><i class="bi bi-geo-alt"></i> <?= e($b['address']) ?></p>
                <p class="small text-muted mb-0"><?= e($b['city']) ?>, <?= e($b['state']) ?></p>
                <div class="mt-3 small fw-bold" style="color:var(--bnc-pink)">Elegir esta sucursal →</div>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

  <?php elseif ($step === 2): ?>
    <!-- ════════ PASO 2: ELIGE SERVICIO ════════ -->
    <div class="mb-3"><a href="<?= url('agendar.php') ?>" class="small text-decoration-none text-muted">← Cambiar sucursal</a></div>
    <div class="row g-3">
      <?php foreach ($services as $s): ?>
        <div class="col-12 col-md-6 col-lg-4">
          <a href="?branch=<?= $selectedBranch ?>&amp;service=<?= (int) $s['id'] ?>" class="text-decoration-none text-reset">
            <div class="bnc-card h-100" style="cursor:pointer;">
              <div class="bnc-card-body">
                <h3 class="h6 fw-bold mb-1"><?= e($s['name']) ?></h3>
                <?php if ($s['description']): ?>
                  <p class="small text-muted mb-2"><?= e($s['description']) ?></p>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                  <span class="small text-muted"><i class="bi bi-clock"></i> <?= (int) $s['duration_min'] ?> min</span>
                  <span class="fw-bold" style="color:var(--bnc-pink)"><?= fmt_price((float) $s['price_mxn']) ?></span>
                </div>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
    <!-- ════════ PASO 3: FECHA + HORA ════════ -->
    <?php
      $svc = Database::one('SELECT name, duration_min, price_mxn FROM services WHERE id = ?', [$selectedService]);
      $br  = Database::one('SELECT name, address FROM branches WHERE id = ?', [$selectedBranch]);
      $minDate = date('Y-m-d', time() + $cfg['booking_min_hours'] * 3600);
      $maxDate = date('Y-m-d', time() + $cfg['booking_max_days'] * 86400);
    ?>
    <div class="mb-3">
      <a href="?branch=<?= $selectedBranch ?>" class="small text-decoration-none text-muted">← Cambiar servicio</a>
    </div>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="bnc-card">
          <div class="bnc-card-body">
            <h2 class="h6 fw-bold mb-3">Elige fecha</h2>
            <input type="date" class="form-control" id="bookingDate"
                   min="<?= $minDate ?>" max="<?= $maxDate ?>"
                   value="<?= e($selectedDate ?: $minDate) ?>">

            <h2 class="h6 fw-bold mt-4 mb-3">Horarios disponibles</h2>
            <div id="slotsContainer">
              <p class="text-muted small">Selecciona una fecha para ver los horarios libres.</p>
            </div>

            <form method="POST" id="bookForm" class="mt-4 d-none">
              <?= Csrf::input() ?>
              <input type="hidden" name="branch_id"  value="<?= $selectedBranch ?>">
              <input type="hidden" name="service_id" value="<?= $selectedService ?>">
              <input type="hidden" name="start_at"   id="startAtInput">
              <div class="mb-3">
                <label class="bnc-label" for="notes">¿Algo que debamos saber? (opcional)</label>
                <textarea class="form-control" name="notes" id="notes" rows="2" maxlength="500" placeholder="Ej. Es mi primera vez, me interesan paquetes..."></textarea>
              </div>
              <button type="submit" class="btn btn-bnc-primary w-100 py-2">Confirmar cita <i class="bi bi-check2-circle ms-1"></i></button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="bnc-card sticky-top" style="top: 90px;">
          <div class="bnc-card-header">
            <h2 class="h6 fw-bold mb-0">Resumen de tu cita</h2>
          </div>
          <div class="bnc-card-body">
            <div class="mb-3">
              <small class="text-muted text-uppercase d-block mb-1" style="font-size:11px; letter-spacing:.5px">Sucursal</small>
              <strong><?= e($br['name']) ?></strong>
              <div class="small text-muted"><?= e($br['address']) ?></div>
            </div>
            <div class="mb-3">
              <small class="text-muted text-uppercase d-block mb-1" style="font-size:11px; letter-spacing:.5px">Servicio</small>
              <strong><?= e($svc['name']) ?></strong>
              <div class="small text-muted"><?= (int) $svc['duration_min'] ?> minutos</div>
            </div>
            <div class="mb-3">
              <small class="text-muted text-uppercase d-block mb-1" style="font-size:11px; letter-spacing:.5px">Fecha y hora</small>
              <strong id="summaryWhen" class="text-muted">Selecciona un horario</strong>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center">
              <span class="text-muted">Total</span>
              <strong style="color:var(--bnc-pink); font-size:20px"><?= fmt_price((float) $svc['price_mxn']) ?></strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script>
      (function () {
        const apiBase = '<?= url('api/disponibilidad.php') ?>';
        const branchId = <?= (int) $selectedBranch ?>;
        const serviceId = <?= (int) $selectedService ?>;
        const dateInput = document.getElementById('bookingDate');
        const slotsBox  = document.getElementById('slotsContainer');
        const form      = document.getElementById('bookForm');
        const startInp  = document.getElementById('startAtInput');
        const summary   = document.getElementById('summaryWhen');

        async function loadSlots(d) {
          slotsBox.innerHTML = '<div class="text-muted small">Cargando horarios…</div>';
          try {
            const r = await fetch(`${apiBase}?branch=${branchId}&service=${serviceId}&date=${encodeURIComponent(d)}`);
            const j = await r.json();
            if (!j.ok || !j.slots.length) {
              slotsBox.innerHTML = '<div class="alert alert-warning small mb-0">No hay horarios disponibles ese día. Prueba con otra fecha.</div>';
              form.classList.add('d-none');
              return;
            }
            slotsBox.innerHTML = j.slots.map(s =>
              `<button type="button" class="btn btn-bnc-outline btn-sm m-1 slot-btn" data-start="${s.start}" data-label="${s.label_long}">${s.label}</button>`
            ).join('');
            slotsBox.querySelectorAll('.slot-btn').forEach(btn => {
              btn.addEventListener('click', () => {
                slotsBox.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('btn-bnc-primary','active'));
                btn.classList.add('btn-bnc-primary','active');
                startInp.value = btn.dataset.start;
                summary.textContent = btn.dataset.label;
                summary.classList.remove('text-muted');
                form.classList.remove('d-none');
              });
            });
          } catch (err) {
            slotsBox.innerHTML = '<div class="alert alert-danger small mb-0">Error al cargar horarios. Recarga la página.</div>';
          }
        }

        dateInput.addEventListener('change', () => loadSlots(dateInput.value));
        if (dateInput.value) loadSlots(dateInput.value);
      })();
    </script>
  <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>
