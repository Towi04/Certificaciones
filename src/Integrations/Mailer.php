<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Env;

/**
 * Envío de correo para Neubox/cPanel.
 *
 * Webmail (IMAP/Dovecot) puede aceptar la clave y Exim SMTP AUTH (465/587)
 * rechazarla con 535 desde PHP. En auto se usa primero mail() local
 * (sendmail/Exim sin AUTH), que es lo habitual en hosting compartido.
 */
final class Mailer
{
    /** @var array{transport: string, host?: string, port?: int, encryption?: string, auth_user?: string}|null */
    private static ?array $lastEndpoint = null;

    /** @return array{transport: string, host?: string, port?: int, encryption?: string, auth_user?: string}|null */
    public static function lastEndpoint(): ?array
    {
        return self::$lastEndpoint;
    }

    /** Huella segura de SMTP_PASS (sin revelar la clave). */
    public static function passwordFingerprint(): array
    {
        $pass = (string) Env::get('SMTP_PASS', '');
        $len = strlen($pass);
        $first = $len > 0 ? ord($pass[0]) : null;
        $last = $len > 0 ? ord($pass[$len - 1]) : null;

        return [
            'pass_len' => $len,
            'pass_first_ord' => $first,
            'pass_last_ord' => $last,
            'pass_sha1_8' => $len > 0 ? substr(sha1($pass), 0, 8) : null,
            'pass_has_space' => str_contains($pass, ' '),
            'pass_has_non_ascii' => $pass !== '' && !preg_match('/^[\x20-\x7E]+$/', $pass),
        ];
    }

    /**
     * @param array{
     *   cc?: string|null,
     *   html?: bool,
     *   body_html?: string|null,
     *   attachments?: list<array{path: string, name?: string, mime?: string}>,
     *   prefer_smtp?: bool
     * } $options
     */
    public function send(string $to, string $subject, string $bodyText, array $options = []): void
    {
        $transport = strtolower(trim(Env::get('SMTP_TRANSPORT', 'auto') ?? 'auto'));
        if (!in_array($transport, ['auto', 'smtp', 'mail', 'log'], true)) {
            $transport = 'auto';
        }

        $errors = [];
        $hasAttachments = !empty($options['attachments']) && is_array($options['attachments']);
        // Con adjuntos o prefer_smtp, SMTP suele entregar mejor; mail() a veces “acepta” y el hosting descarta.
        $preferSmtp = ($transport === 'auto' && $hasAttachments) || !empty($options['prefer_smtp']);

        if ($transport === 'log') {
            $this->sendViaLog($to, $subject, $bodyText, $options);
            self::$lastEndpoint = ['transport' => 'log'];

            return;
        }

        if (($transport === 'mail' || $transport === 'auto') && !$preferSmtp) {
            try {
                $this->sendViaPhpMail($to, $subject, $bodyText, $options);
                self::$lastEndpoint = ['transport' => 'mail'];

                return;
            } catch (\Throwable $e) {
                $errors[] = 'mail() → ' . $e->getMessage();
                if ($transport === 'mail') {
                    throw $e;
                }
            }
        }

        if ($transport === 'smtp' || $transport === 'auto') {
            try {
                $this->sendViaSmtp($to, $subject, $bodyText, $errors, $options);

                return;
            } catch (\Throwable $e) {
                $errors[] = 'smtp → ' . $e->getMessage();
                if ($transport === 'smtp') {
                    throw $this->wrapFinalError($errors);
                }
            }
        }

        if ($preferSmtp) {
            try {
                $this->sendViaPhpMail($to, $subject, $bodyText, $options);
                self::$lastEndpoint = ['transport' => 'mail'];

                return;
            } catch (\Throwable $e) {
                $errors[] = 'mail() → ' . $e->getMessage();
            }
        }

        throw $this->wrapFinalError($errors);
    }

