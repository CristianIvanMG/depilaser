<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'BellaNick Agenda';
$user = Auth::user();
?><!DOCTYPE html>
<html lang="es-MX">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow"><!-- panel no debe indexarse -->
  <title><?= e($pageTitle) ?> · BellaNick Clinic</title>

  <link rel="icon" type="image/png" href="https://depilasermexico.com/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="bnc-client">

<nav class="navbar navbar-expand-lg bnc-navbar sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= url('') ?>">
      <span class="bnc-brand-mark">B</span>
      <span class="bnc-brand-text">BellaNick<small>Agenda</small></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
        <?php if ($user): ?>
          <li class="nav-item"><a class="nav-link <?= is_active('index.php') ?: (basename($_SERVER['SCRIPT_NAME']) === 'index.php' ? 'active' : '') ?>" href="<?= url('') ?>"><i class="bi bi-house-door"></i> Inicio</a></li>
          <li class="nav-item"><a class="nav-link <?= is_active('agendar') ?>" href="<?= url('agendar.php') ?>"><i class="bi bi-calendar-plus"></i> Nueva cita</a></li>
          <li class="nav-item"><a class="nav-link <?= is_active('mis-citas') ?>" href="<?= url('mis-citas.php') ?>"><i class="bi bi-calendar-check"></i> Mis citas</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
              <span class="bnc-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
              <span class="d-none d-lg-inline"><?= e($user['name']) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
              <li><a class="dropdown-item" href="<?= url('perfil.php') ?>"><i class="bi bi-person-circle me-2"></i>Mi perfil</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Salir</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="<?= url('login.php') ?>">Iniciar sesión</a></li>
          <li class="nav-item ms-lg-2"><a class="btn btn-bnc-primary" href="<?= url('register.php') ?>">Crear cuenta</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<?php $flash = flash_pop(); if ($flash): ?>
  <div class="container mt-3">
    <?php foreach ($flash as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<main class="bnc-main">
