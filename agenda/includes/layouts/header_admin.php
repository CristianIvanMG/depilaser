<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'Panel BellaNick';
$user = Auth::user();
$adminHome = Auth::isAdmin() ? url('admin/') : url('admin/calendario.php');
?><!DOCTYPE html>
<html lang="es-MX">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin · <?= e($pageTitle) ?> · BellaNick</title>
  <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/admin.css') ?>">
</head>
<body class="bnc-admin">

<div class="bnc-admin-shell">

  <!-- SIDEBAR -->
  <aside class="bnc-sidebar" id="bncSidebar">
    <div class="bnc-sidebar-header">
      <a href="<?= $adminHome ?>" class="bnc-brand">
        <span class="bnc-brand-mark">B</span>
        <span class="bnc-brand-text">BellaNick<small>Admin</small></span>
      </a>
      <button class="btn btn-sm btn-outline-light d-lg-none" id="bncSidebarClose"><i class="bi bi-x-lg"></i></button>
    </div>

    <nav class="bnc-sidebar-nav">
      <?php if (Auth::isAdmin()): ?>
        <a href="<?= url('admin/') ?>" class="bnc-nav-item <?= basename($_SERVER['SCRIPT_NAME']) === 'index.php' && str_contains($_SERVER['REQUEST_URI'], '/admin') ? 'active' : '' ?>">
          <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
      <?php endif; ?>
      <a href="<?= url('admin/calendario.php') ?>" class="bnc-nav-item <?= is_active('calendario') ?>">
        <i class="bi bi-calendar3"></i><span>Calendario</span>
      </a>
      <?php if (Auth::isAdmin()): ?>
      <a href="<?= url('admin/citas.php') ?>" class="bnc-nav-item <?= is_active('citas') ?>">
        <i class="bi bi-list-check"></i><span>Citas</span>
      </a>
      <a href="<?= url('admin/reportes.php') ?>" class="bnc-nav-item <?= is_active('reportes') ?>">
        <i class="bi bi-graph-up-arrow"></i><span>Reportes</span>
      </a>
      <a href="<?= url('admin/lista-espera.php') ?>" class="bnc-nav-item <?= is_active('lista-espera') ?>">
        <i class="bi bi-hourglass-split"></i><span>Lista de espera</span>
      </a>
      <a href="<?= url('admin/progreso-tratamientos.php') ?>" class="bnc-nav-item <?= is_active('progreso-tratamientos') ?>">
        <i class="bi bi-heart-pulse"></i><span>Seguimiento</span>
      </a>
      <div class="bnc-nav-section">CONFIGURACIÓN</div>
       <a href="<?= url('admin/usuarios.php') ?>" class="bnc-nav-item <?= is_active('usuarios') ?>">
        <i class="bi bi-people"></i><span>Clientes</span>
      </a>
      <a href="<?= url('admin/profesionales.php') ?>" class="bnc-nav-item <?= is_active('profesionales') ?>">
        <i class="bi bi-person-badge"></i><span>Profesionales</span>
      </a>
      <a href="<?= url('admin/servicios.php') ?>" class="bnc-nav-item <?= is_active('servicios') ?>">
        <i class="bi bi-tag"></i><span>Servicios</span>
      </a>
      <a href="<?= url('admin/pagos-servicios.php') ?>" class="bnc-nav-item <?= is_active('pagos-servicios') ?>">
        <i class="bi bi-credit-card"></i><span>Pagos</span>
      </a>
      <a href="<?= url('admin/horarios.php') ?>" class="bnc-nav-item <?= is_active('horarios') ?>">
        <i class="bi bi-clock-history"></i><span>Horarios</span>
      </a>
      <?php if (Auth::isSuperAdmin()): ?>
        <a href="<?= url('admin/sucursales.php') ?>" class="bnc-nav-item <?= is_active('sucursales') ?>">
          <i class="bi bi-shop"></i><span>Sucursales</span>
        </a>
      <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="bnc-sidebar-foot">
      <div class="d-flex align-items-center gap-2">
        <span class="bnc-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
        <div class="flex-grow-1 lh-sm">
          <div class="fw-bold small text-truncate"><?= e($user['name']) ?></div>
          <div class="small text-muted text-uppercase"><?= e($user['role_slug']) ?></div>
        </div>
        <a href="<?= url('logout.php') ?>" class="btn btn-sm btn-outline-light" title="Salir"><i class="bi bi-box-arrow-right"></i></a>
      </div>
    </div>
  </aside>

  <!-- CONTENIDO -->
  <div class="bnc-admin-content">
    <header class="bnc-topbar">
      <button class="btn btn-outline-secondary d-lg-none me-2" id="bncSidebarOpen"><i class="bi bi-list"></i></button>
      <h1 class="h5 mb-0"><?= e($pageTitle) ?></h1>
      <div class="ms-auto d-flex align-items-center gap-2">
        <?php if (Auth::isAdmin()): ?>
          <a href="<?= url('admin/cita-form.php') ?>" class="btn btn-bnc-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Nueva cita
          </a>
        <?php endif; ?>
        <div class="bnc-topbar-user">
          <span class="bnc-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
          <span class="bnc-topbar-user-copy">
            <strong><?= e($user['name']) ?></strong>
            <small><?= e($user['role_slug']) ?></small>
          </span>
        </div>
      </div>
    </header>

    <?php $flash = flash_pop(); if ($flash): ?>
      <div class="px-3 px-md-4 pt-3">
        <?php foreach ($flash as $f): ?>
          <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show">
            <?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <main class="bnc-main">
