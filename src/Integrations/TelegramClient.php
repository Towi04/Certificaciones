<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Env;

/**
 * Cliente mínimo de Telegram Bot API (solo sendMessage saliente).
 */
final class TelegramClient
{
    public function sendMessage(string $text, ?string $chatId = null): bool
    {
        $token = trim((string) (Env::get('TELEGRAM_BOT_TOKEN', '') ?? ''));
        $chat = trim($chatId ?? (string) (Env::get('TELEGRAM_CHAT_ID', '') ?? ''));
        $text = trim($text);
        if ($token === '' || $chat === '' || $text === '') {
            return false;
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $payload = [
            'chat_id' => $chat,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            error_log('[Doceo] TelegramClient: no se pudo iniciar cURL.');

            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log('[Doceo] TelegramClient cURL: ' . $error);

            return false;
        }

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if ($status >= 400 || !is_array($decoded) || empty($decoded['ok'])) {
            $desc = is_array($decoded)
                ? (string) ($decoded['description'] ?? 'error')
                : ('HTTP ' . $status);
            error_log('[Doceo] TelegramClient sendMessage falló: ' . $desc);

            return false;
        }

        return true;
    }
}
