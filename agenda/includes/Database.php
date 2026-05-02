<?php
declare(strict_types=1);

/**
 * Database — wrapper PDO singleton para /agenda/.
 *
 * Lee credenciales desde el MISMO secrets.php que usa el resto del sitio
 * (mismo patrón que /config/db.php). No duplica credenciales.
 *
 * Claves esperadas en secrets.php (return array):
 *   db_host, db_name, db_user, db_pass, db_charset (opcional), db_port (opcional), db_debug (opcional)
 *
 * Uso:
 *   $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id=?');
 *   $stmt->execute([$id]);
 *   $user = $stmt->fetch();
 */
final class Database
{
    private static ?PDO $pdo = null;

    /**
     * Inicializa la conexión.
     * - Si recibe $cfg con claves db_host/db_name/etc, las usa directamente.
     * - Si no, busca secrets.php en varias rutas (mismo orden que /config/db.php).
     * - El parámetro es opcional para mantener compat con bootstrap.php.
     */
    public static function init(?array $cfg = null): void
    {
        if (self::$pdo !== null) return;

        // 1) Si no hay config explícita, cargar secrets.php
        if ($cfg === null) {
            $cfg = self::loadSecrets();
        }

        // 2) Validar credenciales mínimas
        $required = ['db_host', 'db_name', 'db_user', 'db_pass'];
        foreach ($required as $k) {
            if (!isset($cfg[$k]) || $cfg[$k] === '' ||
                (is_string($cfg[$k]) && strpos($cfg[$k], 'REEMPLAZAR_') === 0)) {
                self::abort("Credencial faltante o placeholder: $k", null, $cfg);
            }
        }

        // 3) Construir DSN (idéntico a /config/db.php)
        $host    = $cfg['db_host'];
        $dbname  = $cfg['db_name'];
        $charset = $cfg['db_charset'] ?? 'utf8mb4';
        $port    = (int) ($cfg['db_port'] ?? 3306);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host, $port, $dbname, $charset
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], $options);
            // Forzar zona UTC en MySQL para guardar timestamps consistentes
            try { self::$pdo->exec("SET time_zone = '+00:00'"); } catch (Throwable $e) { /* ignore */ }
        } catch (PDOException $e) {
            self::abort('Fallo conexión MySQL', $e, $cfg);
        }
    }

    /**
     * Busca secrets.php en varias rutas (orden de seguridad).
     * Usa el MISMO orden que /config/db.php para que credenciales vivan en un solo lugar.
     */
    private static function loadSecrets(): array
    {
        $secretsPaths = [
            // Fuera de public_html (más seguro)
            __DIR__ . '/../../../../secrets.php',  // /home/USER/secrets.php
            __DIR__ . '/../../../secrets.php',     // /home/USER/domains/secrets.php
            __DIR__ . '/../../secrets.php',        // /public_html/secrets.php
            // Dentro del sitio (la ruta que YA usas)
            __DIR__ . '/../../config/secrets.php', // /public_html/config/secrets.php  ← TU ARCHIVO ACTUAL
            __DIR__ . '/../config/secrets.php',    // /public_html/agenda/config/secrets.php (fallback local)
        ];

        foreach ($secretsPaths as $p) {
            if (is_file($p)) {
                $cfg = require $p;
                if (is_array($cfg)) return $cfg;
            }
        }

        self::abort('secrets.php no encontrado en ninguna ruta esperada', null, null);
    }

    /**
     * Aborta con mensaje legible. En modo debug expone detalles, en producción no.
     */
    private static function abort(string $msg, ?Throwable $e = null, ?array $cfg = null): void
    {
        $raw = $e ? $e->getMessage() : '';
        error_log('[agenda/Database] ' . $msg . ($raw ? ' — ' . $raw : ''));

        // Diagnóstico legible
        $hint = null;
        if ($raw) {
            if (stripos($raw, 'Access denied') !== false) {
                $hint = 'Credenciales incorrectas: revisa db_user y db_pass en secrets.php. En hPanel > Bases de datos MySQL confirma que ese usuario tiene permisos sobre esa base.';
            } elseif (stripos($raw, 'Unknown database') !== false) {
                $hint = 'La base de datos no existe: revisa db_name en secrets.php (Hostinger usa prefijo tipo u1234567_nombre).';
            } elseif (stripos($raw, 'Unknown MySQL server') !== false || stripos($raw, "can't connect") !== false || stripos($raw, 'getaddrinfo') !== false) {
                $hint = 'Host MySQL inaccesible: revisa db_host. En Hostinger suele ser "localhost".';
            } elseif (stripos($raw, 'SSL') !== false) {
                $hint = 'Problema de SSL en la conexión MySQL.';
            } elseif (stripos($raw, "doesn't exist") !== false || stripos($raw, "Base table") !== false) {
                $hint = 'Falta importar las tablas: ejecuta /agenda/sql/schema.sql y /agenda/sql/seed.sql en phpMyAdmin.';
            }
        }

        $debug = is_array($cfg) && !empty($cfg['db_debug']);

        // Si la petición es JSON / AJAX → respuesta JSON
        $wantsJson = (
            (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'json') !== false) ||
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['REQUEST_URI']) && (stripos($_SERVER['REQUEST_URI'], '/api/') !== false || stripos($_SERVER['REQUEST_URI'], '.json') !== false))
        );

        if ($wantsJson) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            $resp = ['ok' => false, 'status' => 'error', 'message' => 'Servicio no disponible temporalmente'];
            if ($hint) $resp['hint'] = $hint;
            if ($debug) {
                $resp['debug'] = $msg . ($raw ? ' — ' . $raw : '');
                if (is_array($cfg)) {
                    $resp['dsn_preview'] = sprintf(
                        'mysql:host=%s;port=%d;dbname=%s',
                        $cfg['db_host'] ?? '?',
                        (int) ($cfg['db_port'] ?? 3306),
                        $cfg['db_name'] ?? '?'
                    );
                    $resp['user_preview'] = isset($cfg['db_user']) ? substr((string) $cfg['db_user'], 0, 3) . '***' : '?';
                }
            }
            echo json_encode($resp);
            exit;
        }

        // Respuesta HTML
        http_response_code(503);
        $safeMsg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        $safeHint = $hint ? htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') : '';
        $safeDebug = ($debug && $raw) ? htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') : '';
        $debugBlock = '';
        if ($debug) {
            $userPreview = (is_array($cfg) && isset($cfg['db_user'])) ? substr((string) $cfg['db_user'], 0, 3) . '***' : '?';
            $dsnPreview = is_array($cfg) ? sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $cfg['db_host'] ?? '?',
                (int) ($cfg['db_port'] ?? 3306),
                $cfg['db_name'] ?? '?'
            ) : '';
            $debugBlock = "<hr><p style='font-family:monospace;font-size:13px;color:#444'>"
                . "<strong>Debug:</strong> {$safeDebug}<br>"
                . "<strong>DSN:</strong> " . htmlspecialchars($dsnPreview, ENT_QUOTES, 'UTF-8') . "<br>"
                . "<strong>User:</strong> " . htmlspecialchars($userPreview, ENT_QUOTES, 'UTF-8') . "</p>"
                . "<p style='font-size:12px;color:#888'>Modo debug está ON (db_debug => true en secrets.php). Ponlo en false en producción.</p>";
        }
        echo "<!doctype html><html lang='es'><meta charset='utf-8'><title>Servicio no disponible</title>"
            . "<body style='font-family:system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#222'>"
            . "<h1 style='color:#c63d8a'>Servicio no disponible temporalmente</h1>"
            . "<p>Estamos teniendo problemas para conectar con la base de datos. Intenta de nuevo en unos minutos.</p>"
            . ($safeHint ? "<p style='background:#fff5f9;border-left:4px solid #c63d8a;padding:12px 16px;border-radius:6px'><strong>Posible causa:</strong> {$safeHint}</p>" : "")
            . $debugBlock
            . "</body></html>";
        exit;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            // Auto-init lazy si alguien pide pdo() sin haber llamado init()
            self::init();
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
