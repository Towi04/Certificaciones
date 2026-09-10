<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CatalogFilterRepository;

final class CatalogFilterService
{
    private CatalogFilterRepository $filters;

    public function __construct()
    {
        $this->filters = new CatalogFilterRepository();
    }

    /** @return list<array<string, mixed>> */
    public function catalogFilters(string $section = 'all'): array
    {
        try {
            $this->filters->ensureDefaults();
            $this->filters->ensureCenniTypeFilters();
            $this->filters->syncCertifierFilters();

            return $this->filters->catalogVisible($section);
        } catch (\Throwable $e) {
            error_log('[Doceo] Catalog filters: ' . $e->getMessage());

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    public function adminFilters(): array
    {
        $this->filters->ensureDefaults();
        $this->filters->ensureCenniTypeFilters();
        $this->filters->syncCertifierFilters();

        return $this->filters->adminList();
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): int
    {
        $slug = $this->normalizeSlug((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            $slug = slugify((string) ($input['label'] ?? 'filtro'));
        }
        if ($this->filters->slugExists($slug)) {
            throw new \InvalidArgumentException('Ya existe un filtro con el slug ' . $slug);
        }

        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') {
            throw new \InvalidArgumentException('El nombre del filtro es obligatorio.');
        }

        return $this->filters->create([
            'slug' => $slug,
            'label' => $label,
            'filter_group' => $this->nullableGroup($input['filter_group'] ?? null),
            'sort_order' => (int) ($input['sort_order'] ?? 100),
            'is_active' => !empty($input['is_active']),
            'show_in_catalog' => !empty($input['show_in_catalog']),
        ]);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): void
    {
        if ($this->filters->find($id) === null) {
            throw new \InvalidArgumentException('Filtro no encontrado.');
        }

        $slug = $this->normalizeSlug((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            throw new \InvalidArgumentException('El slug del filtro es obligatorio.');
        }
        if ($this->filters->slugExists($slug, $id)) {
            throw new \InvalidArgumentException('Ya existe otro filtro con el slug ' . $slug);
        }

        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') {
            throw new \InvalidArgumentException('El nombre del filtro es obligatorio.');
        }

        $this->filters->update($id, [
            'slug' => $slug,
            'label' => $label,
            'filter_group' => $this->nullableGroup($input['filter_group'] ?? null),
            'sort_order' => (int) ($input['sort_order'] ?? 100),
            'is_active' => !empty($input['is_active']),
            'show_in_catalog' => !empty($input['show_in_catalog']),
        ]);
    }

    public function delete(int $id): void
    {
        if ($this->filters->find($id) === null) {
            throw new \InvalidArgumentException('Filtro no encontrado.');
        }
        $this->filters->delete($id);
    }

    /**
     * @param list<int|string> $filterIds
     * @param list<string>|null $cenniTypeKeys null = conservar CENNI actuales; lista = fijar exactamente
     */
    public function syncProductFilters(int $productId, array $filterIds, ?array $cenniTypeKeys = null): void
    {
        $this->filters->ensureCenniTypeFilters();
        $ids = array_map('intval', $filterIds);
        $certifierIds = array_fill_keys(
            $this->filters->filterIdsByGroup(CatalogFilterRepository::CERTIFIER_GROUP),
            true
        );
        $cenniIds = array_fill_keys(
            $this->filters->filterIdsByGroup(CatalogFilterRepository::CENNI_GROUP),
            true
        );
        $manual = [];
        foreach ($ids as $id) {
            if ($id < 1 || isset($certifierIds[$id]) || isset($cenniIds[$id])) {
                continue;
            }
            $manual[] = $id;
        }

        if ($cenniTypeKeys !== null) {
            foreach ($this->resolveCenniTypeFilterIds($cenniTypeKeys) as $cenniFilterId) {
                $manual[] = $cenniFilterId;
            }
        } else {
            foreach ($this->filters->filterIdsForProduct($productId) as $existingId) {
                if (isset($cenniIds[$existingId])) {
                    $manual[] = $existingId;
                }
            }
        }

        $this->filters->setProductFilters($productId, $manual);
        $this->syncProductCertifierFilter($productId);
    }

    /**
     * @param list<string|int> $keys
     * @return list<int>
     */
    public function resolveCenniTypeFilterIds(array $keys): array
    {
        $this->filters->ensureCenniTypeFilters();
        $defs = CatalogFilterRepository::cenniTypeDefinitions();
        $out = [];
        foreach ($keys as $raw) {
            $key = strtolower(trim((string) $raw));
            $key = str_replace([' ', '_'], '-', $key);
            $key = preg_replace('/^cenni-/', '', $key) ?? $key;
            if (!isset($defs[$key])) {
                continue;
            }
            $filter = $this->filters->findBySlug($defs[$key]['slug']);
            if ($filter !== null) {
                $out[] = (int) $filter['id'];
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public function cenniTypeKeysForProduct(int $productId): array
    {
        $this->filters->ensureCenniTypeFilters();
        $selected = array_fill_keys($this->filters->filterIdsForProduct($productId), true);
        $keys = [];
        foreach (CatalogFilterRepository::cenniTypeDefinitions() as $key => $def) {
            $filter = $this->filters->findBySlug($def['slug']);
            if ($filter !== null && isset($selected[(int) $filter['id']])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Reaplica el filtro de certificadora según products.certifier_id. */
    public function syncProductCertifierFilter(int $productId): void
    {
        $product = (new \App\Repositories\ProductRepository())->find($productId);
        if ($product === null) {
            return;
        }
        $certifierId = isset($product['certifier_id']) ? (int) $product['certifier_id'] : 0;
        (new ProductAdminService())->ensureCertifierCatalogFilter(
            $productId,
            $certifierId > 0 ? $certifierId : null
        );
    }

    /** @return list<int> */
    public function productFilterIds(int $productId): array
    {
        return $this->filters->filterIdsForProduct($productId);
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return str_replace('_', '-', $slug);
    }

    private function nullableGroup(mixed $group): ?string
    {
        $group = trim((string) $group);

        return $group !== '' ? $group : null;
    }
}
