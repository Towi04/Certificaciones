<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Env;
use App\Database\Connection;
use PDOException;

final class HealthChecker
{
    /** @return list<array{name: string, ok: bool, message: string, meta?: array<string, mixed>}> */
    public function runAll(?string $smtpTestTo = null): array
    {
        return [
            $this->checkDatabase(),
            $this->checkMoodle(),
            $this->checkOpenPay(),
            $this->checkOpenPayWebhook(),
            $this->checkSmtp($smtpTestTo),
            $this->checkStorage(),
        ];
    }

    /** @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>} */
    public function checkDatabase(): array
    {
        $name = 'MariaDB';
        if (!Env::isFilled('DB_NAME') || !Env::isFilled('DB_USER')) {
            return ['name' => $name, 'ok' => false, 'message' => 'Faltan DB_NAME o DB_USER en .env'];
        }
        if (!Env::isFilled('DB_PASS')) {
            return ['name' => $name, 'ok' => false, 'message' => 'DB_PASS está vacío. Complétalo en el .env del servidor.'];
        }

        try {
            $pdo = Connection::get();
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            return [
                'name' => $name,
                'ok' => true,
                'message' => "Conectado a {$dbName}",
                'meta' => ['version' => $version],
            ];
        } catch (PDOException | \Throwable $e) {
            Connection::reset();
            return ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>} */
    public function checkMoodle(): array
    {
        $name = 'Moodle';
        if (!Env::isFilled('MOODLE_URL') || !Env::isFilled('MOODLE_TOKEN')) {
            return ['name' => $name, 'ok' => false, 'message' => 'Faltan MOODLE_URL o MOODLE_TOKEN en .env'];
        }

        try {
            $client = new MoodleClient();
            $probes = $client->probeRequiredFunctions();
            $failed = [];
            $okFns = [];
            foreach ($probes as $fn => $row) {
                if (!empty($row['ok'])) {
                    $okFns[] = $fn;
                } else {
                    $failed[] = $fn . ': ' . ($row['error'] ?? 'error');
                }
            }

            $site = $probes['core_webservice_get_site_info']['detail'] ?? null;
            $meta = [
                'functions_ok' => $okFns,
                'functions_failed' => array_keys(array_filter($probes, static fn ($r) => empty($r['ok']))),
                'site' => $site,
            ];

            if ($failed !== []) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'message' => 'Token conecta, pero faltan permisos/funciones para alta de alumnos. '
                        . implode(' | ', $failed),
                    'meta' => $meta,
                ];
            }

            $courses = $client->getCourses();
            $count = count($courses);
            $sample = [];
            foreach (array_slice($courses, 0, 5) as $course) {
                if (!is_array($course)) {
                    continue;
                }
                $sample[] = [
                    'id' => $course['id'] ?? null,
                    'shortname' => isset($course['shortname']) ? (string) $course['shortname'] : null,
                    'fullname' => isset($course['fullname']) ? mb_substr((string) $course['fullname'], 0, 120) : null,
                ];
            }
            unset($courses);
            $meta['sample'] = $sample;
            $who = is_array($site) ? ((string) ($site['username'] ?? '') . ' @ ' . (string) ($site['sitename'] ?? '')) : '';

            return [
                'name' => $name,
                'ok' => true,
                'message' => "OK — {$count} curso(s); funciones PDV listas"
                    . ($who !== '' ? " ({$who})" : ''),
                'meta' => $meta,
            ];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>} */
    public function checkOpenPay(): array
    {
        $name = 'OpenPay';
        if (!Env::isFilled('OPENPAY_MERCHANT_ID') || !Env::isFilled('OPENPAY_PRIVATE_KEY')) {
            return [
                'name' => $name,
                'ok' => false,
                'message' => 'Faltan OPENPAY_MERCHANT_ID o OPENPAY_PRIVATE_KEY (usa la sk_ real de sandbox, no el placeholder).',
            ];
        }

        if (str_contains((string) Env::get('OPENPAY_PRIVATE_KEY'), 'MI_LLAVE')) {
            return [
                'name' => $name,
                'ok' => false,
                'message' => 'OPENPAY_PRIVATE_KEY todavía es un placeholder. Pega la llave secreta real del dashboard sandbox.',
            ];
        }

        try {
            $client = new OpenPayClient();
            $merchant = $client->getMerchant();
            $sandbox = Env::getBool('OPENPAY_SANDBOX', true);
            $mode = $sandbox ? 'sandbox (CLABEs de prueba; no cobran dinero real)' : 'producción';
            return [
                'name' => $name,
                'ok' => true,
                'message' => 'Autenticado correctamente · modo ' . $mode,
                'meta' => [
                    'id' => $merchant['id'] ?? Env::get('OPENPAY_MERCHANT_ID'),
                    'name' => $merchant['name'] ?? null,
                    'sandbox' => $sandbox,
                    'webhook_url' => OpenPayClient::publicWebhookUrl(),
                    'webhook_admin' => '/admin/openpay',
                ],
            ];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{name: string, ok: bool|null, message: string, meta?: array<string, mixed>} */
    public function checkOpenPayWebhook(): array
    {
        $name = 'OpenPay webhook';
        if (!Env::isFilled('OPENPAY_MERCHANT_ID') || !Env::isFilled('OPENPAY_PRIVATE_KEY')) {
            return [
                'name' => $name,
                'ok' => false,
                'message' => 'Configura OpenPay antes de registrar el webhook.',
            ];
        }

        $target = rtrim(OpenPayClient::publicWebhookUrl(), '/');
        try {
            $client = new OpenPayClient();
            $webhooks = $client->listWebhooks();
            $match = null;
            foreach ($webhooks as $wh) {
                if (rtrim((string) ($wh['url'] ?? ''), '/') === $target) {
                    $match = $wh;
                    break;
                }
            }
            if ($match === null) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'message' => 'Aún no hay webhook apuntando a ' . $target . '. Regístralo en Admin → OpenPay.',
                    'meta' => ['url' => $target, 'count' => count($webhooks), 'admin' => '/admin/openpay'],
                ];
            }
            $status = (string) ($match['status'] ?? '');
            $verified = $status === 'verified';

            return [
                'name' => $name,
                'ok' => $verified ? true : null,
                'message' => $verified
                    ? 'Webhook verificado y activo.'
                    : 'Webhook registrado pero no verificado (estado: ' . ($status !== '' ? $status : 'desconocido') . ').',
                'meta' => [
                    'id' => $match['id'] ?? null,
                    'status' => $status,
                    'url' => $match['url'] ?? $target,
                    'admin' => '/admin/openpay',
                ],
            ];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>} */
    public function checkSmtp(?string $testTo = null): array
    {
        $transport = strtolower(trim(Env::get('SMTP_TRANSPORT', 'auto') ?? 'auto'));
        $name = 'Correo';

        if ($transport !== 'mail') {
            foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'] as $key) {
                if (!Env::isFilled($key)) {
                    return ['name' => $name, 'ok' => false, 'message' => "Falta {$key} en .env"];
                }
            }
        } elseif (!Env::isFilled('SMTP_FROM') && !Env::isFilled('SMTP_USER')) {
            return ['name' => $name, 'ok' => false, 'message' => 'Falta SMTP_FROM o SMTP_USER en .env para mail()'];
        }

        $to = $testTo ?: (Env::get('SMTP_FROM') ?: Env::get('SMTP_USER'));
        if ($to === null || $to === '') {
            return ['name' => $name, 'ok' => false, 'message' => 'No hay destinatario de prueba de correo.'];
        }

        $meta = array_merge([
            'transport' => $transport,
            'host' => Env::get('SMTP_HOST'),
            'port' => Env::get('SMTP_PORT'),
            'encryption' => Env::get('SMTP_ENCRYPTION', 'ssl'),
            'user' => Env::get('SMTP_USER'),
            'from' => Env::get('SMTP_FROM'),
            'to' => $to,
            'note' => 'Webmail (IMAP) ≠ SMTP AUTH. En Neubox auto usa mail() local primero.',
        ], Mailer::passwordFingerprint());

        try {
            $mailer = new Mailer();
            $plain = "Este es un correo de prueba del panel de salud del PDV.\n\nFecha: " . date('c') . "\n";
            $html = \App\Mail\MailBranding::wrap(
                '<h1 style="color:#315285;font-size:28px;margin:0 0 20px 0;font-family:Arial,sans-serif;">Prueba PDV</h1>'
                . '<p>Este es un correo de prueba del panel de salud del PDV.</p>'
                . '<p><strong>Fecha:</strong> ' . htmlspecialchars(date('c'), ENT_QUOTES, 'UTF-8') . '</p>'
            );
            $mailer->send(
                $to,
                'Prueba PDV Instituto Doceo',
                $plain,
                ['html' => true, 'body_html' => $html]
            );

            $used = Mailer::lastEndpoint();
            if ($used !== null) {
                $meta['used_endpoint'] = $used;
            }

            $via = 'mail()';
            if ($used !== null) {
                if (($used['transport'] ?? '') === 'mail') {
                    $via = 'PHP mail()';
                } elseif (!empty($used['host'])) {
                    $via = $used['host'] . ':' . ($used['port'] ?? '') . '/' . ($used['encryption'] ?? '');
                }
            }

            return [
                'name' => $name,
                'ok' => true,
                'message' => "Correo de prueba enviado a {$to} vía {$via}",
                'meta' => $meta,
            ];
        } catch (\Throwable $e) {
            return [
                'name' => $name,
                'ok' => false,
                'message' => $e->getMessage(),
                'meta' => $meta,
            ];
        }
    }

    /** @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>} */
    public function checkStorage(): array
    {
        $name = 'Storage';
        $base = dirname(__DIR__, 2) . '/storage';
        $dirs = [$base, $base . '/uploads', $base . '/logs'];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ['name' => $name, 'ok' => false, 'message' => "No se pudo crear {$dir}"];
            }
            if (!is_writable($dir)) {
                return ['name' => $name, 'ok' => false, 'message' => "Sin permiso de escritura en {$dir}"];
            }
        }

        $probe = $base . '/logs/health-probe.txt';
        if (file_put_contents($probe, date('c')) === false) {
            return ['name' => $name, 'ok' => false, 'message' => 'No se pudo escribir archivo de prueba.'];
        }

        return [
            'name' => $name,
            'ok' => true,
            'message' => 'Lectura/escritura OK en storage/',
        ];
    }
}
