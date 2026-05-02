<?php
declare(strict_types=1);

/**
 * CSRF — token único por sesión.
 * Uso en formularios:
 *   <input type="hidden" name="<?= Csrf::field() ?>" value="<?= Csrf::token() ?>">
 * Validación al recibir POST:
 *   Csrf::check($_POST[Csrf::field()] ?? '');
 */
final class Csrf
{
    public const FIELD = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return self::FIELD;
    }

    /** Devuelve <input> hidden listo */
    public static function input(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . self::token() . '">';
    }

    /** Valida; aborta con 419 si no coincide */
    public static function check(string $token): void
    {
        if (!hash_equals(self::token(), $token)) {
            http_response_code(419);
            die('Token CSRF inválido. Recarga la página y vuelve a intentar.');
        }
    }

    /** Versión booleana sin abortar */
    public static function valid(string $token): bool
    {
        return hash_equals(self::token(), $token);
    }
}
