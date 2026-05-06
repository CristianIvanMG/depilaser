<?php
require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();

$userSession = Auth::user();
$userId = (int) ($userSession['id'] ?? 0);

$profileCols = ClientProfile::selectExpr('u');
$namePartCols = ClientProfile::selectNamePartsExpr('u');

$profile = Database::one(
    "SELECT u.id, u.name, {$namePartCols}, u.email, u.phone, u.email_verified, u.active, {$profileCols}
     FROM users u
     WHERE u.id = ? AND u.active = 1
     LIMIT 1",
    [$userId]
);

if (!$profile) {
    Auth::logout();
    redirect('login.php');
}

$identity = ClientProfile::normalizeName([
    'first_name' => $profile['first_name'] ?? '',
    'last_name' => $profile['last_name'] ?? '',
    'name' => $profile['name'] ?? '',
]);

$profile['first_name'] = $identity['first_name'];
$profile['last_name'] = $identity['last_name'];
$profile['name'] = $identity['name'];

$errors = [];

function profile_payload(array $data): array
{
    $fullName = trim((string) ($data['full_name'] ?? ''));
    $identity = ClientProfile::normalizeName(['name' => $fullName]);

    return array_merge([
        'name' => $identity['name'],
        'first_name' => $identity['first_name'],
        'last_name' => $identity['last_name'],
        'email' => strtolower(trim((string) ($data['email'] ?? ''))),
        'phone' => preg_replace('/\D+/', '', (string) ($data['phone'] ?? '')),
    ], ClientProfile::normalize($data));
}

function profile_completion(array $profile): array
{
    $items = [
        'Nombre' => trim((string) ($profile['name'] ?? '')) !== '',
        'Correo' => trim((string) ($profile['email'] ?? '')) !== '',
        'Telefono' => trim((string) ($profile['phone'] ?? '')) !== '',
        'Fecha de nacimiento' => trim((string) ($profile['birth_date'] ?? '')) !== '',
        'Direccion' => trim((string) ($profile['address'] ?? '')) !== '',
    ];
    $done = count(array_filter($items));
    $total = count($items);

    return [
        'items' => $items,
        'done' => $done,
        'total' => $total,
        'percent' => (int) round(($done / max(1, $total)) * 100),
    ];
}

