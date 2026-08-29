<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CertifierRepository;
use App\Repositories\ProductGroupRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;
use App\Support\Settings;

/** Alta/edición de productos y grupos desde el panel admin. */
final class ProductAdminService
{
    private ProductRepository $products;
    private ProductGroupRepository $groups;
    private SupplierRepository $suppliers;
    private CertifierRepository $certifiers;

    public function __construct()
    {
        $this->products = new ProductRepository();
        $this->groups = new ProductGroupRepository();
        $this->suppliers = new SupplierRepository();
        $this->certifiers = new CertifierRepository();
    }

    /** @return list<string> */
    public static function typeOptions(): array
    {
        return ['certification', 'course', 'procedure', 'shipping', 'extension', 'other'];
    }

    /** @return list<string> */
    public static function categoryOptions(): array
    {
        return ['it', 'english_adult', 'english_kids', 'teaching', 'other'];
    }

    /** @return list<string> */
    public static function audienceOptions(): array
    {
        return ['adult', 'kids', 'any'];
    }

    /** @return list<string> */
    public static function platformOptions(): array
    {
        return ['none', 'moodle', 'provider'];
    }

    public static function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'producto';
    }

    public static function normalizeProductCode(string $code): string
    {
        $code = strtoupper(trim($code));

        return preg_replace('/[^A-Z0-9_-]/', '', $code) ?? '';
    }

    public static function normalizeGroupCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = str_replace('_', '-', $code);

        return preg_replace('/[^a-z0-9-]/', '', $code) ?? '';
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createProduct(array $input): int
    {
        $data = $this->buildProductPayload($input);
        if ($this->products->findByCode($data['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe un producto con el código ' . $data['code']);
        }
        if ($this->products->findBySlugExact($data['slug']) !== null) {
            throw new \InvalidArgumentException('Ya existe un producto con el slug ' . $data['slug']);
        }
        $data['config_json'] = json_encode(new \stdClass(), JSON_UNESCAPED_UNICODE);

        return $this->products->create($data);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateProduct(int $id, array $input): void
    {
        $existing = $this->products->find($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }
        $data = $this->buildProductPayload($input, $existing);
        $byCode = $this->products->findByCode($data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            throw new \InvalidArgumentException('Ya existe otro producto con el código ' . $data['code']);
        }
        if ($this->products->findBySlugExact($data['slug'], $id) !== null) {
            throw new \InvalidArgumentException('Ya existe otro producto con el slug ' . $data['slug']);
        }
        $this->products->update($id, $data);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createGroup(array $input): int
    {
        $parsed = $this->buildGroupPayload($input, true);
        if ($this->groups->findByCode($parsed['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe un grupo con el código ' . $parsed['code']);
        }

        return $this->groups->create($parsed);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateGroup(int $id, array $input): void
    {
        if ($this->groups->find($id) === null) {
            throw new \InvalidArgumentException('Grupo no encontrado.');
        }
        $parsed = $this->buildGroupPayload($input, false);
        unset($parsed['code']);
        $this->groups->update($id, $parsed);
    }

    /**
     * Crea/actualiza grupos sugeridos (si no existen) con pagos tipo ELeT.
     *
     * @return list<string>
     */
    public function ensureSuggestedGroups(): array
    {
        $suppliers = [];
        foreach ($this->suppliers->all() as $s) {
            $suppliers[(string) $s['code']] = (int) $s['id'];
        }

        // Códigos alineados con CatalogSeeder.
        $defs = [
            'uks-elet' => ['name' => 'UKS · ELeT (examen)', 'supplier' => 'uks', 'msi' => true],
            'uks-elet-cenni' => ['name' => 'UKS · Trámite CENNI ELeT', 'supplier' => 'uks', 'msi' => false],
            'itep-exams' => ['name' => 'iTEP / Oxford · Exámenes', 'supplier' => 'itep', 'msi' => true],
            'linguafranca-exams' => ['name' => 'Lingua Franca · TOEFL', 'supplier' => 'linguafranca', 'msi' => true],
            'etc-certs' => ['name' => 'ETC · Certificaciones IT', 'supplier' => 'etc', 'msi' => true],
            'doceo-procedures' => ['name' => 'DOCEO · Trámites', 'supplier' => 'doceo', 'msi' => true],
            'doceo-courses' => ['name' => 'DOCEO · Cursos Moodle', 'supplier' => 'doceo', 'msi' => false],
        ];

        $log = [];
        foreach ($defs as $code => $def) {
            $existing = $this->groups->findByCode($code);
            $configJson = json_encode(
                ProductGroupRepository::defaultCheckoutConfig((bool) $def['msi']),
                JSON_UNESCAPED_UNICODE
            );
            $supplierId = $suppliers[$def['supplier']] ?? null;
            if ($existing) {
                $update = [
                    'name' => $def['name'],
                    'supplier_id' => $supplierId,
                ];
                $current = trim((string) ($existing['config_json'] ?? ''));
                if ($current === '' || $current === '{}' || $current === 'null') {
                    $update['config_json'] = $configJson;
                }
                $this->groups->update((int) $existing['id'], $update);
                $log[] = 'Actualizado: ' . $code;
            } else {
                $this->groups->create([
                    'code' => $code,
                    'name' => $def['name'],
                    'supplier_id' => $supplierId,
                    'config_json' => $configJson,
                ]);
                $log[] = 'Creado: ' . $code;
            }
        }

        return $log;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function buildProductPayload(array $input, ?array $existing = null): array
    {
        $code = self::normalizeProductCode((string) ($input['code'] ?? ($existing['code'] ?? '')));
        if (strlen($code) < 2) {
            throw new \InvalidArgumentException('El código del producto es obligatorio (mín. 2 caracteres).');
        }

        $name = trim((string) ($input['name'] ?? ($existing['name'] ?? '')));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del producto es obligatorio.');
        }

        $slugRaw = trim((string) ($input['slug'] ?? ''));
        $slug = self::slugify($slugRaw !== '' ? $slugRaw : $name);

        $type = (string) ($input['type'] ?? ($existing['type'] ?? 'certification'));
        if (!in_array($type, self::typeOptions(), true)) {
            $type = 'certification';
        }
        $category = (string) ($input['category'] ?? ($existing['category'] ?? 'other'));
        if (!in_array($category, self::categoryOptions(), true)) {
            $category = 'other';
        }
        $audience = (string) ($input['audience'] ?? ($existing['audience'] ?? 'any'));
        if (!in_array($audience, self::audienceOptions(), true)) {
            $audience = 'any';
        }
        $platform = (string) ($input['platform_type'] ?? ($existing['platform_type'] ?? 'none'));
        if (!in_array($platform, self::platformOptions(), true)) {
            $platform = 'none';
        }

        $groupId = $this->nullableInt($input['product_group_id'] ?? null);
        if ($groupId !== null && $this->groups->find($groupId) === null) {
            throw new \InvalidArgumentException('El grupo de proceso seleccionado no existe.');
        }
        $supplierId = $this->nullableInt($input['supplier_id'] ?? null);
        if ($supplierId !== null && $this->suppliers->find($supplierId) === null) {
            throw new \InvalidArgumentException('El proveedor seleccionado no existe.');
        }
        $certifierId = $this->nullableInt($input['certifier_id'] ?? null);
        if ($certifierId !== null && $this->certifiers->find($certifierId) === null) {
            throw new \InvalidArgumentException('El certificador seleccionado no existe.');
        }

        $publicPrice = round(max(0, (float) ($input['public_price'] ?? ($existing['public_price'] ?? 0))), 2);
        $catalogRaw = trim((string) ($input['catalog_price'] ?? ''));
        $catalogPrice = $catalogRaw === ''
            ? Settings::catalogPriceFromPublic($publicPrice)
            : round(max(0, (float) $catalogRaw), 2);

        $months = (int) ($input['access_months'] ?? ($existing['access_months'] ?? 6));
        if ($months < 1) {
            $months = 6;
        }
        if ($months > 60) {
            $months = 60;
        }

        return [
            'code' => $code,
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'category' => $category,
            'audience' => $audience,
            'platform_type' => $platform,
            'product_group_id' => $groupId,
            'supplier_id' => $supplierId,
            'certifier_id' => $certifierId,
            'short_description' => $this->nullableString($input['short_description'] ?? null),
            'description' => $this->nullableString($input['description'] ?? null),
            'benefits_html' => $this->nullableString($input['benefits_html'] ?? null),
            'level_label' => $this->nullableString($input['level_label'] ?? null),
            'public_price' => $publicPrice,
            'catalog_price' => $catalogPrice,
            'cost_price' => round(max(0, (float) ($input['cost_price'] ?? ($existing['cost_price'] ?? 0))), 2),
            'price_cncm' => $this->nullableMoney($input['price_cncm'] ?? null),
            'price_partner_a' => $this->nullableMoney($input['price_partner_a'] ?? null),
            'price_partner_b' => $this->nullableMoney($input['price_partner_b'] ?? null),
            'price_partner_c' => $this->nullableMoney($input['price_partner_c'] ?? null),
            'moodle_course_id' => $this->nullableInt($input['moodle_course_id'] ?? null),
            'access_months' => $months,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'is_public' => !empty($input['is_public']) ? 1 : 0,
            'is_star' => !empty($input['is_star']) ? 1 : 0,
            'sort_order' => (int) ($input['sort_order'] ?? ($existing['sort_order'] ?? 100)),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name:string,code:string,supplier_id:?int,config_json:string}
     */
    private function buildGroupPayload(array $input, bool $requireCode): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del grupo es obligatorio.');
        }

        $code = self::normalizeGroupCode((string) ($input['code'] ?? ''));
        if ($requireCode && strlen($code) < 2) {
            throw new \InvalidArgumentException('El código del grupo es obligatorio (ej. itep-exams).');
        }

        $supplierId = $this->nullableInt($input['supplier_id'] ?? null);
        if ($supplierId !== null && $this->suppliers->find($supplierId) === null) {
            throw new \InvalidArgumentException('El proveedor seleccionado no existe.');
        }

        $configRaw = trim((string) ($input['config_json'] ?? ''));
        if ($configRaw === '') {
            $enableMsi = ($input['template'] ?? 'cert') !== 'course';
            $configRaw = (string) json_encode(
                ProductGroupRepository::defaultCheckoutConfig($enableMsi),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );
        } else {
            $decoded = json_decode($configRaw, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('El JSON de configuración del grupo no es válido.');
            }
            $configRaw = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        return [
            'name' => $name,
            'code' => $code,
            'supplier_id' => $supplierId,
            'config_json' => $configRaw,
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }

    private function nullableMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round(max(0, (float) $value), 2);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
