<?php
declare(strict_types=1);

final class MailService
{
    public static function sendHtml(string $to, string $toName, string $subject, string $html): bool
    {
        $config = self::config();
        if (($config['driver'] ?? 'mail') === 'smtp') {
            return self::sendSmtp($to, $toName, $subject, $html, $config);
        }

        return self::sendMail($to, $toName, $subject, $html, $config);
    }

    private static function config(): array
    {
        global $CONFIG;

        $mail = $CONFIG['mail'] ?? [];
        $secretsPath = dirname(AGENDA_ROOT) . '/config/secrets.php';
        if (is_file($secretsPath)) {
            $secrets = require $secretsPath;
            if (is_array($secrets) && isset($secrets['mail']) && is_array($secrets['mail'])) {
                $mail = array_replace_recursive($mail, $secrets['mail']);
            }
        }

        $supportEmail = $CONFIG['app']['support_email'] ?? 'contacto@bellanickclinic.com';
        return array_replace([
            'driver' => 'mail',
            'host' => '',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => '',
            'password' => '',
            'from_email' => $supportEmail,
            'from_name' => 'BellaNick Clinic',
            'reply_to' => $supportEmail,
            'timeout' => 20,
        ], $mail);
    }

    private static function sendMail(string $to, string $toName, string $subject, string $html, array $config): bool
    {
        $fromEmail = (string) ($config['from_email'] ?? '');
        $fromName = (string) ($config['from_name'] ?? 'BellaNick Clinic');
        $replyTo = (string) ($config['reply_to'] ?? $fromEmail);
        $headers = self::headers($fromEmail, $fromName, $replyTo);
        $encodedSubject = self::encodeHeader($subject);
        $toLine = self::address($to, $toName);

        try {
            return @mail($toLine, $encodedSubject, $html, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log('[mail-send] ' . $e->getMessage());
            return false;
        }
    }

    private static function sendSmtp(string $to, string $toName, string $subject, string $html, array $config): bool
    {
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 465);
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $encryption = strtolower((string) ($config['encryption'] ?? 'ssl'));
        $timeout = max(5, (int) ($config['timeout'] ?? 20));
        $fromEmail = (string) ($config['from_email'] ?? $username);
        $fromName = (string) ($config['from_name'] ?? 'BellaNick Clinic');
        $replyTo = (string) ($config['reply_to'] ?? $fromEmail);

        if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
            error_log('[smtp-send] Configuracion SMTP incompleta.');
            return false;
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            error_log('[smtp-send] Conexion fallida: ' . $errstr . ' (' . $errno . ')');
            return false;
        }
        stream_set_timeout($socket, $timeout);

        try {
            self::smtpExpect($socket, [220]);
            self::smtpCommand($socket, 'EHLO ' . self::smtpHostName(), [250]);
            if ($encryption === 'tls') {
                self::smtpCommand($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('No fue posible iniciar TLS.');
                }
                self::smtpCommand($socket, 'EHLO ' . self::smtpHostName(), [250]);
            }
            self::smtpCommand($socket, 'AUTH LOGIN', [334]);
            self::smtpCommand($socket, base64_encode($username), [334]);
            self::smtpCommand($socket, base64_encode($password), [235]);
            self::smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::smtpCommand($socket, 'DATA', [354]);

            $headers = self::headers($fromEmail, $fromName, $replyTo);
            $headers[] = 'To: ' . self::address($to, $toName);
            $headers[] = 'Subject: ' . self::encodeHeader($subject);
            $message = implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($html) . "\r\n.";
            self::smtpCommand($socket, $message, [250]);
            self::smtpCommand($socket, 'QUIT', [221]);
            fclose($socket);
            return true;
        } catch (Throwable $e) {
            error_log('[smtp-send] ' . $e->getMessage());
            @fwrite($socket, "QUIT\r\n");
            fclose($socket);
            return false;
        }
    }

    private static function headers(string $fromEmail, string $fromName, string $replyTo): array
    {
        return [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::address($fromEmail, $fromName),
            'Reply-To: ' . $replyTo,
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
            'X-Mailer: BellaNickAgenda',
        ];
    }

    private static function address(string $email, string $name = ''): string
    {
        return $name !== '' ? self::encodeHeader($name) . ' <' . $email . '>' : $email;
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function smtpCommand($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");
        return self::smtpExpect($socket, $expectedCodes);
    }

    private static function smtpExpect($socket, array $expectedCodes): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line)) break;
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP respondio: ' . trim($response));
        }
        return $response;
    }

    private static function smtpHostName(): string
    {
        $host = parse_url(APP_BASE_URL, PHP_URL_HOST);
        return $host ?: 'localhost';
    }

    private static function dotStuff(string $html): string
    {
        return preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $html));
    }
}
