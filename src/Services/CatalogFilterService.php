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
    public function catalogFilters(): array
    {
        try {
            $this->filters->ensureDefaults();

            return $this->filters->catalogVisible();
        } catch (\Throwable $e) {
            error_log('[Doceo] Catalog filters: ' . $e->getMessage());

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    public function adminFilters(): array
    {
        $this->filters->ensureDefaults();

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

    /** @param list<int|string> $filterIds */
    public function syncProductFilters(int $productId, array $filterIds): void
    {
        $ids = array_map('intval', $filterIds);
        $this->filters->setProductFilters($productId, $ids);
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
