<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Services\CheckoutService;

final class WebhookController
{
    public function openPay(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!$this->authorizeBasic()) {
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="OpenPay Webhook"');
            echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false) {
            $raw = '';
        }

        try {
            $result = (new CheckoutService())->handleOpenPayWebhook($raw);
            http_response_code(200);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('OpenPay webhook: ' . $e->getMessage());
            // 200 para no saturar reintentos OpenPay en fallos de negocio/parseo puntuales.
            http_response_code(200);
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    private function authorizeBasic(): bool
    {
        $expectedUser = trim((string) (Env::get('OPENPAY_WEBHOOK_USER', '') ?? ''));
        $expectedPass = (string) (Env::get('OPENPAY_WEBHOOK_PASSWORD', '') ?? '');

        // Sin credenciales en .env: endpoint abierto (útil en sandbox). En producción configurar ambas.
        if ($expectedUser === '' && $expectedPass === '') {
            return true;
        }

        $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

        if ($user === '' && $pass === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/^Basic\s+(.+)$/i', $header, $m)) {
                $decoded = base64_decode($m[1], true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [$user, $pass] = explode(':', $decoded, 2);
                }
            }
        }

        return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
    }
}
