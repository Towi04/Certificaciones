<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ComboRepository;

/**
 * Presentación de combos en catálogo: overrides propios o fallback de certificaciones.
 */
final class ComboCatalogPresenter
{
    private ComboRepository $combos;

    public function __construct(?ComboRepository $combos = null)
    {
        $this->combos = $combos ?? new ComboRepository();
    }

    /**
     * @param array<string, mixed> $combo
     * @param list<array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    public function presentCard(array $combo, ?array $items = null): array
    {
        $items ??= $this->combos->items((int) ($combo['id'] ?? 0));
        $certs = $this->certificationItems($items);
        $fallbackPool = $certs !== [] ? $certs : $items;

        $logo = trim((string) ($combo['logo_path'] ?? ''));
        if ($logo === '') {
            $logo = $this->firstLogo($fallbackPool);
        }

        $short = trim((string) ($combo['short_description'] ?? ''));
        if ($short === '') {
            $short = $this->defaultShortHtml($certs !== [] ? $certs : $fallbackPool);
        }

        $body = trim((string) ($combo['description'] ?? ''));
        if ($body === '') {
            $body = $this->defaultBodyHtml($certs !== [] ? $certs : $fallbackPool);
        }

        $anchor = $this->anchorProduct($certs, $items);

        $card = [
            'id' => (int) ($combo['id'] ?? 0),
            'code' => (string) ($combo['code'] ?? ''),
            'name' => (string) ($combo['name'] ?? ''),
            'slug' => (string) ($combo['slug'] ?? ''),
            'catalog_kind' => 'combo',
            'is_star' => !empty($combo['is_star']) ? 1 : 0,
            'is_active' => !empty($combo['is_active']) ? 1 : 0,
            'is_public' => !array_key_exists('is_public', $combo) || !empty($combo['is_public']) ? 1 : 0,
            'public_price' => $combo['public_price'] ?? 0,
            'catalog_price' => $combo['catalog_price'] ?? 0,
            'price_partner_a' => $combo['price_partner_a'] ?? null,
            'price_partner_b' => $combo['price_partner_b'] ?? null,
            'price_partner_c' => $combo['price_partner_c'] ?? null,
            'price_cncm' => $combo['price_cncm'] ?? null,
            'logo_path' => $logo !== '' ? $logo : null,
            'short_description' => $short !== '' ? $short : null,
            'description' => $body !== '' ? $body : null,
            'certifier_name' => 'Combo',
            'category' => 'other',
            'supplier_name' => '',
            'acquire_slug' => $anchor !== null ? (string) ($anchor['slug'] ?? '') : '',
            'anchor_product_id' => $anchor !== null ? (int) ($anchor['id'] ?? 0) : 0,
            'items' => $items,
            'certification_items' => $certs,
        ];
        foreach (array_keys(PartnerAdminService::specialPriceFieldLabels()) as $specialCol) {
            $card[$specialCol] = $combo[$specialCol] ?? null;
        }

        return $card;
    }

    /**
     * @param list<array<string, mixed>> $combos
     * @return list<array<string, mixed>>
     */
    public function presentCards(array $combos): array
    {
        if ($combos === []) {
            return [];
        }
        $ids = array_map(static fn (array $c): int => (int) ($c['id'] ?? 0), $combos);
        $byCombo = $this->combos->itemsByComboIds($ids);
        $out = [];
        foreach ($combos as $combo) {
            $id = (int) ($combo['id'] ?? 0);
            $out[] = $this->presentCard($combo, $byCombo[$id] ?? []);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function certificationItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ((string) ($item['type'] ?? '') === 'certification') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $certs
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function anchorProduct(array $certs, array $items): ?array
    {
        if ($certs !== []) {
            return $certs[0];
        }
        foreach ($items as $item) {
            if (!empty($item['slug'])) {
                return $item;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $items */
    private function firstLogo(array $items): string
    {
        foreach ($items as $item) {
            $logo = trim((string) ($item['logo_path'] ?? ''));
            if ($logo !== '') {
                return $logo;
            }
        }

        return '';
    }

    /** @param list<array<string, mixed>> $items */
    private function defaultShortHtml(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $parts = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $short = trim(strip_tags((string) ($item['short_description'] ?? '')));
            $parts[] = $short !== ''
                ? '<strong>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>: '
                    . htmlspecialchars($short, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : '<strong>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
        }

        return $parts === [] ? '' : '<p>' . implode(' · ', $parts) . '</p>';
    }

    /** @param list<array<string, mixed>> $items */
    private function defaultBodyHtml(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $blocks = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $html = trim((string) ($item['description'] ?? ''));
            if ($html === '') {
                $html = trim((string) ($item['short_description'] ?? ''));
            }
            $block = '<h3>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
            if ($html !== '') {
                // description/short del producto ya es HTML de admin; se muestra vía rich_text en vista.
                $block .= $html;
            }
            $blocks[] = $block;
        }

        return implode("\n", $blocks);
    }
}
