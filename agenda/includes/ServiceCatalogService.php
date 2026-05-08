<?php
declare(strict_types=1);

final class ServiceCatalogService
{
    public const TYPE_SERVICE = 'service';
    public const TYPE_PACKAGE = 'package';

    public static function ensureSchema(): void
    {
        self::ensureServiceColumn("item_type", "ENUM('service','package') NOT NULL DEFAULT 'service' AFTER description");
        self::ensureServiceColumn('precio_base_calculado', 'DECIMAL(10,2) NULL AFTER price_mxn');
        self::ensureServiceColumn('precio_final', 'DECIMAL(10,2) NULL AFTER precio_base_calculado');
        self::ensureServiceColumn('sessions_count', 'SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER price_mxn');
        self::ensureServiceColumn('price_locked', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER price_mxn');

        if (!self::tableExists('service_package_items')) {
            Database::exec(
                "CREATE TABLE service_package_items (
                    package_service_id SMALLINT UNSIGNED NOT NULL,
                    included_service_id SMALLINT UNSIGNED NOT NULL,
                    sessions_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                    display_order SMALLINT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (package_service_id, included_service_id),
                    INDEX idx_spi_included_service (included_service_id),
                    CONSTRAINT fk_spi_package FOREIGN KEY (package_service_id) REFERENCES services(id) ON DELETE CASCADE,
                    CONSTRAINT fk_spi_service FOREIGN KEY (included_service_id) REFERENCES services(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        self::ensurePackageItemsColumn('sessions_count', 'SMALLINT UNSIGNED NOT NULL DEFAULT 1');
        self::ensurePackageItemsColumn('display_order', 'SMALLINT NOT NULL DEFAULT 0');
        self::backfillPackagePrices();
    }

    public static function activeCatalogSqlSelect(string $alias = 's'): string
    {
        return "{$alias}.id, {$alias}.slug, {$alias}.name, {$alias}.description, {$alias}.duration_min,
                " . self::priceSql($alias) . " AS price_mxn,
                {$alias}.payment_required, {$alias}.payment_mode, {$alias}.deposit_amount_mxn,
                COALESCE({$alias}.item_type, 'service') AS item_type,
                COALESCE({$alias}.sessions_count, 1) AS sessions_count,
                COALESCE({$alias}.price_locked, 0) AS price_locked,
                {$alias}.precio_base_calculado,
                {$alias}.precio_final";
    }

    public static function priceSql(string $alias = 's'): string
    {
        return "CASE
            WHEN COALESCE({$alias}.item_type, 'service') = 'package'
            THEN COALESCE({$alias}.precio_final, {$alias}.price_mxn)
            ELSE {$alias}.price_mxn
        END";
    }

    public static function typeOptions(): array
    {
        return [
            self::TYPE_SERVICE => 'Servicio individual',
            self::TYPE_PACKAGE => 'Paquete',
        ];
    }

    public static function normalizeType(?string $type): string
    {
        return $type === self::TYPE_PACKAGE ? self::TYPE_PACKAGE : self::TYPE_SERVICE;
    }

    public static function typeLabel(?string $type): string
    {
        return self::normalizeType($type) === self::TYPE_PACKAGE ? 'Paquete' : 'Servicio';
    }

    public static function paymentItemLabel(array $service): string
    {
        return self::typeLabel($service['item_type'] ?? self::TYPE_SERVICE);
    }

    public static function packageItemsForServices(array $serviceIds): array
    {
        $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds))));
        if (!$serviceIds) {
            return [];
        }
        self::ensureSchema();
        $rows = Database::all(
            "SELECT spi.package_service_id, spi.included_service_id, spi.sessions_count,
                    s.name, s.duration_min, s.price_mxn
             FROM service_package_items spi
             JOIN services s ON s.id = spi.included_service_id
             WHERE spi.package_service_id IN (" . implode(',', array_fill(0, count($serviceIds), '?')) . ")
             ORDER BY spi.package_service_id, spi.display_order, s.name",
            $serviceIds
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['package_service_id']][] = $row;
        }
        return $out;
    }

    public static function calculatePackageBasePrice(array $items): float
    {
        $items = array_filter($items, fn($sessions, $serviceId) => (int) $serviceId > 0 && (int) $sessions > 0, ARRAY_FILTER_USE_BOTH);
        if (!$items) {
            return 0.0;
        }
        $serviceIds = array_map('intval', array_keys($items));
        $rows = Database::all(
            "SELECT id, price_mxn
             FROM services
             WHERE id IN (" . implode(',', array_fill(0, count($serviceIds), '?')) . ")
               AND COALESCE(item_type, 'service') = 'service'",
            $serviceIds
        );
        $prices = [];
        foreach ($rows as $row) {
            $prices[(int) $row['id']] = (float) $row['price_mxn'];
        }
        $total = 0.0;
        foreach ($items as $serviceId => $sessions) {
            $total += ($prices[(int) $serviceId] ?? 0.0) * max(1, (int) $sessions);
        }
        return round($total, 2);
    }

    public static function updatePackagePrices(int $packageServiceId, float $basePrice, float $finalPrice): void
    {
        $basePrice = round(max(0, $basePrice), 2);
        $finalPrice = round(max(0, $finalPrice), 2);
        Database::exec(
            'UPDATE services
             SET precio_base_calculado = ?, precio_final = ?, price_mxn = ?
             WHERE id = ? AND COALESCE(item_type, \'service\') = \'package\'',
            [$basePrice, $finalPrice, $finalPrice, $packageServiceId]
        );
    }

    public static function refreshPackageBasePrice(int $packageServiceId): void
    {
        $rows = Database::all(
            'SELECT included_service_id, sessions_count
             FROM service_package_items
             WHERE package_service_id = ?',
            [$packageServiceId]
        );
        if (!$rows) {
            return;
        }
        $items = [];
        foreach ($rows as $row) {
            $items[(int) $row['included_service_id']] = (int) $row['sessions_count'];
        }
        $base = self::calculatePackageBasePrice($items);
        $package = Database::one('SELECT precio_final, price_mxn FROM services WHERE id = ? LIMIT 1', [$packageServiceId]);
        if (!$package) {
            return;
        }
        $final = $package['precio_final'] !== null ? (float) $package['precio_final'] : (float) $package['price_mxn'];
        self::updatePackagePrices($packageServiceId, $base, $final);
    }

    public static function refreshPackagesContainingService(int $serviceId): void
    {
        $rows = Database::all(
            'SELECT package_service_id
             FROM service_package_items
             WHERE included_service_id = ?',
            [$serviceId]
        );
        foreach ($rows as $row) {
            self::refreshPackageBasePrice((int) $row['package_service_id']);
        }
    }

    public static function savePackageItems(int $packageServiceId, array $items): void
    {
        self::ensureSchema();
        Database::exec('DELETE FROM service_package_items WHERE package_service_id = ?', [$packageServiceId]);
        $order = 0;
        foreach ($items as $serviceId => $sessions) {
            $serviceId = (int) $serviceId;
            $sessions = max(1, (int) $sessions);
            if ($serviceId <= 0 || $serviceId === $packageServiceId) {
                continue;
            }
            Database::exec(
                'INSERT INTO service_package_items (package_service_id, included_service_id, sessions_count, display_order)
                 VALUES (?, ?, ?, ?)',
                [$packageServiceId, $serviceId, $sessions, $order++]
            );
        }
    }

    public static function normalizePackageItems(array $serviceIds, array $sessions): array
    {
        $items = [];
        foreach (array_map('intval', $serviceIds) as $serviceId) {
            if ($serviceId <= 0) {
                continue;
            }
            $items[$serviceId] = max(1, (int) ($sessions[$serviceId] ?? 1));
        }
        return $items;
    }

    public static function simpleServicesForPackageOptions(?int $excludeId = null): array
    {
        self::ensureSchema();
        $params = [];
        $excludeSql = '';
        if ($excludeId) {
            $excludeSql = ' AND id <> ?';
            $params[] = $excludeId;
        }
        return Database::all(
            "SELECT id, name, duration_min, price_mxn
             FROM services
             WHERE active = 1 AND COALESCE(item_type, 'service') = 'service' {$excludeSql}
             ORDER BY display_order, name",
            $params
        );
    }

    private static function backfillPackagePrices(): void
    {
        $packages = Database::all(
            "SELECT id, price_mxn, precio_base_calculado, precio_final
             FROM services
             WHERE COALESCE(item_type, 'service') = 'package'
               AND (precio_base_calculado IS NULL OR precio_final IS NULL)
             LIMIT 200"
        );
        if (!$packages) {
            return;
        }
        $packageIds = array_map('intval', array_column($packages, 'id'));
        $itemRows = Database::all(
            "SELECT package_service_id, included_service_id, sessions_count
             FROM service_package_items
             WHERE package_service_id IN (" . implode(',', array_fill(0, count($packageIds), '?')) . ")",
            $packageIds
        );
        $itemsByPackage = [];
        foreach ($itemRows as $itemRow) {
            $itemsByPackage[(int) $itemRow['package_service_id']][] = $itemRow;
        }
        foreach ($packages as $package) {
            $packageId = (int) $package['id'];
            $items = [];
            foreach ($itemsByPackage[$packageId] ?? [] as $item) {
                $items[(int) $item['included_service_id']] = (int) $item['sessions_count'];
            }
            $base = $items ? self::calculatePackageBasePrice($items) : (float) $package['price_mxn'];
            $final = $package['precio_final'] !== null ? (float) $package['precio_final'] : (float) $package['price_mxn'];
            if ($final < 0) {
                $final = $base;
            }
            self::updatePackagePrices($packageId, $base, $final);
        }
    }

    private static function ensureServiceColumn(string $name, string $definition): void
    {
        if (!self::columnExists('services', $name)) {
            Database::exec("ALTER TABLE services ADD COLUMN {$name} {$definition}");
        }
    }

    private static function ensurePackageItemsColumn(string $name, string $definition): void
    {
        if (!self::columnExists('service_package_items', $name)) {
            Database::exec("ALTER TABLE service_package_items ADD COLUMN {$name} {$definition}");
        }
    }

    private static function tableExists(string $table): bool
    {
        try {
            return (bool) Database::one(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                [$table]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            return (bool) Database::one(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$table, $column]
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}
