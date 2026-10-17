<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Integrations\TelegramClient;
use App\Repositories\PurchaseRepository;

/**
 * Avisos operativos al celular vía Telegram (compra nueva / pago por confirmar).
 */
final class TelegramNotifyService
{
    private TelegramClient $client;
    private PurchaseRepository $purchases;

    public function __construct(?TelegramClient $client = null, ?PurchaseRepository $purchases = null)
    {
        $this->client = $client ?? new TelegramClient();
        $this->purchases = $purchases ?? new PurchaseRepository();
    }

    public function isEnabled(): bool
    {
        if (!Env::getBool('TELEGRAM_ENABLED', true)) {
            return false;
        }
        $token = trim((string) (Env::get('TELEGRAM_BOT_TOKEN', '') ?? ''));
        $chat = trim((string) (Env::get('TELEGRAM_CHAT_ID', '') ?? ''));

        return $token !== '' && $chat !== '';
    }

    public function notifyPurchaseCreated(int $purchaseId): void
    {
        if ($purchaseId < 1 || !$this->isEnabled()) {
            return;
        }

        $detail = $this->purchases->detail($purchaseId);
        if ($detail === null) {
            error_log('[Doceo] TelegramNotify: compra no encontrada #' . $purchaseId);

            return;
        }

        $items = $this->purchases->items($purchaseId);
        $productNames = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['product_name'] ?? ''));
            if ($name !== '' && !in_array($name, $productNames, true)) {
                $productNames[] = $name;
            }
        }
        $productsLabel = $productNames !== [] ? implode(', ', $productNames) : '—';

        $student = trim(implode(' ', array_filter([
            (string) ($detail['first_name'] ?? ''),
            (string) ($detail['last_name_p'] ?? ''),
            (string) ($detail['last_name_m'] ?? ''),
        ], static fn (string $p): bool => $p !== '')));
        if ($student === '') {
            $student = (string) ($detail['student_email'] ?? '—');
        }

        $status = (string) ($detail['status'] ?? '');
        $method = (string) ($detail['payment_method'] ?? '');
        $amount = money($detail['charged_amount'] ?? 0);
        $matricula = (string) ($detail['matricula'] ?? '');
        $email = trim((string) ($detail['student_email'] ?? ''));
        $partner = trim((string) ($detail['partner_name'] ?? ''));

        $base = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
        $adminUrl = $base !== ''
            ? $base . '/admin/compras/' . $purchaseId
            : '/admin/compras/' . $purchaseId;

        $lines = [
            '<b>Nueva compra / pago por confirmar</b>',
            '',
            '<b>Matrícula:</b> ' . self::esc($matricula !== '' ? $matricula : (string) $purchaseId),
            '<b>Alumno:</b> ' . self::esc($student),
        ];
        if ($email !== '') {
            $lines[] = '<b>Correo:</b> ' . self::esc($email);
        }
        $lines[] = '<b>Producto(s):</b> ' . self::esc($productsLabel);
        $lines[] = '<b>Monto:</b> ' . self::esc($amount);
        $lines[] = '<b>Método:</b> ' . self::esc(self::paymentMethodLabel($method));
        $lines[] = '<b>Estatus:</b> ' . self::esc(self::statusLabel($status));
        if ($partner !== '') {
            $lines[] = '<b>Partner:</b> ' . self::esc($partner);
        }
        $lines[] = '';
        $lines[] = '<a href="' . self::escAttr($adminUrl) . '">Abrir compra en admin</a>';

        $ok = $this->client->sendMessage(implode("\n", $lines));
        if (!$ok) {
            error_log('[Doceo] TelegramNotify: no se pudo enviar aviso compra #' . $purchaseId);
        }
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'transfer_proof' => 'Transferencia (comprobante)',
            'openpay_card' => 'Tarjeta (OpenPay)',
            'openpay_spei' => 'SPEI (OpenPay)',
            'openpay_store' => 'OXXO / tienda',
            'credit' => 'Crédito partner',
            default => $method !== '' ? $method : '—',
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'payment_review' => 'En revisión (payment_review)',
            'awaiting_payment' => 'Por pagar (awaiting_payment)',
            'paid' => 'Pagada',
            default => $status !== '' ? $status : '—',
        };
    }
}
