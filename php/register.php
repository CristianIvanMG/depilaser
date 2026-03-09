<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) { exit('CSRF inválido'); }

    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
        $_SESSION['flash'] = 'Datos inválidos.';
        header('Location: /register.php'); exit;
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT); // usa bcrypt/argon2 según PHP

    try {
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
        $stmt->execute([$email, $hash]);
        $_SESSION['flash'] = 'Registro exitoso. Inicia sesión.';
        header('Location: /index.php'); exit;
    } catch (PDOException $e) {
        // Si es duplicado
        $_SESSION['flash'] = 'El correo ya está registrado.';
        header('Location: /register.php'); exit;
    }
}
?>
<form method="POST">
  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
  <input type="email" name="email" required>
  <input type="password" name="password" required minlength="8">
  <button type="submit">Crear cuenta</button>
</form>