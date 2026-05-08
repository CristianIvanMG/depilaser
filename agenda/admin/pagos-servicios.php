<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
PaymentService::ensureSchema();
ServiceCatalogService::ensureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $required = isset($_POST['payment_required']) ? 1 : 0;
    $mode = in_array(($_POST['payment_mode'] ?? 'none'), ['none','deposit','full'], true) ? $_POST['payment_mode'] : 'none';
    $deposit = max(0, (float) ($_POST['deposit_amount_mxn'] ?? 0));
    $service = Database::one("SELECT id, " . ServiceCatalogService::priceSql() . " AS price_mxn, COALESCE(item_type, 'service') AS item_type FROM services s WHERE id = ? LIMIT 1", [$serviceId]);
    if (!$service) {
        flash('danger', 'Servicio no encontrado.');
    } else {
        if (!$required) {
            $mode = 'none';
            $deposit = 0;
        }
        if ($required && $mode === 'deposit' && ($deposit <= 0 || $deposit > (float) $service['price_mxn'])) {
            flash('danger', 'El anticipo debe ser mayor a cero y no superar el precio configurado.');
        } elseif ($required && $mode === 'none') {
            flash('danger', 'Selecciona anticipo o pago total.');
        } else {
            Database::exec(
                'UPDATE services SET payment_required = ?, payment_mode = ?, deposit_amount_mxn = ? WHERE id = ?',
                [$required, $mode, $mode === 'deposit' ? $deposit : null, $serviceId]
            );
            Auth::audit('service_payment_update', 'service', $serviceId);
            flash('success', 'Configuración de pago actualizada.');
        }
    }
    redirect('admin/pagos-servicios.php');
}

$services = Database::all("SELECT id, name, duration_min, " . ServiceCatalogService::priceSql() . " AS price_mxn, payment_required, payment_mode, deposit_amount_mxn, active, COALESCE(item_type, 'service') AS item_type FROM services s ORDER BY active DESC, item_type, display_order, name");
$pageTitle = 'Pagos por servicio';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a href="<?= url('admin/servicios.php') ?>" class="btn btn-sm btn-bnc-outline"><i class="bi bi-tag"></i> Servicios</a>
</div>

<div class="bnc-card">
  <div class="bnc-card-header">
    <h2 class="h6 fw-bold mb-0">Pago anticipado por servicio o paquete</h2>
  </div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead><tr><th>Registro</th><th>Precio</th><th>Requiere pago</th><th>Tipo de cobro</th><th>Anticipo</th><th class="text-end">Guardar</th></tr></thead>
      <tbody>
        <?php foreach ($services as $service): ?>
          <tr>
            <form method="POST">
              <?= Csrf::input() ?>
              <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
              <td><strong><?= e($service['name']) ?></strong><br><small class="text-muted"><?= e(ServiceCatalogService::typeLabel($service['item_type'] ?? 'service')) ?> · <?= (int) $service['duration_min'] ?> min</small></td>
              <td><?= fmt_price((float) $service['price_mxn']) ?></td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="payment_required" <?= !empty($service['payment_required']) ? 'checked' : '' ?>>
                </div>
              </td>
              <td>
                <select name="payment_mode" class="form-select form-select-sm">
                  <option value="none" <?= ($service['payment_mode'] ?? 'none') === 'none' ? 'selected' : '' ?>>Sin cobro</option>
                  <option value="deposit" <?= ($service['payment_mode'] ?? '') === 'deposit' ? 'selected' : '' ?>>Anticipo</option>
                  <option value="full" <?= ($service['payment_mode'] ?? '') === 'full' ? 'selected' : '' ?>>Pago total</option>
                </select>
              </td>
              <td><input type="number" min="0" step="0.01" name="deposit_amount_mxn" class="form-control form-control-sm" value="<?= e($service['deposit_amount_mxn'] ?? '0.00') ?>"></td>
              <td class="text-end"><button class="btn btn-sm btn-bnc-primary" type="submit">Guardar</button></td>
            </form>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