    /** @param list<string> $errors */
    private function wrapFinalError(array $errors): \RuntimeException
    {
        $fp = self::passwordFingerprint();

        return new \RuntimeException(
            'Correo: no se pudo enviar. '
            . 'Webmail (IMAP) ≠ SMTP AUTH: clave válida en webmail no garantiza 465/587 desde PHP. '
            . 'Tras muchos 535, revisa cPanel → cPHulk (desbloquea IP/cuenta) o restablece la clave del buzón. '
            . 'Puedes forzar envío local con SMTP_TRANSPORT=mail. '
            . 'Fingerprint: len=' . $fp['pass_len']
            . ', first_ord=' . ($fp['pass_first_ord'] ?? '-')
            . ', last_ord=' . ($fp['pass_last_ord'] ?? '-')
            . ', sha1_8=' . ($fp['pass_sha1_8'] ?? '-')
            . ". Intentos:\n- " . implode("\n- ", $errors)
        );
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed> $options
     */
    private function sendViaSmtp(string $to, string $subject, string $bodyText, array &$errors, array $options = []): void
    {
        $user = trim(Env::require('SMTP_USER'));
        $pass = Env::require('SMTP_PASS');
        $from = trim(Env::get('SMTP_FROM', $user) ?? $user);
        $fromName = Env::get('SMTP_FROM_NAME', 'Instituto Doceo') ?? 'Instituto Doceo';

        $userVariants = array_values(array_unique(array_filter([
            $user,
            str_contains($user, '@') ? explode('@', $user, 2)[0] : null,
        ])));

        foreach ($this->endpoints() as $endpoint) {
            $label = $endpoint['host'] . ':' . $endpoint['port'] . '/' . $endpoint['encryption'];
            foreach ($userVariants as $authUser) {
                $authLabel = $label . ' user=' . $authUser;
                try {
                    $this->sendViaSocket(
                        $endpoint['host'],
                        $endpoint['port'],
                        $endpoint['encryption'],
                        $authUser,
                        $pass,
                        $from,
                        $fromName,
                        $to,
                        $subject,
                        $bodyText,
                        $options
                    );
                    self::$lastEndpoint = [
                        'transport' => 'smtp',
                        'host' => $endpoint['host'],
                        'port' => $endpoint['port'],
                        'encryption' => $endpoint['encryption'],
                        'auth_user' => $authUser,
                    ];

                    return;
                } catch (\Throwable $e) {
                    $errors[] = $authLabel . ' → ' . $e->getMessage();
                }
            }
        }

        throw new \RuntimeException('SMTP AUTH falló en todos los endpoints.');
    }

    /** @param array<string, mixed> $options */
    private function sendViaPhpMail(string $to, string $subject, string $bodyText, array $options = []): void
    {
        $user = trim((string) Env::get('SMTP_USER', ''));
        $from = trim((string) (Env::get('SMTP_FROM', $user !== '' ? $user : null) ?? 'certificaciones@institutodoceo.com'));
        $fromName = (string) (Env::get('SMTP_FROM_NAME', 'Instituto Doceo') ?? 'Instituto Doceo');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>';
        $payload = $this->buildMimePayload($to, $from, $fromName, $subject, $bodyText, $options);

        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $encodedFrom,
            'Reply-To: ' . $from,
            'X-Mailer: Instituto-Doceo-PDV',
        ];
        if ($payload['cc'] !== '') {
            $headers[] = 'Cc: ' . $payload['cc'];
        }
        $headers[] = $payload['content_type_header'];

        $params = '-f' . $from;
        $ok = @mail($to, $encodedSubject, $payload['body'], implode("\r\n", $headers), $params);
        if ($ok !== true) {
            throw new \RuntimeException(
                'PHP mail() devolvió false. Revisa que From (' . $from
                . ') sea un buzón del dominio en Neubox.'
            );
        }
    }

