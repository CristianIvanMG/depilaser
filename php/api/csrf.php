<?php
// /api/csrf.php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
echo json_encode(['csrf' => csrf_token()]);