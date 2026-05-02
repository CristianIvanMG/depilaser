<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::logout();
flash('info', 'Sesión cerrada. ¡Hasta pronto!');
redirect('login.php');