function validate_profile_payload(array $clean, int $userId): array
{
    $errors = [];

    if (mb_strlen($clean['name']) < 2) {
        $errors['full_name'] = 'Ingresa tu nombre completo.';
    }

    if (!$clean['email'] || !filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Ingresa un correo valido.';
    } elseif (Database::one('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$clean['email'], $userId])) {
        $errors['email'] = 'Ese correo ya esta registrado en otra cuenta.';
    }

    if (strlen($clean['phone']) !== 10) {
        $errors['phone'] = 'Ingresa un telefono de exactamente 10 digitos.';
    } elseif (Database::one('SELECT id FROM users WHERE phone = ? AND id <> ? LIMIT 1', [$clean['phone'], $userId])) {
        $errors['phone'] = 'Ese telefono ya esta registrado en otra cuenta.';
    }

    return array_merge($errors, ClientProfile::validate($clean));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');

    $clean = profile_payload($_POST);
    $clean['gender'] = $profile['gender'] ?? null;
    $errors = validate_profile_payload($clean, $userId);

    if (!$errors) {
        $emailChanged = strcasecmp((string) $profile['email'], $clean['email']) !== 0;
        $nameExtra = ClientProfile::nameSqlFragment($clean);
        $extra = ClientProfile::sqlFragment($clean);

        $sql = 'UPDATE users SET name = ?, email = ?, phone = ?';
        $params = [$clean['name'], $clean['email'], $clean['phone']];

        if ($emailChanged) {
            $sql .= ', email_verified = 0';
        }
        if ($nameExtra['set']) {
            $sql .= ', ' . $nameExtra['set'];
            $params = array_merge($params, $nameExtra['values']);
        }
        if ($extra['set']) {
            $sql .= ', ' . $extra['set'];
            $params = array_merge($params, $extra['values']);
        }

        $sql .= ', updated_at = NOW() WHERE id = ?';
        $params[] = $userId;

        Database::exec($sql, $params);
        unset($_SESSION['user_cache']);
        Auth::audit('profile_update', 'user', $userId, ['email_changed' => $emailChanged]);

        if ($emailChanged) {
            $verification = EmailVerification::issueAndSend($userId, true);
            flash(!empty($verification['mail_sent']) ? 'warning' : 'info', 'Actualizamos tu perfil. Confirma tu nuevo correo para seguir usando todas las funciones.');
            redirect('verificar-email.php?next=' . urlencode(app_base_path() . '/perfil.php'));
        }

        flash('success', 'Tu perfil se actualizo correctamente.');
        redirect('perfil.php');
    }
}

$form = $errors ? profile_payload($_POST) : $profile;
$completion = profile_completion($form);

$pageTitle = 'Mi perfil';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container py-4">
  <div class="bnc-profile-hero mb-4">
    <div>
      <span class="bnc-profile-kicker">Mi cuenta</span>
      <h1>Mi perfil</h1>
      <p>Revisa y completa tus datos para que podamos atenderte mejor en cada visita.</p>
    </div>
    <a href="<?= url('mis-citas.php') ?>" class="btn btn-bnc-primary">
      <i class="bi bi-calendar-check me-1"></i> Mis citas
    </a>
  </div>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="bnc-card h-100">
        <div class="bnc-card-header">
          <h2 class="h6 fw-bold mb-0">Datos completos</h2>
        </div>
        <div class="bnc-card-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="bnc-avatar" style="width:54px;height:54px;font-size:22px">
              <?= e(mb_substr((string) $form['name'], 0, 1)) ?>
            </div>
            <div>
              <div class="fw-bold"><?= e($form['name'] ?: 'Tu perfil') ?></div>
              <small class="text-muted"><?= e($form['email']) ?></small>
            </div>
          </div>

          <div class="progress mb-2" style="height:10px;border-radius:999px">
            <div class="progress-bar" style="width:<?= (int) $completion['percent'] ?>%;background:var(--bnc-pink)"></div>
          </div>
          <div class="small text-muted mb-3"><?= (int) $completion['percent'] ?>% completado</div>

          <div class="d-grid gap-2 small">
            <?php foreach ($completion['items'] as $label => $done): ?>
              <div class="d-flex align-items-center justify-content-between">
                <span><?= e($label) ?></span>
                <span class="<?= $done ? 'text-success' : 'text-muted' ?>">
                  <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                </span>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="alert alert-light border small mt-4 mb-0">
            Mantener tu informacion completa nos ayuda a darte seguimiento con mas claridad.
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="bnc-card">
        <div class="bnc-card-header">
          <h2 class="h6 fw-bold mb-0">Informacion personal</h2>
        </div>
        <div class="bnc-card-body">
          <?php if ($errors): ?>
            <div class="alert alert-danger small">Revisa los campos marcados antes de guardar.</div>
          <?php endif; ?>

          <form method="POST" novalidate>
            <?= Csrf::input() ?>

            <div class="mb-3">
              <label class="bnc-label" for="full_name">Nombre completo</label>
              <input id="full_name" name="full_name" class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>"
                     value="<?= e($form['name'] ?? '') ?>" required autocomplete="name">
              <?php if (isset($errors['full_name'])): ?><div class="invalid-feedback"><?= e($errors['full_name']) ?></div><?php endif; ?>
            </div>

            <div class="row g-3">
              <div class="col-md-7">
                <label class="bnc-label" for="email">Correo electronico</label>
                <input type="email" id="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                       value="<?= e($form['email'] ?? '') ?>" required autocomplete="email">
                <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-5">
                <label class="bnc-label" for="phone">Telefono</label>
                <input id="phone" name="phone" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                       value="<?= e($form['phone'] ?? '') ?>" required inputmode="tel" autocomplete="tel" placeholder="55 1234 5678">
                <?php if (isset($errors['phone'])): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-5">
                <label class="bnc-label" for="birth_date">Fecha de nacimiento <span class="text-muted fw-normal">(opcional)</span></label>
                <input type="date" id="birth_date" name="birth_date" class="form-control <?= isset($errors['birth_date']) ? 'is-invalid' : '' ?>"
                       value="<?= e($form['birth_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
                <?php if (isset($errors['birth_date'])): ?><div class="invalid-feedback"><?= e($errors['birth_date']) ?></div><?php endif; ?>
              </div>
              <div class="col-md-7">
                <label class="bnc-label" for="address">Direccion <span class="text-muted fw-normal">(opcional)</span></label>
                <input id="address" name="address" class="form-control <?= isset($errors['address']) ? 'is-invalid' : '' ?>"
                       value="<?= e($form['address'] ?? '') ?>" maxlength="255" autocomplete="street-address" placeholder="Calle, numero, colonia, ciudad">
                <?php if (isset($errors['address'])): ?><div class="invalid-feedback"><?= e($errors['address']) ?></div><?php endif; ?>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
              <a href="<?= url('mis-citas.php') ?>" class="btn btn-bnc-outline">Cancelar</a>
              <button type="submit" class="btn btn-bnc-primary">
                <i class="bi bi-check2-circle me-1"></i> Guardar cambios
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>
