<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Env;

final class OpenPayClient
{
    public function request(string $method, string $path, ?array $body = null): array
    {
        $merchantId = Env::require('OPENPAY_MERCHANT_ID');
        $privateKey = Env::require('OPENPAY_PRIVATE_KEY');
        $base = rtrim(Env::get('OPENPAY_API_BASE', 'https://sandbox-api.openpay.mx/v1') ?? '', '/');

        $url = $base . '/' . $merchantId;
        if ($path !== '') {
            $url .= '/' . ltrim($path, '/');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('No se pudo iniciar cURL para OpenPay.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $headers[] = 'X-Forwarded-For: ' . $clientIp;

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $privateKey . ':',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL OpenPay: {$error}");
        }

        if ($status >= 400) {
            $decodedErr = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decodedErr)) {
                $description = (string) ($decodedErr['description'] ?? $decodedErr['message'] ?? 'error');
                $code = (string) ($decodedErr['error_code'] ?? $status);
                throw new \RuntimeException("OpenPay [{$code}]: {$description}");
            }
            throw new \RuntimeException("OpenPay error HTTP {$status}.");
        }

        // DELETE u otras respuestas vacías (p. ej. 204)
        if ($raw === false || $raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("OpenPay respuesta inválida (HTTP {$status}).");
        }

        return $decoded;
    }

    public function getMerchant(): array
    {
        return $this->request('GET', '');
    }

    /**
     * Cargo SPEI / transferencia bancaria → CLABE única por transacción.
     *
     * @param array{
     *   amount: float|int|string,
     *   description: string,
     *   order_id: string,
     *   customer: array{name: string, email: string, phone_number?: string},
     *   due_date?: string
     * } $data
     */
    public function createBankCharge(array $data): array
    {
        $payload = [
            'method' => 'bank_account',
            'amount' => round((float) $data['amount'], 2),
            'description' => mb_substr((string) $data['description'], 0, 250),
            'order_id' => mb_substr((string) $data['order_id'], 0, 100),
            'customer' => $data['customer'],
        ];
        if (!empty($data['due_date'])) {
            $payload['due_date'] = $data['due_date'];
        }

        return $this->request('POST', 'charges', $payload);
    }

    public function getCharge(string $chargeId): array
    {
        return $this->request('GET', 'charges/' . rawurlencode($chargeId));
    }

    public function merchantId(): string
    {
        return Env::require('OPENPAY_MERCHANT_ID');
    }

    /** Recibo PDF genérico OpenPay (sandbox o producción según API base). */
    public function speiPdfUrl(string $chargeId): string
    {
        $sandbox = str_contains(
            strtolower((string) (Env::get('OPENPAY_API_BASE', '') ?? '')),
            'sandbox'
        ) || Env::getBool('OPENPAY_SANDBOX', true);

        $host = $sandbox
            ? 'https://sandbox-dashboard.openpay.mx'
            : 'https://dashboard.openpay.mx';

        return $host . '/spei-pdf/' . $this->merchantId() . '/' . rawurlencode($chargeId);
    }

    /** @return list<string> */
    public static function defaultWebhookEventTypes(): array
    {
        return [
            'charge.succeeded',
            'charge.created',
            'charge.failed',
            'charge.cancelled',
            'spei.received',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listWebhooks(): array
    {
        $result = $this->request('GET', 'webhooks');
        if (isset($result[0]) && is_array($result[0])) {
            /** @var list<array<string, mixed>> $result */
            return $result;
        }
        if (isset($result['data']) && is_array($result['data'])) {
            $out = [];
            foreach ($result['data'] as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * @param list<string>|null $eventTypes
     * @return array<string, mixed>
     */
    public function createWebhook(
        string $url,
        ?string $user = null,
        ?string $password = null,
        ?array $eventTypes = null
    ): array {
        $payload = [
            'url' => $url,
            'event_types' => $eventTypes ?? self::defaultWebhookEventTypes(),
        ];
        if ($user !== null && $user !== '') {
            $payload['user'] = $user;
        }
        if ($password !== null && $password !== '') {
            $payload['password'] = $password;
        }

        return $this->request('POST', 'webhooks', $payload);
    }

    /** @return array<string, mixed> */
    public function getWebhook(string $webhookId): array
    {
        return $this->request('GET', 'webhooks/' . rawurlencode($webhookId));
    }

    public function deleteWebhook(string $webhookId): void
    {
        $this->request('DELETE', 'webhooks/' . rawurlencode($webhookId));
    }

    public static function publicWebhookUrl(): string
    {
        $base = rtrim((string) (Env::get('APP_URL', 'https://pdv.institutodoceo.com') ?? ''), '/');

        return $base . '/webhooks/openpay';
    }
}
