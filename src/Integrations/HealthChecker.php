<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Env;
use App\Database\Connection;
use App\Mail\MailBranding;
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
            return ['name' => $name, 'ok' => false, 'message' => 'DB_PASS está vacío en .env'];
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
            foreach ($probes as $fn => $row) {
                if (empty($row['ok'])) {
                    $failed[] = $fn . ': ' . ($row['error'] ?? 'error');
                }
            }

            if ($failed !== []) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'message' => 'Token conecta, pero faltan permisos: ' . implode(' | ', $failed),
                ];
            }

            $courses = $client->getCourses();

            return [
                'name' => $name,
                'ok' => true,
                'message' => 'OK — ' . count($courses) . ' curso(s) visibles',
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
                'message' => 'Faltan OPENPAY_MERCHANT_ID o OPENPAY_PRIVATE_KEY en .env',
            ];
        }

        try {
            $client = new OpenPayClient();
            $merchant = $client->getMerchant();
            $sandbox = Env::getBool('OPENPAY_SANDBOX', true);
            $mode = $sandbox ? 'sandbox' : 'producción';

            return [
                'name' => $name,
                'ok' => true,
                'message' => 'Autenticado · modo ' . $mode,
                'meta' => [
                    'id' => $merchant['id'] ?? Env::get('OPENPAY_MERCHANT_ID'),
                    'name' => $merchant['name'] ?? null,
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
        }

        $to = $testTo ?: (Env::get('SMTP_FROM') ?: Env::get('SMTP_USER'));
        if ($to === null || $to === '') {
            return ['name' => $name, 'ok' => false, 'message' => 'No hay destinatario de prueba de correo.'];
        }

        try {
            $mailer = new Mailer();
            $html = MailBranding::wrap(
                '<h1 style="color:#315285;margin:0 0 16px;">Prueba de correo</h1>'
                . '<p>Sistema Instituto DOCEO — verificación de integración.</p>'
                . '<p><strong>Fecha:</strong> ' . htmlspecialchars(date('c'), ENT_QUOTES, 'UTF-8') . '</p>'
            );
            $mailer->send(
                $to,
                'Prueba — ' . app_name(),
                'Prueba de correo del sistema Instituto DOCEO. Fecha: ' . date('c'),
                ['html' => true, 'body_html' => $html]
            );

            return [
                'name' => $name,
                'ok' => true,
                'message' => "Correo de prueba enviado a {$to}",
            ];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{name: string, ok: bool, message: string} */
    public function checkStorage(): array
    {
        $name = 'Storage';
        $base = BASE_PATH . '/storage';
        $dirs = [$base, $base . '/uploads', $base . '/logs', $base . '/sessions'];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ['name' => $name, 'ok' => false, 'message' => "No se pudo crear {$dir}"];
            }
            if (!is_writable($dir)) {
                return ['name' => $name, 'ok' => false, 'message' => "Sin permiso de escritura en {$dir}"];
            }
        }

        return ['name' => $name, 'ok' => true, 'message' => 'Lectura/escritura OK en storage/'];
    }
}
