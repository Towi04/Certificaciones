<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Meses con tarjeta vía OpenPay.
 *
 * El alumno paga el TOTAL con tarjeta; OpenPay liquida el monto completo
 * (menos comisión) al comercio. El banco difiere el cobro mensual al tarjetahabiente.
 *
 * Preferir configurar en product_groups.config_json (heredable) o products.config_json:
 * {
 *   "card_msi": {
 *     "enabled": true,
 *     "months": [1, 3, 6, 9, 12],
 *     "min_amount": 0
 *   }
 * }
 */
final class CardMsiCalculator
{
    /** Misma oferta que ELeT para certificaciones/trámites sin override. */
    public const DEFAULT_MONTHS = [1, 3, 6, 9, 12];

    /**
     * @param array<string, mixed> $product
     * @return array{enabled:bool,months:list<int>,min_amount:float}
     */
    public static function configForProduct(array $product): array
    {
        $cfg = [];
        $decoded = CheckoutRequirements::config($product);
        if (isset($decoded['card_msi']) && is_array($decoded['card_msi'])) {
            $cfg = $decoded['card_msi'];
        } elseif (isset($decoded['deferred']) && is_array($decoded['deferred'])) {
            // Compatibilidad con config anterior
            $cfg = $decoded['deferred'];
        }

        $type = (string) ($product['type'] ?? '');
        $enabledDefault = in_array($type, ['certification', 'procedure'], true);

        $months = self::DEFAULT_MONTHS;
        if (isset($cfg['months']) && is_array($cfg['months']) && $cfg['months'] !== []) {
            $months = [];
            foreach ($cfg['months'] as $m) {
                $n = (int) $m;
                if ($n >= 1 && $n <= 24) {
                    $months[] = $n;
                }
            }
            $months = array_values(array_unique($months));
            sort($months);
            if ($months === []) {
                $months = self::DEFAULT_MONTHS;
            }
        }
        if (!in_array(1, $months, true)) {
            $months[] = 1;
            $months = array_values(array_unique($months));
            sort($months);
        }

        return [
            'enabled' => array_key_exists('enabled', $cfg) ? (bool) $cfg['enabled'] : $enabledDefault,
            'months' => $months,
            'min_amount' => round((float) ($cfg['min_amount'] ?? 0), 2),
        ];
    }

    /**
     * Opciones por meses para mostrar en checkout con tarjeta.
     * Siempre cobra el total al comercio; monthly_estimate es solo referencia al alumno.
     *
     * @param array<string, mixed> $product
     * @return list<array{months:int,total:float,monthly_estimate:float,label:string}>
     */
    public static function optionsFor(float $total, array $product): array
    {
        $total = round(max(0, $total), 2);
        $cfg = self::configForProduct($product);

        if (!$cfg['enabled'] || $total < $cfg['min_amount']) {
            return [[
                'months' => 1,
                'total' => $total,
                'monthly_estimate' => $total,
                'label' => 'Un solo pago (contado)',
            ]];
        }

        $out = [];
        foreach ($cfg['months'] as $months) {
            $months = (int) $months;
            $estimate = $months > 1 ? round($total / $months, 2) : $total;
            $out[] = [
                'months' => $months,
                'total' => $total,
                'monthly_estimate' => $estimate,
                'label' => $months === 1
                    ? 'Un solo pago (contado)'
                    : sprintf(
                        '%d meses — tú pagas %s; referencia ~%s/mes',
                        $months,
                        '$' . number_format($total, 2, '.', ','),
                        '$' . number_format($estimate, 2, '.', ',')
                    ),
            ];
        }

        return $out;
    }

    public static function isValidMonths(float $total, array $product, int $months): bool
    {
        $allowed = array_column(self::optionsFor($total, $product), 'months');

        return in_array(max(1, $months), $allowed, true);
    }
}
