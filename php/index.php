<?php require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/functions.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Login</title></head>
<body>
  <?php if (!empty($_SESSION['flash'])) { echo '<p>'.htmlspecialchars($_SESSION['flash']).'</p>'; unset($_SESSION['flash']); } ?>

  <form method="POST" action="/login.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <label>
      Correo
      <input type="email" name="email" required>
    </label>
    <label>
      Contraseña
      <input type="password" name="password" required>
    </label>
    <button type="submit">Ingresar</button>
  </form>
</body>
</html>
