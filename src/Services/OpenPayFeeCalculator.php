<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Support\Settings;

/**
 * Calcula el monto a cobrar al cliente cuando la comisión OpenPay se traslada al alumno.
 *
 * gross = (net + fixed) / (1 - percent/100)
 */
final class OpenPayFeeCalculator
{
    public const METHOD_CARD = 'card';
    public const METHOD_SPEI = 'spei';
    public const METHOD_STORE = 'store';

    /**
     * @return array{
     *   net: float,
     *   fee: float,
     *   gross: float,
     *   fee_percent: float,
     *   fee_fixed: float,
     *   pass_to_customer: bool
     * }
     */
    public static function grossFromNet(float $net, string $method): array
    {
        $net = round(max(0, $net), 2);
        $pass = self::passToCustomer();
        [$percent, $fixed] = self::ratesFor($method);

        if (!$pass || ($percent <= 0 && $fixed <= 0)) {
            return [
                'net' => $net,
                'fee' => 0.0,
                'gross' => $net,
                'fee_percent' => $percent,
                'fee_fixed' => $fixed,
                'pass_to_customer' => false,
            ];
        }

        $divisor = 1 - ($percent / 100);
        $gross = $divisor > 0 ? ($net + $fixed) / $divisor : $net + $fixed;
        $gross = round($gross, 2);
        $fee = round(max(0, $gross - $net), 2);

        return [
            'net' => $net,
            'fee' => $fee,
            'gross' => $gross,
            'fee_percent' => $percent,
            'fee_fixed' => $fixed,
            'pass_to_customer' => true,
        ];
    }

    /** @return array{0: float, 1: float} percent, fixed MXN */
    private static function ratesFor(string $method): array
    {
        return match ($method) {
            self::METHOD_SPEI => [
                self::floatSetting('openpay_fee_spei_percent', 'OPENPAY_FEE_SPEI_PERCENT', 2.9),
                self::floatSetting('openpay_fee_spei_fixed', 'OPENPAY_FEE_SPEI_FIXED', 0.0),
            ],
            self::METHOD_STORE => [
                self::floatSetting('openpay_fee_store_percent', 'OPENPAY_FEE_STORE_PERCENT', 3.5),
                self::floatSetting('openpay_fee_store_fixed', 'OPENPAY_FEE_STORE_FIXED', 10.0),
            ],
            default => [
                self::floatSetting('openpay_fee_card_percent', 'OPENPAY_FEE_CARD_PERCENT', 3.5),
                self::floatSetting('openpay_fee_card_fixed', 'OPENPAY_FEE_CARD_FIXED', 0.0),
            ],
        };
    }

    private static function passToCustomer(): bool
    {
        $fromSettings = Settings::get('openpay_pass_fee_to_customer');
        if ($fromSettings !== null && $fromSettings !== '') {
            return !in_array(strtolower($fromSettings), ['0', 'false', 'no'], true);
        }

        return Env::getBool('OPENPAY_PASS_FEE_TO_CUSTOMER', true);
    }

    private static function floatSetting(string $settingsKey, string $envKey, float $default): float
    {
        $v = Settings::get($settingsKey);
        if ($v !== null && $v !== '') {
            return (float) $v;
        }
        $env = Env::get($envKey);
        if ($env !== null && $env !== '') {
            return (float) $env;
        }

        return $default;
    }
}
