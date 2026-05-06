<?php
declare(strict_types=1);

/**
 * Validator — validaciones de formularios.
 * Cada método retorna ['campo' => 'mensaje de error', ...] o []
 */
final class Validator
{
    public static function registration(array $d): array
    {
        $e = [];

        $identity = class_exists('ClientProfile')
            ? ClientProfile::normalizeName($d)
            : ['first_name' => trim((string) ($d['first_name'] ?? '')), 'last_name' => trim((string) ($d['last_name'] ?? ''))];

        if (mb_strlen($identity['first_name']) < 2) {
            $e['first_name'] = 'Tu nombre debe tener al menos 2 caracteres.';
        }
        if (mb_strlen($identity['last_name']) < 2) {
            $e['last_name'] = 'Ingresa tus apellidos.';
        }
        if (empty($d['email']) || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'Correo electrónico inválido.';
        }
        $phone = preg_replace('/\D+/', '', $d['phone'] ?? '');
        if (strlen($phone) !== 10) {
            $e['phone'] = 'Teléfono debe tener exactamente 10 dígitos.';
        }
        if (empty($d['password']) || strlen($d['password']) < 8) {
            $e['password'] = 'La contraseña debe tener mínimo 8 caracteres.';
        } elseif (!preg_match('/[A-Z]/', $d['password']) || !preg_match('/\d/', $d['password'])) {
            $e['password'] = 'La contraseña debe incluir al menos 1 mayúscula y 1 número.';
        }
        if (($d['password'] ?? '') !== ($d['password_confirm'] ?? '')) {
            $e['password_confirm'] = 'Las contraseñas no coinciden.';
        }

        return $e;
    }

    public static function login(array $d): array
    {
        $e = [];
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if ($email === '') {
            $e['email'] = 'Ingresa tu correo.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'Ingresa un correo valido.';
        }
        if (empty($d['password'])) $e['password'] = 'Ingresa tu contraseña.';
        return $e;
    }

    public static function appointmentCreate(array $d): array
    {
        $e = [];
        if (empty($d['branch_id']))  $e['branch_id']  = 'Selecciona una sucursal.';
        if (empty($d['service_id'])) $e['service_id'] = 'Selecciona un servicio.';
        if (empty($d['start_at']))   $e['start_at']   = 'Selecciona fecha y hora.';
        return $e;
    }
}
