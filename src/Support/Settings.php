<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;
use App\Database\Connection;

final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (float) $v;
    }

    public static function set(string $key, string $value): void
    {
        $pdo = Connection::get();
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
        self::$cache[$key] = $value;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            $rows = Connection::get()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                self::$cache[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable) {
            // tablas aún no instaladas
        }
    }

    public static function catalogPriceFromPublic(float $public): float
    {
        $pct = self::getFloat('catalog_markup_percent', 10.0);
        return round($public * (1 + $pct / 100), 2);
    }

    public static function shippingCharge(float $enviaRate): float
    {
        $fixed = self::getFloat('shipping_fixed_price', 300.0);
        $overage = self::getFloat('shipping_overage_percent', 50.0);
        if ($enviaRate <= $fixed) {
            return round($fixed, 2);
        }

        return round($enviaRate * (1 + $overage / 100), 2);
    }

    public static function defaultStudentPassword(): string
    {
        return self::get('default_student_password', Env::get('MOODLE_DEFAULT_PASSWORD', 'Doceo*1234'))
            ?? 'Doceo*1234';
    }

    /**
     * WhatsApp de la escuela (solo dígitos, con código de país).
     * Preferencia: settings.school_whatsapp → env SCHOOL_WHATSAPP.
     */
    public static function schoolWhatsapp(): string
    {
        $raw = self::get('school_whatsapp', Env::get('SCHOOL_WHATSAPP', '')) ?? '';
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /**
     * URL wa.me para pedir código promocional a un asesor.
     * Null si no hay número configurado.
     */
    public static function schoolWhatsappPromoUrl(?string $productName = null): ?string
    {
        $phone = self::schoolWhatsapp();
        if ($phone === '') {
            return null;
        }

        $msg = 'Hola, me interesa saber si hay algún código promocional vigente';
        if ($productName !== null && trim($productName) !== '') {
            $msg .= ' para ' . trim($productName);
        }
        $msg .= '.';

        return 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
    }
}
