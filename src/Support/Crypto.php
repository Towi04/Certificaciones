<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;

/** Cifrado simple para secretos de proveedores (APP_KEY en .env). */
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar.');
        }

        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            throw new \RuntimeException('Payload cifrado inválido.');
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('No se pudo descifrar.');
        }

        return $plain;
    }

    private static function key(): string
    {
        $appKey = (string) (Env::get('APP_KEY', '') ?? '');
        if ($appKey === '') {
            throw new \RuntimeException('Define APP_KEY en .env para cifrar secretos.');
        }

        return hash('sha256', $appKey, true);
    }
}
