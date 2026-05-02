<?php
declare(strict_types=1);

/**
 * Database — wrapper PDO singleton.
 * Uso:
 *   $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id=?');
 *   $stmt->execute([$id]);
 *   $user = $stmt->fetch();
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function init(array $cfg): void
    {
        if (self::$pdo !== null) return;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'] ?? 3306,
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . ($cfg['charset'] ?? 'utf8mb4') . ", time_zone='+00:00'",
        ];

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        } catch (PDOException $e) {
            // No exponer credenciales — log + mensaje genérico
            error_log('[Database] ' . $e->getMessage());
            http_response_code(503);
            die('Servicio no disponible temporalmente. Intenta de nuevo en unos minutos.');
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database no inicializada. Llama Database::init($cfg) primero.');
        }
        return self::$pdo;
    }

    /** Atajo para SELECT que devuelve 1 fila o null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Atajo para SELECT que devuelve todas las filas */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Atajo para INSERT/UPDATE/DELETE — devuelve filas afectadas */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
