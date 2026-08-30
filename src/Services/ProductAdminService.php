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
        $input['_group_id'] = $id;
        if ($this->groups->find($id) === null) {
            throw new \InvalidArgumentException('Grupo no encontrado.');
        }
        $parsed = $this->buildGroupPayload($input, false);
        unset($parsed['code']);
        $this->groups->update($id, $parsed);
    }

    /**
     * Extrae campos de UI a partir del config_json del grupo.
     *
     * @return array<string, mixed>
     */
    public static function groupFormExtrasFromConfig(?string $configJson): array
    {
        $cfg = [];
        if ($configJson !== null && trim($configJson) !== '') {
            $decoded = json_decode($configJson, true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }
        $exam = is_array($cfg['exam'] ?? null) ? $cfg['exam'] : [];
        $schedule = is_array($cfg['schedule'] ?? null) ? $cfg['schedule'] : [];
        $weekdays = is_array($schedule['weekdays'] ?? null) ? $schedule['weekdays'] : [];
        $saturday = is_array($schedule['saturday'] ?? null) ? $schedule['saturday'] : [];
        $reg = is_array($cfg['reglamento'] ?? null) ? $cfg['reglamento'] : [];
        $payments = is_array($cfg['payments'] ?? null) ? $cfg['payments'] : [];
        $msi = is_array($cfg['card_msi'] ?? null) ? $cfg['card_msi'] : [];

        $daysCfg = is_array($schedule['days'] ?? null) ? $schedule['days'] : null;
        if ($daysCfg === null) {
            $days = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 0 => false];
        } else {
            $days = [];
            foreach ([0, 1, 2, 3, 4, 5, 6] as $d) {
                $days[$d] = !empty($daysCfg[(string) $d]) || !empty($daysCfg[$d]);
            }
        }

        $order = is_array($payments['order'] ?? null)
            ? $payments['order']
            : ['transfer_proof', 'openpay_store', 'openpay_card'];
        $msiMonths = is_array($msi['months'] ?? null)
            ? array_map('intval', $msi['months'])
            : [1, 3, 6, 9, 12];

        return [
            'exam_choose_at_checkout' => (bool) ($exam['choose_at_checkout'] ?? true),
            'exam_slot_minutes' => max(15, (int) ($exam['slot_minutes'] ?? 30)),
            'exam_validity_months' => max(1, (int) ($exam['validity_months'] ?? 6)),
            'schedule_min_advance_days' => max(0, (int) ($schedule['min_advance_days'] ?? 2)),
            'schedule_available_365' => (bool) ($schedule['available_365'] ?? false),
            'schedule_days' => $days,
            'schedule_weekdays_start' => (string) ($weekdays['start'] ?? '10:00'),
            'schedule_weekdays_end' => (string) ($weekdays['end'] ?? '17:30'),
            'schedule_saturday_start' => (string) ($saturday['start'] ?? '08:00'),
            'schedule_saturday_end' => (string) ($saturday['end'] ?? '12:00'),
            'reglamento_enabled' => $reg !== [] && (
                trim((string) ($reg['template_path'] ?? '')) !== ''
                || trim((string) ($reg['source_url'] ?? '')) !== ''
            ),
            'reglamento_template_path' => (string) ($reg['template_path'] ?? ''),
            'reglamento_source_url' => (string) ($reg['source_url'] ?? ''),
            'reglamento_doc_code' => (string) ($reg['doc_code'] ?? ''),
            'reglamento_required_before_checkout' => (bool) ($reg['required_before_checkout'] ?? true),
            'pay_transfer' => in_array('transfer_proof', $order, true),
            'pay_oxxo' => in_array('openpay_store', $order, true),
            'pay_card' => in_array('openpay_card', $order, true),
            'msi_enabled' => (bool) ($msi['enabled'] ?? true),
            'msi_months' => $msiMonths,
        ];
    }

    /**
     * Actualiza precios de varios productos desde el formulario tabular.
     *
     * @param array<string, mixed> $rows keyed by product id
     * @return int cantidad actualizada
     */
    public function updatePricesBulk(array $rows): int
    {
        $updated = 0;
        foreach ($rows as $idRaw => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $id = (int) $idRaw;
            if ($id < 1) {
                continue;
            }
            $existing = $this->products->find($id);
            if ($existing === null) {
                continue;
            }
            $payload = $this->pricePayloadFromInput($fields, $existing);
            $this->products->update($id, $payload);
            $updated++;
        }

        return $updated;
    }

    /**
     * @return list<string>
     */
    public static function priceCsvHeaders(): array
    {
        return [
            'code',
            'name',
            'cost_price',
            'catalog_price',
            'public_price',
            'price_cncm',
            'price_partner_a',
            'price_partner_b',
            'price_partner_c',
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    public function sendPriceTemplateCsv(array $products, string $filename = 'plantilla-precios.csv'): void
    {
        $headers = self::priceCsvHeaders();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if ($out === false) {
            throw new \RuntimeException('No se pudo generar el CSV.');
        }
        fputcsv($out, $headers);
        foreach ($products as $p) {
            $row = [];
            foreach ($headers as $h) {
                $val = $p[$h] ?? '';
                $row[] = $val === null ? '' : (string) $val;
            }
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    /**
     * Importa precios desde CSV (columna code obligatoria).
     *
     * @return array{updated:int,skipped:int,errors:list<string>}
     */
    public function importPricesFromCsv(string $tmpPath): array
    {
        $handle = fopen($tmpPath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('No se pudo leer el archivo CSV.');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \InvalidArgumentException('El CSV no tiene encabezados.');
        }
        $header = array_map(
            static fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\"")),
            $header
        );
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        }
        $map = array_flip($header);
        if (!isset($map['code'])) {
            fclose($handle);
            throw new \InvalidArgumentException('El CSV debe incluir la columna code.');
        }

        $updated = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if ($this->csvRowEmpty($data)) {
                continue;
            }
            $code = self::normalizeProductCode((string) ($data[$map['code']] ?? ''));
            if ($code === '') {
                $errors[] = "Fila {$line}: código vacío.";
                $skipped++;
                continue;
            }
            $product = $this->products->findByCode($code);
            if ($product === null) {
                $errors[] = "Fila {$line}: no existe el producto {$code}.";
                $skipped++;
                continue;
            }
            $fields = [];
            foreach (['cost_price', 'catalog_price', 'public_price', 'price_cncm', 'price_partner_a', 'price_partner_b', 'price_partner_c'] as $col) {
                if (!isset($map[$col])) {
                    continue;
                }
                $fields[$col] = $data[$map[$col]] ?? '';
            }
            try {
                $payload = $this->pricePayloadFromInput($fields, $product);
                $this->products->update((int) $product['id'], $payload);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = "Fila {$line} ({$code}): " . $e->getMessage();
                $skipped++;
            }
        }
        fclose($handle);

        return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Alta masiva de certificaciones/productos (p.ej. desde un proveedor).
     *
     * @return array{created:int,skipped:int,errors:list<string>}
     */
    public function importProductsFromCsv(string $tmpPath, ?int $defaultGroupId, ?int $defaultSupplierId): array
    {
        $handle = fopen($tmpPath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('No se pudo leer el archivo CSV.');
        }
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \InvalidArgumentException('El CSV no tiene encabezados.');
        }
        $header = array_map(
            static fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\"")),
            $header
        );
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        }
        $map = array_flip($header);
        foreach (['code', 'name'] as $required) {
            if (!isset($map[$required])) {
                fclose($handle);
                throw new \InvalidArgumentException('El CSV debe incluir las columnas code y name.');
            }
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if ($this->csvRowEmpty($data)) {
                continue;
            }
            $get = static function (string $col, mixed $default = '') use ($map, $data): mixed {
                if (!isset($map[$col])) {
                    return $default;
                }

                return $data[$map[$col]] ?? $default;
            };
            $input = [
                'code' => $get('code', ''),
                'name' => $get('name', ''),
                'type' => $get('type', 'certification'),
                'category' => $get('category', 'other'),
                'audience' => $get('audience', 'any'),
                'public_price' => $get('public_price', 0),
                'catalog_price' => $get('catalog_price', ''),
                'cost_price' => $get('cost_price', 0),
                'price_cncm' => $get('price_cncm', ''),
                'price_partner_a' => $get('price_partner_a', ''),
                'price_partner_b' => $get('price_partner_b', ''),
                'price_partner_c' => $get('price_partner_c', ''),
                'is_active' => 1,
                'is_public' => 1,
                'product_group_id' => $defaultGroupId,
                'supplier_id' => $defaultSupplierId,
            ];
            if (isset($map['product_group_code'])) {
                $gCode = self::normalizeGroupCode((string) $get('product_group_code', ''));
                if ($gCode !== '') {
                    $group = $this->groups->findByCode($gCode);
                    if ($group === null) {
                        $errors[] = "Fila {$line}: grupo {$gCode} no existe.";
                        $skipped++;
                        continue;
                    }
                    $input['product_group_id'] = (int) $group['id'];
                    if ($defaultSupplierId === null && !empty($group['supplier_id'])) {
                        $input['supplier_id'] = (int) $group['supplier_id'];
                    }
                }
            }
            try {
                $this->createProduct($input);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Fila {$line}: " . $e->getMessage();
                $skipped++;
            }
        }
        fclose($handle);

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** @return list<string> */
    public static function productBulkCsvHeaders(): array
    {
        return [
            'code',
            'name',
            'type',
            'category',
            'public_price',
            'catalog_price',
            'cost_price',
            'price_cncm',
            'price_partner_a',
            'price_partner_b',
            'price_partner_c',
            'product_group_code',
        ];
    }

    public function sendProductBulkTemplateCsv(string $filename = 'plantilla-certificaciones.csv'): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if ($out === false) {
            throw new \RuntimeException('No se pudo generar el CSV.');
        }
        fputcsv($out, self::productBulkCsvHeaders());
        fputcsv($out, [
            'EJEMPLO-B1',
            'Certificación ejemplo B1',
            'certification',
            'english_adult',
            '2500',
            '3000',
            '1800',
            '2200',
            '2300',
            '2400',
            '2450',
            'itep-exams',
        ]);
        fclose($out);
        exit;
    }

    /** @return list<string> */
    public static function cefrOptions(): array
    {
        return ['Pre-A1', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'];
    }

    /** @return list<string> */
    public static function cenniOptions(): array
    {
        $out = ['N/A'];
        for ($i = 1; $i <= 20; $i++) {
            $out[] = (string) $i;
        }

        return $out;
    }

    /**
     * Lee la configuración de examen de nivel desde products.config_json.
     *
     * @return array{
     *   enabled:bool,
     *   uses_cenni:bool,
     *   score_label:string,
     *   ranges:list<array{min:string,max:string,cefr:string,cenni:string}>
     * }
     */
    public static function levelExamFromConfig(?string $configJson): array
    {
        $cfg = [];
        if ($configJson !== null && trim($configJson) !== '') {
            $decoded = json_decode($configJson, true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }
        $level = is_array($cfg['level_exam'] ?? null) ? $cfg['level_exam'] : [];
        $ranges = [];
        if (is_array($level['ranges'] ?? null)) {
            foreach ($level['ranges'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ranges[] = [
                    'min' => isset($row['min']) && $row['min'] !== null && $row['min'] !== '' ? (string) $row['min'] : '',
                    'max' => isset($row['max']) && $row['max'] !== null && $row['max'] !== '' ? (string) $row['max'] : '',
                    'cefr' => (string) ($row['cefr'] ?? ''),
                    'cenni' => (string) ($row['cenni'] ?? ''),
                ];
            }
        }
        if ($ranges === [] && !empty($level['enabled'])) {
            $ranges[] = ['min' => '', 'max' => '', 'cefr' => '', 'cenni' => ''];
        }

        return [
            'enabled' => (bool) ($level['enabled'] ?? false),
            'uses_cenni' => (bool) ($level['uses_cenni'] ?? false),
            'score_label' => (string) ($level['score_label'] ?? 'Puntaje'),
            'ranges' => $ranges,
        ];
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
            'config_json' => $this->buildProductConfigJson($input, $existing),
        ];
    }

    /**
     * Conserva config_json existente y actualiza la sección level_exam desde el formulario.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function buildProductConfigJson(array $input, ?array $existing = null): string
    {
        $cfg = [];
        if ($existing !== null) {
            $decoded = json_decode((string) ($existing['config_json'] ?? ''), true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }

        if (!empty($input['is_level_exam'])) {
            $ranges = $this->parseLevelExamRanges($input);
            if ($ranges === []) {
                throw new \InvalidArgumentException(
                    'Si marcas "Es un examen de nivel", agrega al menos un rango con puntaje y nivel CEFR.'
                );
            }
            $scoreLabel = trim((string) ($input['level_score_label'] ?? 'Puntaje'));
            if ($scoreLabel === '') {
                $scoreLabel = 'Puntaje';
            }
            $cfg['level_exam'] = [
                'enabled' => true,
                'uses_cenni' => !empty($input['level_uses_cenni']),
                'score_label' => $scoreLabel,
                'ranges' => $ranges,
            ];
        } else {
            unset($cfg['level_exam']);
        }

        if ($cfg === []) {
            return (string) json_encode(new \stdClass(), JSON_UNESCAPED_UNICODE);
        }

        return (string) json_encode($cfg, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{min:float|int,max:float|int,cefr:string,cenni:?string}>
     */
    private function parseLevelExamRanges(array $input): array
    {
        $mins = $input['level_min'] ?? [];
        $maxs = $input['level_max'] ?? [];
        $cefrs = $input['level_cefr'] ?? [];
        $cennis = $input['level_cenni'] ?? [];
        if (!is_array($mins) || !is_array($maxs) || !is_array($cefrs)) {
            return [];
        }

        $usesCenni = !empty($input['level_uses_cenni']);
        $allowedCefr = self::cefrOptions();
        $out = [];
        $count = max(count($mins), count($maxs), count($cefrs));
        for ($i = 0; $i < $count; $i++) {
            $minRaw = trim((string) ($mins[$i] ?? ''));
            $maxRaw = trim((string) ($maxs[$i] ?? ''));
            $cefr = trim((string) ($cefrs[$i] ?? ''));
            $cenni = trim((string) ($cennis[$i] ?? ''));
            if ($minRaw === '' && $maxRaw === '' && $cefr === '' && $cenni === '') {
                continue;
            }
            if ($minRaw === '' || $maxRaw === '' || $cefr === '') {
                throw new \InvalidArgumentException(
                    'Cada rango de nivel debe incluir puntaje mínimo, máximo y nivel CEFR.'
                );
            }
            if (!is_numeric($minRaw) || !is_numeric($maxRaw)) {
                throw new \InvalidArgumentException('Los puntajes de los rangos deben ser numéricos.');
            }
            $min = (float) $minRaw;
            $max = (float) $maxRaw;
            if ($min > $max) {
                throw new \InvalidArgumentException('En un rango, el puntaje mínimo no puede ser mayor que el máximo.');
            }
            if (!in_array($cefr, $allowedCefr, true)) {
                throw new \InvalidArgumentException('Nivel CEFR no válido: ' . $cefr);
            }
            if ($usesCenni) {
                $allowedCenni = self::cenniOptions();
                if ($cenni === '' || !in_array($cenni, $allowedCenni, true)) {
                    throw new \InvalidArgumentException(
                        'Cada rango CENNI debe ser N/A o un nivel del 1 al 20.'
                    );
                }
            }
            $out[] = [
                'min' => $min,
                'max' => $max,
                'cefr' => $cefr,
                'cenni' => $usesCenni ? $cenni : null,
            ];
        }

        return $out;
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

        $config = $this->decodeConfigInput($input);
        $config = $this->applyStructuredGroupConfig($config, $input);
        $configRaw = (string) json_encode($config, JSON_UNESCAPED_UNICODE);

        return [
            'name' => $name,
            'code' => $code,
            'supplier_id' => $supplierId,
            'config_json' => $configRaw,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function decodeConfigInput(array $input): array
    {
        $configRaw = trim((string) ($input['config_json'] ?? ''));
        if ($configRaw === '') {
            $enableMsi = ($input['template'] ?? 'cert') !== 'course';

            return ProductGroupRepository::defaultCheckoutConfig($enableMsi);
        }
        $decoded = json_decode($configRaw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('El JSON de configuración del grupo no es válido.');
        }

        return $decoded;
    }

    /**
     * Mezcla campos de UI (horarios, pagos, reglamento) sobre el JSON base.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyStructuredGroupConfig(array $config, array $input): array
    {
        if (empty($input['apply_structured_config'])) {
            return $config;
        }

        $exam = is_array($config['exam'] ?? null) ? $config['exam'] : [];
        $exam['choose_at_checkout'] = !empty($input['exam_choose_at_checkout']);
        $slot = (int) ($input['exam_slot_minutes'] ?? ($exam['slot_minutes'] ?? 30));
        $exam['slot_minutes'] = max(15, $slot);
        $validity = (int) ($input['exam_validity_months'] ?? ($exam['validity_months'] ?? 6));
        $exam['validity_months'] = max(1, min(36, $validity));
        $config['exam'] = $exam;

        $schedule = is_array($config['schedule'] ?? null) ? $config['schedule'] : [];
        $schedule['min_advance_days'] = max(0, (int) ($input['schedule_min_advance_days'] ?? ($schedule['min_advance_days'] ?? 2)));
        $schedule['available_365'] = !empty($input['schedule_available_365']);
        $daysRaw = $input['schedule_days'] ?? [];
        if (!is_array($daysRaw)) {
            $daysRaw = [];
        }
        $days = [];
        foreach ([0, 1, 2, 3, 4, 5, 6] as $d) {
            $days[(string) $d] = !empty($daysRaw[(string) $d]) || !empty($daysRaw[$d]);
        }
        if (!in_array(true, $days, true)) {
            throw new \InvalidArgumentException('Marca al menos un día de la semana para aplicar exámenes.');
        }
        $schedule['days'] = $days;
        $schedule['weekdays'] = [
            'start' => $this->normalizeClock((string) ($input['schedule_weekdays_start'] ?? '10:00'), '10:00'),
            'end' => $this->normalizeClock((string) ($input['schedule_weekdays_end'] ?? '17:30'), '17:30'),
        ];
        $schedule['saturday'] = [
            'start' => $this->normalizeClock((string) ($input['schedule_saturday_start'] ?? '08:00'), '08:00'),
            'end' => $this->normalizeClock((string) ($input['schedule_saturday_end'] ?? '12:00'), '12:00'),
        ];
        unset($schedule['blocked_dates']);
        $config['schedule'] = $schedule;

        $order = [];
        if (!empty($input['pay_transfer'])) {
            $order[] = 'transfer_proof';
        }
        if (!empty($input['pay_oxxo'])) {
            $order[] = 'openpay_store';
        }
        if (!empty($input['pay_card'])) {
            $order[] = 'openpay_card';
        }
        if ($order === []) {
            $order = ['transfer_proof', 'openpay_store', 'openpay_card'];
        }
        $payments = is_array($config['payments'] ?? null) ? $config['payments'] : [];
        $payments['default_method'] = $order[0];
        $payments['order'] = $order;
        $payments['price_includes_fee'] = (bool) ($payments['price_includes_fee'] ?? false);
        $config['payments'] = $payments;

        $msiMonthsRaw = $input['msi_months'] ?? [];
        if (!is_array($msiMonthsRaw)) {
            $msiMonthsRaw = [];
        }
        $msiMonths = [];
        foreach ($msiMonthsRaw as $m) {
            $mi = (int) $m;
            if (in_array($mi, [1, 3, 6, 9, 12], true)) {
                $msiMonths[] = $mi;
            }
        }
        $msiMonths = array_values(array_unique($msiMonths));
        sort($msiMonths);
        if ($msiMonths === []) {
            $msiMonths = [1];
        }
        $config['card_msi'] = [
            'enabled' => !empty($input['msi_enabled']),
            'months' => $msiMonths,
            'min_amount' => 0,
        ];

        if (!empty($input['reglamento_enabled'])) {
            $path = trim((string) ($input['reglamento_template_path'] ?? ''));
            $source = trim((string) ($input['reglamento_source_url'] ?? ''));
            if ($path === '' && $source === '') {
                throw new \InvalidArgumentException(
                    'Si activas el reglamento, indica la ruta/plantilla PDF o el link externo.'
                );
            }
            $docCode = strtolower(trim((string) ($input['reglamento_doc_code'] ?? '')));
            $docCode = preg_replace('/[^a-z0-9_-]+/', '_', $docCode) ?? '';
            $docCode = trim($docCode, '_');
            if ($docCode === '') {
                $groupCode = self::normalizeGroupCode((string) ($input['code'] ?? 'grupo'));
                $docCode = 'reglamento_' . str_replace('-', '_', $groupCode !== '' ? $groupCode : 'grupo');
            }
            $excludeId = isset($input['_group_id']) ? (int) $input['_group_id'] : null;
            $conflict = $this->findGroupUsingDocCode($docCode, $excludeId);
            if ($conflict !== null) {
                throw new \InvalidArgumentException(
                    'El código de documento "' . $docCode . '" ya lo usa el grupo ' . $conflict . '. Elige otro.'
                );
            }
            $config['reglamento'] = [
                'template_path' => $path,
                'source_url' => $source,
                'signature_mode' => 'append_to_pdf',
                'required_before_checkout' => !empty($input['reglamento_required_before_checkout']),
                'doc_code' => $docCode,
            ];
        } else {
            unset($config['reglamento']);
        }

        return $config;
    }


    /**
     * @return array<string, string> doc_code => group code
     */
    public function usedReglamentoDocCodes(?int $excludeGroupId = null): array
    {
        $out = [];
        foreach ($this->groups->all() as $group) {
            $gid = (int) ($group['id'] ?? 0);
            if ($excludeGroupId !== null && $gid === $excludeGroupId) {
                continue;
            }
            $decoded = json_decode((string) ($group['config_json'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $reg = is_array($decoded['reglamento'] ?? null) ? $decoded['reglamento'] : [];
            $code = trim((string) ($reg['doc_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = (string) ($group['code'] ?? ('#' . $gid));
        }

        return $out;
    }

    private function findGroupUsingDocCode(string $docCode, ?int $excludeGroupId = null): ?string
    {
        $used = $this->usedReglamentoDocCodes($excludeGroupId);

        return $used[$docCode] ?? null;
    }

    private function normalizeClock(string $clock, string $fallback): string
    {
        $clock = trim($clock);
        if (!preg_match('/^\d{1,2}:\d{2}$/', $clock)) {
            return $fallback;
        }
        [$h, $m] = array_map('intval', explode(':', $clock));
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return $fallback;
        }

        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function pricePayloadFromInput(array $input, array $existing): array
    {
        $publicRaw = $input['public_price'] ?? null;
        $publicPrice = ($publicRaw === null || $publicRaw === '')
            ? round(max(0, (float) ($existing['public_price'] ?? 0)), 2)
            : round(max(0, (float) $publicRaw), 2);

        $catalogRaw = $input['catalog_price'] ?? null;
        if ($catalogRaw === null || $catalogRaw === '') {
            $catalogPrice = isset($existing['catalog_price']) && $existing['catalog_price'] !== null && $existing['catalog_price'] !== ''
                ? round(max(0, (float) $existing['catalog_price']), 2)
                : Settings::catalogPriceFromPublic($publicPrice);
        } else {
            $catalogPrice = round(max(0, (float) $catalogRaw), 2);
        }

        $costRaw = $input['cost_price'] ?? null;
        $costPrice = ($costRaw === null || $costRaw === '')
            ? round(max(0, (float) ($existing['cost_price'] ?? 0)), 2)
            : round(max(0, (float) $costRaw), 2);

        return [
            'public_price' => $publicPrice,
            'catalog_price' => $catalogPrice,
            'cost_price' => $costPrice,
            'price_cncm' => array_key_exists('price_cncm', $input)
                ? $this->nullableMoney($input['price_cncm'])
                : $this->nullableMoney($existing['price_cncm'] ?? null),
            'price_partner_a' => array_key_exists('price_partner_a', $input)
                ? $this->nullableMoney($input['price_partner_a'])
                : $this->nullableMoney($existing['price_partner_a'] ?? null),
            'price_partner_b' => array_key_exists('price_partner_b', $input)
                ? $this->nullableMoney($input['price_partner_b'])
                : $this->nullableMoney($existing['price_partner_b'] ?? null),
            'price_partner_c' => array_key_exists('price_partner_c', $input)
                ? $this->nullableMoney($input['price_partner_c'])
                : $this->nullableMoney($existing['price_partner_c'] ?? null),
        ];
    }

    /** @param list<mixed>|false $data */
    private function csvRowEmpty(array|false $data): bool
    {
        if ($data === false) {
            return true;
        }
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
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