    /**
     * Sink de pruebas: escribe el correo en storage/logs/mail/ (sin SMTP).
     *
     * @param array<string, mixed> $options
     */
    private function sendViaLog(string $to, string $subject, string $bodyText, array $options = []): void
    {
        $dir = BASE_PATH . '/storage/logs/mail';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear ' . $dir);
        }
        $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $path = $dir . '/' . $stamp . '.eml.json';
        $payload = [
            'to' => $to,
            'cc' => $options['cc'] ?? null,
            'subject' => $subject,
            'body_text' => $bodyText,
            'body_html' => $options['body_html'] ?? null,
            'created_at' => date('c'),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new \RuntimeException('No se pudo escribir el log de correo en ' . $path);
        }
    }

    /**
     * Pocos endpoints (auto ya prioriza mail()). Evita timeouts en salud.
     *
     * @return list<array{host: string, port: int, encryption: string}>
     */
    private function endpoints(): array
    {
        $host = trim(Env::require('SMTP_HOST'));
        $port = (int) (Env::get('SMTP_PORT', '465') ?? '465');
        $encryption = strtolower(trim(Env::get('SMTP_ENCRYPTION', 'ssl') ?? 'ssl'));
        if ($encryption !== 'tls' && $encryption !== 'none') {
            $encryption = 'ssl';
        }

        $candidates = [
            ['host' => $host, 'port' => $port, 'encryption' => $encryption],
            ['host' => 'localhost', 'port' => 465, 'encryption' => 'ssl'],
            ['host' => $host, 'port' => 587, 'encryption' => 'tls'],
        ];

        $seen = [];
        $out = [];
        foreach ($candidates as $c) {
            $key = $c['host'] . '|' . $c['port'] . '|' . $c['encryption'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $c;
        }

        return $out;
    }

    /** @param array<string, mixed> $options */
    private function sendViaSocket(
        string $host,
        int $port,
        string $encryption,
        string $user,
        string $pass,
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $bodyText,
        array $options = []
    ): void {
        $fp = $this->connect($host, $port, $encryption);

        try {
            $fp = $this->authenticate($fp, $user, $pass, $host, $port, $encryption);
            $payload = $this->buildMimePayload($to, $from, $fromName, $subject, $bodyText, $options);

            $this->command($fp, 'MAIL FROM:<' . $from . '>', 250);
            $this->command($fp, 'RCPT TO:<' . $to . '>', 250);
            foreach ($payload['cc_list'] as $ccAddr) {
                $this->command($fp, 'RCPT TO:<' . $ccAddr . '>', 250);
            }
            $this->command($fp, 'DATA', 354);

            $headers = [
                'From: ' . $this->encodeAddress($fromName, $from),
                'To: <' . $to . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
                'Date: ' . date('r'),
                $payload['content_type_header'],
            ];
            if ($payload['cc'] !== '') {
                $headers[] = 'Cc: ' . $payload['cc'];
            }

            $data = implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($payload['body']) . "\r\n.";
            $this->command($fp, $data, 250);
            $this->command($fp, 'QUIT', 221);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{body: string, content_type_header: string, cc: string, cc_list: list<string>}
     */
    private function buildMimePayload(
        string $to,
        string $from,
        string $fromName,
        string $subject,
        string $bodyText,
        array $options
    ): array {
        unset($to, $from, $fromName, $subject);
        $ccRaw = trim((string) ($options['cc'] ?? ''));
        $ccList = [];
        if ($ccRaw !== '') {
            foreach (preg_split('/\s*,\s*/', $ccRaw) ?: [] as $addr) {
                $addr = trim($addr);
                if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $ccList[] = $addr;
                }
            }
        }
        $ccHeader = implode(', ', $ccList);

        $isHtml = !empty($options['html']) || !empty($options['body_html']);
        $html = (string) ($options['body_html'] ?? '');
        if ($isHtml && $html === '') {
            $html = nl2br(htmlspecialchars($bodyText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        $text = $bodyText !== '' ? $bodyText : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));

        /** @var list<array{path: string, name?: string, mime?: string}> $attachments */
        $attachments = $options['attachments'] ?? [];
        $validAttachments = [];
        foreach ($attachments as $att) {
            $path = (string) ($att['path'] ?? '');
            if ($path !== '' && is_file($path) && is_readable($path)) {
                $validAttachments[] = $att;
            }
        }

        if ($validAttachments === []) {
            if ($isHtml) {
                return [
                    'body' => $this->normalizeEol($html),
                    'content_type_header' => 'Content-Type: text/html; charset=UTF-8',
                    'cc' => $ccHeader,
                    'cc_list' => $ccList,
                ];
            }

            return [
                'body' => $this->normalizeEol($text),
                'content_type_header' => 'Content-Type: text/plain; charset=UTF-8',
                'cc' => $ccHeader,
                'cc_list' => $ccList,
            ];
        }

        $boundary = 'pdv_' . bin2hex(random_bytes(12));
        $parts = [];
        if ($isHtml) {
            $parts[] = '--' . $boundary
                . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                . $this->normalizeEol($html);
        } else {
            $parts[] = '--' . $boundary
                . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                . $this->normalizeEol($text);
        }

        foreach ($validAttachments as $att) {
            $path = (string) $att['path'];
            $name = (string) ($att['name'] ?? basename($path));
            $mime = (string) ($att['mime'] ?? 'application/octet-stream');
            $data = base64_encode((string) file_get_contents($path));
            $data = chunk_split($data, 76, "\r\n");
            $parts[] = '--' . $boundary
                . "\r\nContent-Type: {$mime}; name=\"{$name}\""
                . "\r\nContent-Transfer-Encoding: base64"
                . "\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                . $data;
        }
        $parts[] = '--' . $boundary . '--';

        return [
            'body' => implode("\r\n", $parts),
            'content_type_header' => 'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'cc' => $ccHeader,
            'cc_list' => $ccList,
        ];
    }

    private function normalizeEol(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        return str_replace("\n", "\r\n", $body);
    }

    /**
     * @return resource
     */
    private function connect(string $host, int $port, string $encryption)
    {
        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $remote = $scheme . $host . ':' . $port;

        $contexts = [
            stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]),
        ];

        $fp = false;
        $errno = 0;
        $errstr = '';
        foreach ($contexts as $context) {
            $fp = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                12,
                STREAM_CLIENT_CONNECT,
                $context
            );
            if ($fp !== false) {
                break;
            }
        }

        if ($fp === false) {
            throw new \RuntimeException("No se pudo conectar a {$remote}: {$errstr} ({$errno})");
        }

        stream_set_timeout($fp, 12);
        $this->expect($fp, 220);
        $this->command($fp, 'EHLO pdv.institutodoceo.com', 250);

        if ($encryption === 'tls') {
            $this->command($fp, 'STARTTLS', 220);
            $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoOk !== true) {
                throw new \RuntimeException('No se pudo negociar STARTTLS.');
            }
            $this->command($fp, 'EHLO pdv.institutodoceo.com', 250);
        }

        return $fp;
    }

    /**
     * @param resource $fp
     * @return resource
     */
    private function authenticate($fp, string $user, string $pass, string $host, int $port, string $encryption)
    {
        try {
            $plain = base64_encode("\0{$user}\0{$pass}");
            $this->command($fp, 'AUTH PLAIN ' . $plain, 235);

            return $fp;
        } catch (\RuntimeException) {
            // LOGIN
        }

        $newFp = $this->connect($host, $port, $encryption);
        fclose($fp);
        $fp = $newFp;

        $this->command($fp, 'AUTH LOGIN', 334);
        $this->command($fp, base64_encode($user), 334);
        $this->command($fp, base64_encode($pass), 235);

        return $fp;
    }

    private function encodeAddress(string $name, string $email): string
    {
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    private function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /** @param resource $fp */
    private function command($fp, string $command, int $expectCode): void
    {
        fwrite($fp, $command . "\r\n");
        $this->expect($fp, $expectCode);
    }

    /** @param resource $fp */
    private function expect($fp, int $expectCode): void
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new \RuntimeException("SMTP esperaba {$expectCode}, recibió: (sin respuesta / timeout)");
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectCode) {
            throw new \RuntimeException("SMTP esperaba {$expectCode}, recibió: " . trim($response));
        }
    }
}
