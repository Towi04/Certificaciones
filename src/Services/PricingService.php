<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\Settings;
use PDO;

final class PricingService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /**
     * @param array<string, mixed> $product
     * @return array{
     *   catalog: float,
     *   charged: float,
     *   public: float,
     *   partner_id: ?int,
     *   partner_price: ?float,
     *   partner_credit: float,
     *   discount_code_id: ?int,
     *   discount_code: ?string,
     *   label: string
     * }
     */
    public function quoteProduct(array $product, ?string $codeRaw): array
    {
        $catalog = (float) ($product['catalog_price'] ?? 0);
        $public = (float) ($product['public_price'] ?? 0);
        if ($catalog <= 0 && $public > 0) {
            $catalog = Settings::catalogPriceFromPublic($public);
        }

        $out = [
            'catalog' => round($catalog, 2),
            'charged' => round($catalog, 2),
            'base' => round($catalog, 2),
            'public' => round($public, 2),
            'partner_id' => null,
            'partner_price' => null,
            'partner_credit' => 0.0,
            'discount_code_id' => null,
            'discount_code' => null,
            'label' => 'Precio de lista',
            'msi_plans' => [],
            'payment_options' => [],
        ];

        $codeRaw = strtoupper(trim((string) $codeRaw));
        if ($codeRaw === '') {
            return $this->withDeferredPlans($product, $out);
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM discount_codes WHERE code = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$codeRaw]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('El código de descuento no es válido.');
        }
        if (!empty($row['starts_at']) && strtotime((string) $row['starts_at']) > time()) {
            throw new \InvalidArgumentException('Este código aún no está vigente.');
        }
        if (!empty($row['ends_at']) && strtotime((string) $row['ends_at']) < time()) {
            throw new \InvalidArgumentException('Este código ya expiró.');
        }

        $out['discount_code_id'] = (int) $row['id'];
        $out['discount_code'] = (string) $row['code'];
        $mode = (string) $row['discount_mode'];
        $type = (string) $row['type'];

        if (in_array($type, ['partner', 'partner_seasonal'], true) || $mode === 'partner_public') {
            $partnerId = (int) ($row['partner_id'] ?? 0);
            if ($partnerId < 1) {
                throw new \InvalidArgumentException('El código de partner no está ligado a un distribuidor.');
            }
            $partner = $this->pdo->prepare('SELECT * FROM partners WHERE id = ? AND is_active = 1');
            $partner->execute([$partnerId]);
            $p = $partner->fetch();
            if (!$p) {
                throw new \InvalidArgumentException('Partner inactivo o inexistente.');
            }
            $tierPrice = $this->partnerPriceForProduct($product, (string) $p['tier']);
            $charged = $public > 0 ? $public : $catalog;
            // Código de temporada: descuento extra sobre público
            if ($type === 'partner_seasonal' && $mode === 'percent' && $row['discount_value'] !== null) {
                $charged = round($charged * (1 - ((float) $row['discount_value'] / 100)), 2);
            } elseif ($type === 'partner_seasonal' && $mode === 'fixed' && $row['discount_value'] !== null) {
                $charged = max(0, round($charged - (float) $row['discount_value'], 2));
            }

            $out['charged'] = $charged;
            $out['partner_id'] = $partnerId;
            $out['partner_price'] = $tierPrice;
            $out['partner_credit'] = max(0, round($charged - $tierPrice, 2));
            $out['label'] = 'Código partner · precio público';

            return $this->withDeferredPlans($product, $out);
        }

        // Promo DOCEO / campaña → bajar a público u otro descuento
        if ($mode === 'to_public') {
            $out['charged'] = $public > 0 ? $public : $catalog;
            $out['label'] = 'Código promocional DOCEO';
        } elseif ($mode === 'percent') {
            $pct = (float) ($row['discount_value'] ?? 0);
            $out['charged'] = round($catalog * (1 - $pct / 100), 2);
            $out['label'] = 'Descuento ' . $pct . '%';
        } elseif ($mode === 'fixed') {
            $out['charged'] = max(0, round($catalog - (float) ($row['discount_value'] ?? 0), 2));
            $out['label'] = 'Descuento fijo';
        }

        return $this->withDeferredPlans($product, $out);
    }

    /**
     * Cotiza un combo (mismas columnas de precio que productos).
     *
     * @param array<string, mixed> $combo
     * @return array<string, mixed>
     */
    public function quoteCombo(array $combo, ?string $codeRaw): array
    {
        $code = strtoupper(trim((string) $codeRaw));
        if ($code !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT applies_to_combos FROM discount_codes WHERE code = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$code]);
            $applies = $stmt->fetchColumn();
            if ($applies !== false && !(int) $applies) {
                $code = '';
            }
        }

        return $this->quoteProduct($combo, $code !== '' ? $code : null);
    }

    /** @param array<string, mixed> $product */
    public function partnerPriceForProduct(array $product, string $tier): float
    {
        $map = [
            'cncm' => 'price_cncm',
            'a' => 'price_partner_a',
            'b' => 'price_partner_b',
            'c' => 'price_partner_c',
        ];
        $col = $map[$tier] ?? 'price_partner_c';
        $val = $product[$col] ?? null;
        if ($val === null || $val === '') {
            return (float) ($product['public_price'] ?? 0);
        }

        return (float) $val;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function withDeferredPlans(array $product, array $quote): array
    {
        $base = round((float) ($quote['charged'] ?? 0), 2);
        $quote['base'] = $base;
        $quote['charged'] = $base;
        $quote['charged_base'] = $base;
        $quote['charged_fee'] = 0.0;

        $msiPlans = [];
        foreach (CardMsiCalculator::optionsFor($base, $product) as $plan) {
            $months = (int) ($plan['months'] ?? 1);
            $priced = OpenPayFeeCalculator::grossCardFromNet($base, $months);
            $gross = $priced['gross'];
            $msiPlans[] = [
                'months' => $months,
                'base' => $base,
                'fee' => $priced['fee'],
                'fee_percent' => $priced['fee_percent'],
                'fee_fixed' => $priced['fee_fixed'],
                'total' => $gross,
                'monthly_estimate' => round($gross / $months, 2),
                'label' => $months === 1 ? '1 exhibición' : $months . ' meses',
            ];
        }

        $quote['msi_plans'] = $msiPlans;
        $quote['payment_options'] = [
            'msi' => $msiPlans,
            'oxxo' => [
                'net' => $base,
                'gross' => $base,
                'fee' => 0.0,
            ],
            'transfer' => [
                'net' => $base,
                'gross' => $base,
                'fee' => 0.0,
            ],
        ];

        return $quote;
    }
}
