<?php
function require_login() {
    if (empty($_SESSION['uid'])) {
        header('Location: /index.php'); exit;
    }
}
