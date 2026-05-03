<?php
declare(strict_types=1);

/**
 * BotProtection
 *
 * Validacion humana local, libre y sin servicios externos:
 * - reto matematico en sesion
 * - token de reto no reutilizable
 * - honeypot invisible
 * - tiempo minimo de llenado
 * - rate limit basico por sesion
 */
final class BotProtection
{
    private const SESSION_KEY = 'bot_challenges';
    private const FAIL_KEY = 'bot_failures';
    private const TTL_SECONDS = 900;
    private const MIN_SECONDS = 2;
    private const MAX_FAILURES = 8;
    private const LOCK_SECONDS = 600;

    public static function challenge(string $scope): array
    {
        self::garbageCollect();

        $stored = $_SESSION[self::SESSION_KEY][$scope] ?? null;
        if (is_array($stored) && self::isFresh($stored)) {
            return [
                'token' => $stored['token'],
                'question' => $stored['question'],
            ];
        }

        return self::issue($scope);
    }

    public static function issue(string $scope): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $answer = (string) ($a + $b);
        $token = bin2hex(random_bytes(16));
        $secret = self::secret($scope, $token);

        $_SESSION[self::SESSION_KEY][$scope] = [
            'token' => $token,
            'question' => "¿Cuánto es {$a} + {$b}?",
            'answer_hash' => hash_hmac('sha256', $answer, $secret),
            'created_at' => time(),
        ];

        return [
            'token' => $token,
            'question' => $_SESSION[self::SESSION_KEY][$scope]['question'],
        ];
    }

    public static function validate(string $scope, array $input): bool
    {
        if (self::isLocked($scope)) {
            return false;
        }

        $stored = $_SESSION[self::SESSION_KEY][$scope] ?? null;
        unset($_SESSION[self::SESSION_KEY][$scope]);

        if (!is_array($stored) || !self::isFresh($stored)) {
            self::recordFailure($scope);
            return false;
        }

        $elapsed = time() - (int) ($stored['created_at'] ?? 0);
        $token = (string) ($input['bot_token'] ?? '');
        $answer = trim((string) ($input['bot_answer'] ?? ''));
        $honeypot = trim((string) ($input['company_site'] ?? ''));

        $ok = $honeypot === ''
            && $elapsed >= self::MIN_SECONDS
            && hash_equals((string) $stored['token'], $token)
            && preg_match('/^\d{1,2}$/', $answer)
            && hash_equals(
                (string) $stored['answer_hash'],
                hash_hmac('sha256', $answer, self::secret($scope, $token))
            );

        if (!$ok) {
            self::recordFailure($scope);
            return false;
        }

        self::clearFailures($scope);
        return true;
    }

    public static function reset(string $scope): void
    {
        unset($_SESSION[self::SESSION_KEY][$scope]);
        self::clearFailures($scope);
    }

    public static function isLocked(string $scope): bool
    {
        $fail = $_SESSION[self::FAIL_KEY][$scope] ?? null;
        if (!is_array($fail)) return false;

        $lockedUntil = (int) ($fail['locked_until'] ?? 0);
        if ($lockedUntil <= 0) return false;
        if ($lockedUntil <= time()) {
            self::clearFailures($scope);
            return false;
        }
        return true;
    }

    public static function lockedMessage(string $scope): ?string
    {
        if (!self::isLocked($scope)) return null;
        return 'Por seguridad, espera unos minutos antes de intentarlo nuevamente.';
    }

    private static function recordFailure(string $scope): void
    {
        $fail = $_SESSION[self::FAIL_KEY][$scope] ?? ['count' => 0, 'first_at' => time(), 'locked_until' => 0];
        if ((int) ($fail['first_at'] ?? 0) < time() - self::LOCK_SECONDS) {
            $fail = ['count' => 0, 'first_at' => time(), 'locked_until' => 0];
        }

        $fail['count'] = (int) ($fail['count'] ?? 0) + 1;
        if ($fail['count'] >= self::MAX_FAILURES) {
            $fail['locked_until'] = time() + self::LOCK_SECONDS;
        }

        $_SESSION[self::FAIL_KEY][$scope] = $fail;
    }

    private static function clearFailures(string $scope): void
    {
        unset($_SESSION[self::FAIL_KEY][$scope]);
    }

    private static function isFresh(array $stored): bool
    {
        $created = (int) ($stored['created_at'] ?? 0);
        return $created > 0 && $created >= time() - self::TTL_SECONDS;
    }

    private static function secret(string $scope, string $token): string
    {
        return session_id() . '|' . $scope . '|' . $token;
    }

    private static function garbageCollect(): void
    {
        foreach ($_SESSION[self::SESSION_KEY] ?? [] as $scope => $stored) {
            if (!is_array($stored) || !self::isFresh($stored)) {
                unset($_SESSION[self::SESSION_KEY][$scope]);
            }
        }
    }
}
