<?php
require __DIR__ . '/includes/config.php';
header('Content-Type: application/json');
if (empty($_SESSION['uid'])) {
  echo json_encode(['auth' => false]); exit;
}
echo json_encode(['auth' => true, 'email' => $_SESSION['email']]);
