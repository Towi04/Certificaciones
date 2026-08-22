<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cálculo de pagos diferidos (MSI / calendario SPEI).
 *
 * En products.config_json:
 * {
 *   "deferred": {
 *     "enabled": true,
 *     "months": [1, 3, 6, 9, 12],
 *     "min_amount": 500,
 *     "label": "Pago diferido"
 *   }
 * }
 */
final class DeferredPaymentCalculator
{
    public const DEFAULT_MONTHS = [1, 3, 6];

    /**
     * @param array<string, mixed> $product
     * @return array{enabled:bool,months:list<int>,min_amount:float,label:string}
     */
    public static function configForProduct(array $product): array
    {
        $cfg = [];
        if (!empty($product['config_json'])) {
            $decoded = json_decode((string) $product['config_json'], true);
            if (is_array($decoded) && isset($decoded['deferred']) && is_array($decoded['deferred'])) {
                $cfg = $decoded['deferred'];
            }
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

        return [
            'enabled' => array_key_exists('enabled', $cfg) ? (bool) $cfg['enabled'] : $enabledDefault,
            'months' => $months,
            'min_amount' => round((float) ($cfg['min_amount'] ?? 500), 2),
            'label' => (string) ($cfg['label'] ?? 'Pago diferido'),
        ];
    }

    /**
     * Reparte el total en N mensualidades (centavos al último pago).
     *
     * @return array{months:int,monthly:float,first:float,last:float,total:float,schedule:list<array{seq:int,amount:float}>}
     */
    public static function split(float $total, int $months): array
    {
        if ($months < 1) {
            throw new \InvalidArgumentException('El número de meses debe ser al menos 1.');
        }
        $totalCents = (int) round(max(0, $total) * 100);
        if ($months === 1) {
            $amount = round($totalCents / 100, 2);

            return [
                'months' => 1,
                'monthly' => $amount,
                'first' => $amount,
                'last' => $amount,
                'total' => $amount,
                'schedule' => [['seq' => 1, 'amount' => $amount]],
            ];
        }

        $base = intdiv($totalCents, $months);
        $remainder = $totalCents - ($base * $months);
        $schedule = [];
        for ($i = 1; $i <= $months; $i++) {
            $cents = $base + ($i === $months ? $remainder : 0);
            $schedule[] = ['seq' => $i, 'amount' => round($cents / 100, 2)];
        }
        $first = $schedule[0]['amount'];
        $last = $schedule[$months - 1]['amount'];

        return [
            'months' => $months,
            'monthly' => $first,
            'first' => $first,
            'last' => $last,
            'total' => round($totalCents / 100, 2),
            'schedule' => $schedule,
        ];
    }

    /**
     * Planes ofrecidos para un monto y producto.
     *
     * @param array<string, mixed> $product
     * @return list<array{months:int,monthly:float,first:float,last:float,total:float,label:string}>
     */
    public static function plansFor(float $amount, array $product): array
    {
        $cfg = self::configForProduct($product);
        if (!$cfg['enabled'] || $amount < $cfg['min_amount']) {
            $one = self::split($amount, 1);

            return [[
                'months' => 1,
                'monthly' => $one['monthly'],
                'first' => $one['first'],
                'last' => $one['last'],
                'total' => $one['total'],
                'label' => 'Pago de contado',
            ]];
        }

        $out = [];
        foreach ($cfg['months'] as $m) {
            $plan = self::split($amount, $m);
            $out[] = [
                'months' => $m,
                'monthly' => $plan['monthly'],
                'first' => $plan['first'],
                'last' => $plan['last'],
                'total' => $plan['total'],
                'label' => $m === 1
                    ? 'Pago de contado'
                    : sprintf('%d pagos de $%s', $m, number_format($plan['monthly'], 2, '.', ',')),
            ];
        }

        return $out;
    }
}
