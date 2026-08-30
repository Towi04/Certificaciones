<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ComboRepository;
use App\Repositories\ProductRepository;
use App\Support\Settings;

/** CRUD de combos (certificación + curso + trámite) con precios por nivel. */
final class ComboAdminService
{
    private ComboRepository $combos;
    private ProductRepository $products;

    public function __construct()
    {
        $this->combos = new ComboRepository();
        $this->products = new ProductRepository();
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = str_replace('_', '-', $code);

        return preg_replace('/[^a-z0-9-]/', '', $code) ?? '';
    }

    public static function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

        return trim($s, '-') ?: 'combo';
    }

    /**
     * @param array<string, mixed> $input
     * @param list<int|string>|null $productIds
     */
    public function create(array $input, ?array $productIds = null): int
    {
        $payload = $this->buildPayload($input, true);
        if ($this->combos->findByCode($payload['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe un combo con el código ' . $payload['code']);
        }
        $ids = $this->normalizeProductIds($productIds ?? ($input['product_ids'] ?? []));
        $this->assertProducts($ids);

        $id = $this->combos->create($payload);
        $this->combos->syncItems($id, $ids);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<int|string>|null $productIds
     */
    public function update(int $id, array $input, ?array $productIds = null): void
    {
        if ($this->combos->find($id) === null) {
            throw new \InvalidArgumentException('Combo no encontrado.');
        }
        $payload = $this->buildPayload($input, false);
        unset($payload['code']);
        $ids = $this->normalizeProductIds($productIds ?? ($input['product_ids'] ?? []));
        $this->assertProducts($ids);
        $this->combos->update($id, $payload);
        $this->combos->syncItems($id, $ids);
    }

    public function delete(int $id): void
    {
        if ($this->combos->find($id) === null) {
            throw new \InvalidArgumentException('Combo no encontrado.');
        }
        if ($this->combos->countPurchases($id) > 0) {
            throw new \InvalidArgumentException(
                'No se puede eliminar: ya hay compras con este combo. Márcalo inactivo.'
            );
        }
        $this->combos->delete($id);
    }

    /**
     * Opciones de “convertir en combo” para un producto ancla.
     *
     * @return array{
     *   combos: list<array<string,mixed>>,
     *   addons: list<array<string,mixed>>
     * }
     */
    public function offersForProduct(int $productId): array
    {
        $combos = $this->combos->activeContainingProduct($productId);
        $addonMap = [];
        $enriched = [];
        foreach ($combos as $combo) {
            $cid = (int) $combo['id'];
            $items = $this->combos->items($cid);
            $itemIds = [];
            $addonIds = [];
            foreach ($items as $item) {
                $pid = (int) $item['id'];
                $itemIds[] = $pid;
                if ($pid !== $productId) {
                    $addonIds[] = $pid;
                    $addonMap[$pid] = [
                        'id' => $pid,
                        'code' => (string) $item['code'],
                        'name' => (string) $item['name'],
                        'type' => (string) $item['type'],
                        'public_price' => (float) $item['public_price'],
                        'catalog_price' => (float) $item['catalog_price'],
                        'slug' => (string) $item['slug'],
                    ];
                }
            }
            $combo['items'] = $items;
            $combo['item_ids'] = $itemIds;
            $combo['addon_ids'] = $addonIds;
            $combo['solo_sum'] = array_sum(array_map(
                static fn (array $i): float => (float) ($i['public_price'] ?? 0) > 0
                    ? (float) $i['public_price']
                    : (float) ($i['catalog_price'] ?? 0),
                $items
            ));
            $enriched[] = $combo;
        }

        $addons = array_values($addonMap);
        usort($addons, static function (array $a, array $b): int {
            $order = ['course' => 1, 'certification' => 2, 'procedure' => 3];
            $oa = $order[$a['type']] ?? 9;
            $ob = $order[$b['type']] ?? 9;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcmp($a['name'], $b['name']);
        });

        return ['combos' => $enriched, 'addons' => $addons];
    }

    /**
     * Desglose de precios sueltos vs tarifa del combo (para admin/checkout).
     *
     * @param list<array<string, mixed>> $items
     * @return array{
     *   solo_sum: float,
     *   combo_price: float,
     *   savings: float,
     *   savings_percent: float,
     *   items: list<array{id:int,name:string,type:string,code:string,solo_price:float,combo_share:float,discount:float}>
     * }
     */
    public static function priceBreakdown(array $items, float $comboCharged): array
    {
        $rows = [];
        $soloSum = 0.0;
        foreach ($items as $item) {
            $solo = (float) ($item['public_price'] ?? 0) > 0
                ? (float) $item['public_price']
                : (float) ($item['catalog_price'] ?? 0);
            $solo = round(max(0, $solo), 2);
            $soloSum += $solo;
            $rows[] = [
                'id' => (int) ($item['id'] ?? 0),
                'name' => (string) ($item['name'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
                'code' => (string) ($item['code'] ?? ''),
                'solo_price' => $solo,
                'combo_share' => 0.0,
                'discount' => 0.0,
            ];
        }
        $soloSum = round($soloSum, 2);
        $comboCharged = round(max(0, $comboCharged), 2);
        $n = count($rows);
        $allocated = 0.0;
        foreach ($rows as $i => &$row) {
            if ($n === 0) {
                break;
            }
            if ($i === $n - 1) {
                $share = round($comboCharged - $allocated, 2);
            } elseif ($soloSum > 0) {
                $share = round($comboCharged * ($row['solo_price'] / $soloSum), 2);
                $allocated += $share;
            } else {
                $share = round($comboCharged / $n, 2);
                $allocated += $share;
            }
            $row['combo_share'] = $share;
            $row['discount'] = round($row['solo_price'] - $share, 2);
        }
        unset($row);

        $savings = round($soloSum - $comboCharged, 2);

        return [
            'solo_sum' => $soloSum,
            'combo_price' => $comboCharged,
            'savings' => $savings,
            'savings_percent' => $soloSum > 0 ? round(($savings / $soloSum) * 100, 1) : 0.0,
            'items' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildPayload(array $input, bool $requireCode): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del combo es obligatorio.');
        }
        $code = self::normalizeCode((string) ($input['code'] ?? ''));
        if ($requireCode && strlen($code) < 2) {
            throw new \InvalidArgumentException('El código del combo es obligatorio (ej. toefl-prep-cenni).');
        }
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::slugify($name);
        } else {
            $slug = self::slugify($slug);
        }
        $description = trim((string) ($input['description'] ?? ''));
        $public = round(max(0, (float) ($input['public_price'] ?? 0)), 2);
        $catalogRaw = $input['catalog_price'] ?? '';
        if ($catalogRaw === '' || $catalogRaw === null) {
            $catalog = Settings::catalogPriceFromPublic($public);
        } else {
            $catalog = round(max(0, (float) $catalogRaw), 2);
        }

        return [
            'name' => $name,
            'code' => $code,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'is_star' => !empty($input['is_star']) ? 1 : 0,
            'public_price' => $public,
            'catalog_price' => $catalog,
            'price_cncm' => $this->nullableMoney($input['price_cncm'] ?? null),
            'price_partner_a' => $this->nullableMoney($input['price_partner_a'] ?? null),
            'price_partner_b' => $this->nullableMoney($input['price_partner_b'] ?? null),
            'price_partner_c' => $this->nullableMoney($input['price_partner_c'] ?? null),
        ];
    }

    private function nullableMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round(max(0, (float) $value), 2);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function normalizeProductIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (count($ids) < 2) {
            throw new \InvalidArgumentException(
                'Un combo debe incluir al menos 2 productos (ej. certificación + curso, o certificación + trámite).'
            );
        }

        return $ids;
    }

    /** @param list<int> $ids */
    private function assertProducts(array $ids): void
    {
        foreach ($ids as $id) {
            $p = $this->products->find($id);
            if ($p === null) {
                throw new \InvalidArgumentException('Producto #' . $id . ' no existe.');
            }
            if (!(int) ($p['is_active'] ?? 0)) {
                throw new \InvalidArgumentException('El producto ' . $p['code'] . ' está inactivo.');
            }
        }
    }
}
