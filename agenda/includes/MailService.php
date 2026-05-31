<?php
declare(strict_types=1);

final class MailService
{
    private static ?string $lastError = null;

    public static function sendHtml(string $to, string $toName, string $subject, string $html): bool
    {
        self::$lastError = null;
        $config = self::config();
        if (($config['driver'] ?? 'mail') === 'smtp') {
            return self::sendSmtp($to, $toName, $subject, $html, $config);
        }

        return self::sendMail($to, $toName, $subject, $html, $config);
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function sendPlain(string $to, string $toName, string $subject, string $body): bool
    {
        self::$lastError = null;
        $config = self::config();
        if (($config['driver'] ?? 'mail') === 'smtp') {
            return self::sendSmtp($to, $toName, $subject, nl2br(e($body)), array_replace($config, ['format' => 'plain']));
        }

        return self::sendPlainMail($to, $toName, $subject, $body, $config);
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
            'format' => 'html',
        ], $mail);
    }

    private static function sendMail(string $to, string $toName, string $subject, string $html, array $config): bool
    {
        $fromEmail = (string) ($config['from_email'] ?? '');
        $fromName = (string) ($config['from_name'] ?? 'BellaNick Clinic');
        $replyTo = (string) ($config['reply_to'] ?? $fromEmail);
        $plain = self::wantsPlainText($config);
        if ($plain) {
            return self::sendPlainMail($to, $toName, $subject, self::htmlToText($html), $config);
        }
        $boundary = $plain ? '' : self::boundary();
        $headers = self::headers($fromEmail, $fromName, $replyTo, $boundary, $plain);
        $encodedSubject = self::encodeHeader($subject);
        $toLine = self::address($to, $toName);
        $body = $plain ? self::htmlToText($html) : self::multipartBody($html, $boundary);

        try {
            $params = self::mailParams($fromEmail);
            return $params !== ''
                ? @mail($toLine, $encodedSubject, $body, implode("\r\n", $headers), $params)
                : @mail($toLine, $encodedSubject, $body, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log('[mail-send] ' . $e->getMessage());
            return false;
        }
    }

    private static function sendPlainMail(string $to, string $toName, string $subject, string $body, array $config): bool
    {
        $fromEmail = (string) ($config['from_email'] ?? '');
        $fromName = (string) ($config['from_name'] ?? 'BellaNick Clinic');
        $replyTo = (string) ($config['reply_to'] ?? $fromEmail);
        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $replyTo,
            'Return-Path: ' . $fromEmail,
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        try {
            $params = self::mailParams($fromEmail);
            return $params !== ''
                ? @mail($to, $subject, $body, implode("\r\n", $headers), $params)
                : @mail($to, $subject, $body, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log('[plain-mail-send] ' . $e->getMessage());
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
            self::fail('Configuracion SMTP incompleta.');
            return false;
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            self::fail('Conexion SMTP fallida: ' . $errstr . ' (' . $errno . ')');
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

            $plain = self::wantsPlainText($config);
            $boundary = $plain ? '' : self::boundary();
            $headers = self::headers($fromEmail, $fromName, $replyTo, $boundary, $plain);
            $headers[] = 'To: ' . self::address($to, $toName);
            $headers[] = 'Subject: ' . self::encodeHeader($subject);
            $body = $plain ? self::htmlToText($html) : self::multipartBody($html, $boundary);
            $message = implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($body) . "\r\n.";
            self::smtpCommand($socket, $message, [250]);
            self::smtpCommand($socket, 'QUIT', [221]);
            fclose($socket);
            return true;
        } catch (Throwable $e) {
            self::fail($e->getMessage());
            @fwrite($socket, "QUIT\r\n");
            fclose($socket);
            return false;
        }
    }

    private static function headers(string $fromEmail, string $fromName, string $replyTo, string $boundary, bool $plain = false): array
    {
        $domain = parse_url(APP_BASE_URL, PHP_URL_HOST) ?: 'depilasermexico.com';
        return [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . '>',
            'MIME-Version: 1.0',
            $plain ? 'Content-Type: text/plain; charset=UTF-8' : 'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'From: ' . self::address($fromEmail, $fromName),
            'Reply-To: ' . $replyTo,
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
            'X-Mailer: BellaNickAgenda',
        ];
    }

    private static function multipartBody(string $html, string $boundary): string
    {
        $text = self::htmlToText($html);
        return "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n\r\n"
            . "--{$boundary}--";
    }

    private static function htmlToText(string $html): string
    {
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text ?? '');
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text ?? '');
        $text = preg_replace('/<\/p\s*>/i', "\n\n", $text ?? '');
        $text = strip_tags($text ?? '');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text ?? '');
        return trim($text ?? '') ?: 'BellaNick Clinic';
    }

    private static function boundary(): string
    {
        return 'bnc_' . bin2hex(random_bytes(16));
    }

    private static function wantsPlainText(array $config): bool
    {
        return strtolower((string) ($config['format'] ?? 'html')) === 'plain';
    }

    private static function mailParams(string $fromEmail): string
    {
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return '-f' . $fromEmail;
    }

    private static function fail(string $message): void
    {
        self::$lastError = $message;
        error_log('[mail-service] ' . $message);
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
        fwrite($socket, self::smtpLines($command) . "\r\n");
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
        return preg_replace('/^\./m', '..', self::smtpLines($html));
    }

    private static function smtpLines(string $value): string
    {
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $value));
    }
}
