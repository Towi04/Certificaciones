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
        // Paquetes: PricingService adjunta el merge de sus productos.
        if (isset($product['_card_msi']) && is_array($product['_card_msi'])) {
            return self::normalizeConfig(
                $product['_card_msi'],
                (string) ($product['type'] ?? 'certification')
            );
        }

        $cfg = [];
        $decoded = CheckoutRequirements::config($product);
        if (isset($decoded['card_msi']) && is_array($decoded['card_msi'])) {
            $cfg = $decoded['card_msi'];
        } elseif (isset($decoded['deferred']) && is_array($decoded['deferred'])) {
            // Compatibilidad con config anterior
            $cfg = $decoded['deferred'];
        }

        return self::normalizeConfig($cfg, (string) ($product['type'] ?? ''));
    }

    /**
     * MSI de un paquete = unión de certificaciones/trámites con MSI activo
     * (intersección de meses). Los cursos u otros con MSI apagado no lo bloquean.
     *
     * @param list<array<string, mixed>> $products
     * @return array{enabled:bool,months:list<int>,min_amount:float}
     */
    public static function configFromProducts(array $products): array
    {
        $enabledCfgs = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $type = (string) ($product['type'] ?? '');
            // Solo certificaciones/trámites definen MSI del paquete.
            if (!in_array($type, ['certification', 'procedure'], true)) {
                continue;
            }
            $cfg = self::configForProduct($product);
            if (!$cfg['enabled']) {
                continue;
            }
            $enabledCfgs[] = $cfg;
        }

        if ($enabledCfgs === []) {
            return [
                'enabled' => false,
                'months' => [1],
                'min_amount' => 0.0,
            ];
        }

        $months = $enabledCfgs[0]['months'];
        $minAmount = $enabledCfgs[0]['min_amount'];
        for ($i = 1, $n = count($enabledCfgs); $i < $n; $i++) {
            $months = array_values(array_intersect($months, $enabledCfgs[$i]['months']));
            $minAmount = max($minAmount, $enabledCfgs[$i]['min_amount']);
        }
        if ($months === []) {
            $months = self::DEFAULT_MONTHS;
        }
        if (!in_array(1, $months, true)) {
            $months[] = 1;
        }
        $months = array_values(array_unique(array_map('intval', $months)));
        sort($months);

        return [
            'enabled' => true,
            'months' => $months,
            'min_amount' => round($minAmount, 2),
        ];
    }

    /**
     * Adjunta MSI resuelto al combo para quote/checkout.
     *
     * @param array<string, mixed> $combo
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>
     */
    public static function attachComboMsi(array $combo, array $products): array
    {
        $combo['_card_msi'] = self::configFromProducts($products);
        // Para defaults de enabled si alguien lee type.
        if (!isset($combo['type']) || trim((string) $combo['type']) === '') {
            $combo['type'] = 'certification';
        }

        return $combo;
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{enabled:bool,months:list<int>,min_amount:float}
     */
    public static function normalizeConfig(array $cfg, string $type = ''): array
    {
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
