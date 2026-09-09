<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CertifierRepository;
use App\Repositories\ProductGroupRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;
use App\Support\Settings;

// CheckoutRequirements vive en el mismo namespace.

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

    /** Código interno único a partir del nombre (grupos). */
    public function allocateUniqueGroupCode(string $name): string
    {
        $base = self::normalizeGroupCode(self::slugify($name));
        if (strlen($base) < 2) {
            $base = 'grupo';
        }
        $candidate = $base;
        $n = 2;
        while ($this->groups->findByCode($candidate) !== null) {
            $candidate = $base . '-' . $n;
            $n++;
            if ($n > 500) {
                throw new \RuntimeException('No se pudo generar un código único para el grupo.');
            }
        }

        return $candidate;
    }

    /** Código interno único a partir del nombre (productos). */
    public function allocateUniqueProductCode(string $name): string
    {
        $base = self::normalizeProductCode(str_replace('-', '_', self::slugify($name)));
        if (strlen($base) < 2) {
            $base = 'PRODUCTO';
        }
        $candidate = $base;
        $n = 2;
        while ($this->products->findByCode($candidate) !== null) {
            $candidate = $base . '_' . $n;
            $n++;
            if ($n > 500) {
                throw new \RuntimeException('No se pudo generar un código único para el producto.');
            }
        }

        return $candidate;
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
        $input = $this->storeProviderWorkbookFromInput($input, null);
        $pdfFile = is_array($input['_instruction_pdf_file'] ?? null) ? $input['_instruction_pdf_file'] : null;
        $docFiles = is_array($input['_instruction_doc_files'] ?? null) ? $input['_instruction_doc_files'] : null;
        unset($input['_instruction_pdf_file'], $input['_instruction_doc_files']);
        $parsed = $this->buildGroupPayload($input, true);
        if ($this->groups->findByCode($parsed['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe un grupo con el código ' . $parsed['code']);
        }
        $id = $this->groups->create($parsed);
        $hasPdf = $pdfFile !== null;
        $hasDocs = is_array($docFiles) && $docFiles !== [];
        if ($hasPdf || $hasDocs) {
            if ($hasPdf) {
                $input['_instruction_pdf_file'] = $pdfFile;
            }
            if ($docFiles !== null && $docFiles !== []) {
                $input['_instruction_doc_files'] = $docFiles;
            }
            $input['_group_id'] = $id;
            $input = $this->storeInstructionPdfFromInput($input, $id);
            $parsedAgain = $this->buildGroupPayload($input, false);
            unset($parsedAgain['code']);
            $this->groups->update($id, $parsedAgain);
        }
        $this->savePipelineStepsFromInput($input);

        return $id;
    }

    public function updateGroup(int $id, array $input): void
    {
        $input['_group_id'] = $id;
        if ($this->groups->find($id) === null) {
            throw new \InvalidArgumentException('Grupo no encontrado.');
        }
        $input = $this->storeProviderWorkbookFromInput($input, $id);
        $input = $this->storeInstructionPdfFromInput($input, $id);
        $parsed = $this->buildGroupPayload($input, false);
        unset($parsed['code']);
        $this->groups->update($id, $parsed);
        $this->savePipelineStepsFromInput($input);
    }

    public function deleteProduct(int $id): void
    {
        $product = $this->products->find($id);
        if ($product === null) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }
        if ($this->products->countPurchaseItems($id) > 0 || $this->products->countTrackings($id) > 0) {
            throw new \InvalidArgumentException(
                'No se puede eliminar: ya hay compras o seguimientos con este producto. Márcalo inactivo.'
            );
        }
        $this->products->delete($id);
    }

    public function deleteGroup(int $id): void
    {
        if ($this->groups->find($id) === null) {
            throw new \InvalidArgumentException('Grupo no encontrado.');
        }
        if ($this->groups->countProducts($id) > 0) {
            throw new \InvalidArgumentException(
                'No se puede eliminar: el grupo aún tiene productos. Muévelos o elimínalos antes.'
            );
        }
        $this->groups->delete($id);
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
        $instr = is_array($cfg['exam_instructions'] ?? null) ? $cfg['exam_instructions'] : [];

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

        $checkoutFields = [];
        if (array_key_exists('checkout_fields', $cfg) && is_array($cfg['checkout_fields'])) {
            foreach ($cfg['checkout_fields'] as $code) {
                if (is_string($code) && isset(CheckoutRequirements::allFieldMeta()[$code])) {
                    $checkoutFields[] = $code;
                }
            }
        } else {
            $checkoutFields = ['email', 'first_name', 'last_name_p', 'last_name_m', 'phone'];
        }
        foreach (['email', 'first_name', 'last_name_p', 'phone'] as $must) {
            if (!in_array($must, $checkoutFields, true)) {
                array_unshift($checkoutFields, $must);
            }
        }
        $checkoutFields = array_values(array_unique($checkoutFields));

        $checkoutFieldRequired = [];
        if (isset($cfg['checkout_field_required']) && is_array($cfg['checkout_field_required'])) {
            foreach ($cfg['checkout_field_required'] as $code => $flag) {
                if (is_string($code) && $code !== '') {
                    $checkoutFieldRequired[$code] = (bool) $flag;
                }
            }
        }

        return [
            'exam_choose_at_checkout' => (bool) ($exam['choose_at_checkout'] ?? true),
            'exam_slot_minutes' => max(15, (int) ($exam['slot_minutes'] ?? 30)),
            'exam_validity_months' => max(1, (int) ($exam['validity_months'] ?? 6)),
            'exam_capture_zoom' => !empty($exam['capture_zoom']),
            'exam_extra_field_label' => trim((string) ($exam['extra_field_label'] ?? '')),
            'schedule_mode' => ExamScheduleService::normalizeMode((string) ($schedule['mode'] ?? ExamScheduleService::MODE_WINDOW)),
            'schedule_min_advance_days' => max(0, (int) ($schedule['min_advance_days'] ?? 2)),
            'schedule_available_365' => (bool) ($schedule['available_365'] ?? false),
            'schedule_days' => $days,
            'schedule_weekdays_start' => (string) ($weekdays['start'] ?? '10:00'),
            'schedule_weekdays_end' => (string) ($weekdays['end'] ?? '17:30'),
            'schedule_saturday_start' => (string) ($saturday['start'] ?? '08:00'),
            'schedule_saturday_end' => (string) ($saturday['end'] ?? '12:00'),
            'schedule_checkout_help' => trim((string) ($schedule['checkout_help'] ?? '')),
            'schedule_fixed_slots_text' => self::fixedSlotsToText($schedule['fixed_slots'] ?? []),
            'schedule_sessions_text' => self::sessionsToText($schedule['sessions'] ?? []),
            'extraordinary_enabled' => !empty(($schedule['extraordinary'] ?? [])['enabled']),
            'extraordinary_surcharge' => (float) (($schedule['extraordinary'] ?? [])['surcharge_amount'] ?? 0),
            'extraordinary_label' => (string) (($schedule['extraordinary'] ?? [])['surcharge_label'] ?? 'Fecha extraordinaria'),
            'extraordinary_requires_admin' => array_key_exists('requires_admin_approval', $schedule['extraordinary'] ?? [])
                ? !empty(($schedule['extraordinary'] ?? [])['requires_admin_approval'])
                : true,
            'inventory_enabled' => !empty(($cfg['inventory'] ?? [])['enabled']),
            'inventory_send_days_before' => max(0, (int) (($cfg['inventory'] ?? [])['send_access_days_before'] ?? 3)),
            'inventory_assign_within_days' => max(0, (int) (($cfg['inventory'] ?? [])['assign_within_days'] ?? 3)),
            'inventory_low_stock_threshold' => max(0, (int) (($cfg['inventory'] ?? [])['low_stock_threshold'] ?? 5)),
            'inventory_student_months' => max(1, (int) (($cfg['inventory'] ?? [])['student_validity_months'] ?? 6)),
            'inventory_provider_months' => max(1, (int) (($cfg['inventory'] ?? [])['provider_validity_months'] ?? 12)),
            'inventory_reallocate_enabled' => array_key_exists('reallocate_enabled', $cfg['inventory'] ?? [])
                ? !empty(($cfg['inventory'] ?? [])['reallocate_enabled'])
                : true,
            'inventory_reallocate_min_future_days' => max(1, (int) (($cfg['inventory'] ?? [])['reallocate_min_future_days'] ?? 14)),
            'inventory_access_mail_template' => (string) (($cfg['inventory'] ?? [])['access_mail_template'] ?? 'student_inventory_exam_access'),
            'inventory_results_mail_template' => (string) (($cfg['inventory'] ?? [])['results_mail_template'] ?? 'student_results_cenni'),
            'inventory_low_stock_mail_template' => trim((string) (($cfg['inventory'] ?? [])['low_stock_mail_template'] ?? '')),
            'results_delivery' => ResultsDeliveryService::fromConfig($cfg),
            'reglamento_enabled' => $reg !== [] && (
                trim((string) ($reg['template_path'] ?? '')) !== ''
                || trim((string) ($reg['source_url'] ?? '')) !== ''
            ),
            'reglamento_template_path' => (string) ($reg['template_path'] ?? ''),
            'reglamento_source_url' => (string) ($reg['source_url'] ?? ''),
            'reglamento_doc_code' => (string) ($reg['doc_code'] ?? ''),
            'instruction_pdf_path' => trim((string) ($instr['pdf_path'] ?? '')),
            'instruction_pdf_url' => trim((string) ($instr['pdf_url'] ?? '')),
            'instruction_pdf_label' => trim((string) ($instr['pdf_label'] ?? '')),
            'instruction_video_url' => trim((string) ($instr['video_url'] ?? '')),
            'instruction_video_label' => trim((string) ($instr['video_label'] ?? '')),
            'instruction_docs' => ExamInstructionAssets::normalizeDocuments($instr),
            'checkout_fields' => $checkoutFields,
            'checkout_field_required' => $checkoutFieldRequired,
            'pay_transfer' => in_array('transfer_proof', $order, true),
            'pay_oxxo' => in_array('openpay_store', $order, true),
            'pay_card' => in_array('openpay_card', $order, true),
            'msi_enabled' => (bool) ($msi['enabled'] ?? true),
            'msi_months' => $msiMonths,
            'pipeline_code' => trim((string) ($cfg['pipeline_code'] ?? '')),
            'initial_step_code' => trim((string) ($cfg['initial_step_code'] ?? '')),
            'provider_request' => self::providerRequestExtrasFromConfig($cfg),
            'emails' => GroupEmailAutomation::normalize($cfg['emails'] ?? null),
            'step_defs' => GroupStepConfig::defsFromConfig($cfg),
        ];
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>
     */
    private static function providerRequestExtrasFromConfig(array $cfg): array
    {
        $raw = is_array($cfg['provider_request'] ?? null) ? $cfg['provider_request'] : [];
        $wb = is_array($raw['workbook'] ?? null) ? $raw['workbook'] : [];
        $cellMap = [];
        foreach (is_array($wb['cell_map'] ?? null) ? $wb['cell_map'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cellMap[] = [
                'cell' => strtoupper(trim((string) ($item['cell'] ?? ''))),
                'field' => trim((string) ($item['field'] ?? '')),
            ];
        }

        return [
            'enabled' => !empty($raw['enabled']),
            'auto_send_on_payment' => array_key_exists('auto_send_on_payment', $raw)
                ? (bool) $raw['auto_send_on_payment']
                : false,
            'step_code' => trim((string) ($raw['step_code'] ?? 'solicitud_proveedor')),
            'to' => trim((string) ($raw['to'] ?? '')),
            'cc' => trim((string) ($raw['cc'] ?? '')),
            'mail_template_code' => trim((string) ($raw['mail_template_code'] ?? '')),
            'include_student_data' => array_key_exists('include_student_data', $raw)
                ? (bool) $raw['include_student_data']
                : true,
            'include_exam_schedule' => array_key_exists('include_exam_schedule', $raw)
                ? (bool) $raw['include_exam_schedule']
                : true,
            'include_reglamento' => array_key_exists('include_reglamento', $raw)
                ? (bool) $raw['include_reglamento']
                : true,
            'include_payment_proof' => array_key_exists('include_payment_proof', $raw)
                ? (bool) $raw['include_payment_proof']
                : true,
            'require_reglamento' => array_key_exists('require_reglamento', $raw)
                ? (bool) $raw['require_reglamento']
                : true,
            'delivery' => (string) ($raw['delivery'] ?? 'links'),
            'require_admin_payment_proof' => array_key_exists('require_admin_payment_proof', $raw)
                ? (bool) $raw['require_admin_payment_proof']
                : true,
            'auto_send_on_admin_proof' => array_key_exists('auto_send_on_admin_proof', $raw)
                ? (bool) $raw['auto_send_on_admin_proof']
                : true,
            'workbook_enabled' => !empty($wb['enabled']),
            'workbook_template_path' => trim((string) ($wb['template_path'] ?? '')),
            'workbook_attach' => array_key_exists('attach', $wb) ? (bool) $wb['attach'] : false,
            'workbook_sheet' => trim((string) ($wb['sheet'] ?? '')),
            'workbook_normalize' => in_array((string) ($wb['normalize'] ?? 'none'), ['none', 'toefl'], true)
                ? (string) ($wb['normalize'] ?? 'none')
                : 'none',
            'workbook_cell_map' => $cellMap,
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
        csv_download_headers($filename);
        $out = fopen('php://output', 'w');
        if ($out === false) {
            throw new \RuntimeException('No se pudo generar el CSV.');
        }
        csv_put($out, $headers);
        foreach ($products as $p) {
            $row = [];
            foreach ($headers as $h) {
                $val = $p[$h] ?? '';
                $row[] = $val === null ? '' : (string) $val;
            }
            csv_put($out, $row);
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

        $header = csv_get($handle);
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
        $map = [];
        foreach ($header as $i => $col) {
            if ($col === '') {
                continue;
            }
            $map[$col] = $i;
        }
        if (!isset($map['code'])) {
            fclose($handle);
            throw new \InvalidArgumentException('El CSV debe incluir la columna code.');
        }

        $updated = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;
        while (($data = csv_get($handle)) !== false) {
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
     * Alta/actualización masiva de certificaciones/productos.
     * La clave es la columna code.
     *
     * @param 'create'|'update'|'upsert' $mode
     * @return array{created:int,updated:int,skipped:int,errors:list<string>}
     */
    public function importProductsFromCsv(
        string $tmpPath,
        ?int $defaultGroupId,
        ?int $defaultSupplierId,
        string $mode = 'create'
    ): array {
        if (!in_array($mode, ['create', 'update', 'upsert'], true)) {
            $mode = 'create';
        }
        $handle = fopen($tmpPath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('No se pudo leer el archivo CSV.');
        }
        $header = csv_get($handle);
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
        $map = [];
        foreach ($header as $i => $col) {
            if ($col === '') {
                continue;
            }
            $map[$col] = $i;
        }
        foreach (['code', 'name'] as $required) {
            if (!isset($map[$required])) {
                fclose($handle);
                throw new \InvalidArgumentException('El CSV debe incluir las columnas code y name.');
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;
        while (($data = csv_get($handle)) !== false) {
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
            $code = self::normalizeProductCode((string) $get('code', ''));
            if ($code === '') {
                $errors[] = "Fila {$line}: código vacío.";
                $skipped++;
                continue;
            }
            $existing = $this->products->findByCode($code);
            if ($mode === 'create' && $existing !== null) {
                $errors[] = "Fila {$line}: el código {$code} ya existe (modo solo crear).";
                $skipped++;
                continue;
            }
            if ($mode === 'update' && $existing === null) {
                $errors[] = "Fila {$line}: no existe el producto {$code} (modo solo actualizar).";
                $skipped++;
                continue;
            }

            $input = [
                'code' => $code,
                'name' => $get('name', ''),
                'type' => $get('type', $existing['type'] ?? 'certification'),
                'category' => $get('category', $existing['category'] ?? 'other'),
                'audience' => $get('audience', $existing['audience'] ?? 'any'),
                'public_price' => $get('public_price', $existing['public_price'] ?? 0),
                'catalog_price' => $get('catalog_price', $existing['catalog_price'] ?? ''),
                'cost_price' => $get('cost_price', $existing['cost_price'] ?? 0),
                'price_cncm' => $get('price_cncm', $existing['price_cncm'] ?? ''),
                'price_partner_a' => $get('price_partner_a', $existing['price_partner_a'] ?? ''),
                'price_partner_b' => $get('price_partner_b', $existing['price_partner_b'] ?? ''),
                'price_partner_c' => $get('price_partner_c', $existing['price_partner_c'] ?? ''),
                'product_group_id' => $defaultGroupId ?? ($existing['product_group_id'] ?? null),
                // No reasignar proveedor en masa al actualizar: conservar el existente
                // salvo que la fila traiga supplier_code o sea un alta nueva con default.
                'supplier_id' => $existing['supplier_id'] ?? null,
            ];

            $rowSupplierResolved = false;
            if (isset($map['supplier_code'])) {
                $sCode = \App\Services\SupplierAdminService::normalizeCode((string) $get('supplier_code', ''));
                if ($sCode !== '') {
                    $supplier = $this->suppliers->findByCode($sCode);
                    if ($supplier === null) {
                        $errors[] = "Fila {$line}: proveedor {$sCode} no existe.";
                        $skipped++;
                        continue;
                    }
                    $input['supplier_id'] = (int) $supplier['id'];
                    $rowSupplierResolved = true;
                }
            }
            if (!$rowSupplierResolved && $existing === null && $defaultSupplierId !== null) {
                $input['supplier_id'] = $defaultSupplierId;
                $rowSupplierResolved = true;
            }
            if (isset($map['is_public'])) {
                $raw = trim((string) $get('is_public', ''));
                if ($raw !== '') {
                    $input['is_public'] = in_array(strtolower($raw), ['1', 'si', 'sí', 'yes', 'true'], true) ? 1 : 0;
                } elseif ($existing !== null) {
                    $input['is_public'] = (int) ($existing['is_public'] ?? 1);
                } else {
                    $input['is_public'] = 1;
                }
            } elseif ($existing !== null) {
                $input['is_public'] = (int) ($existing['is_public'] ?? 1);
            } else {
                $input['is_public'] = 1;
            }
            if (isset($map['is_star'])) {
                $raw = trim((string) $get('is_star', ''));
                if ($raw !== '') {
                    $input['is_star'] = in_array(strtolower($raw), ['1', 'si', 'sí', 'yes', 'true'], true) ? 1 : 0;
                } elseif ($existing !== null) {
                    $input['is_star'] = (int) ($existing['is_star'] ?? 0);
                }
            } elseif ($existing !== null) {
                $input['is_star'] = (int) ($existing['is_star'] ?? 0);
            }
            if ($existing !== null) {
                $input['is_active'] = (int) ($existing['is_active'] ?? 1);
            } else {
                $input['is_active'] = 1;
            }
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
                    if (
                        !$rowSupplierResolved
                        && $existing === null
                        && empty($input['supplier_id'])
                        && !empty($group['supplier_id'])
                    ) {
                        $input['supplier_id'] = (int) $group['supplier_id'];
                    }
                }
            }
            if ($existing === null && empty($input['supplier_id'])) {
                $errors[] = "Fila {$line} ({$code}): indica supplier_code en el CSV o elige un proveedor por defecto para altas nuevas.";
                $skipped++;
                continue;
            }
            try {
                if ($existing !== null) {
                    $this->updateProduct((int) $existing['id'], $input);
                    $updated++;
                } else {
                    $this->createProduct($input);
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Fila {$line} ({$code}): " . $e->getMessage();
                $skipped++;
            }
        }
        fclose($handle);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
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
            'supplier_code',
            'is_public',
            'is_star',
        ];
    }

    public function sendProductBulkTemplateCsv(string $filename = 'plantilla-certificaciones.csv'): void
    {
        csv_download_headers($filename);
        $out = fopen('php://output', 'w');
        if ($out === false) {
            throw new \RuntimeException('No se pudo generar el CSV.');
        }
        csv_put($out, self::productBulkCsvHeaders());
        csv_put($out, [
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
            'itep',
            '1',
            '0',
        ]);
        fclose($out);
        exit;
    }

    /**
     * Exporta productos existentes (mismos encabezados de la plantilla) para editar y re-subir.
     *
     * @param array<string, mixed> $filters
     */
    public function sendProductsExportCsv(?string $q = null, array $filters = [], string $filename = 'productos-export.csv'): void
    {
        $rows = $this->products->adminList($q, null, null, $filters);
        csv_download_headers($filename);
        $out = fopen('php://output', 'w');
        if ($out === false) {
            throw new \RuntimeException('No se pudo generar el CSV.');
        }
        $headers = self::productBulkCsvHeaders();
        csv_put($out, $headers);
        foreach ($rows as $p) {
            csv_put($out, [
                (string) ($p['code'] ?? ''),
                (string) ($p['name'] ?? ''),
                (string) ($p['type'] ?? 'certification'),
                (string) ($p['category'] ?? 'other'),
                (string) ($p['public_price'] ?? ''),
                (string) ($p['catalog_price'] ?? ''),
                (string) ($p['cost_price'] ?? ''),
                (string) ($p['price_cncm'] ?? ''),
                (string) ($p['price_partner_a'] ?? ''),
                (string) ($p['price_partner_b'] ?? ''),
                (string) ($p['price_partner_c'] ?? ''),
                (string) ($p['product_group_code'] ?? ''),
                (string) ($p['supplier_code'] ?? ''),
                !empty($p['is_public']) ? '1' : '0',
                !empty($p['is_star']) ? '1' : '0',
            ]);
        }
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
            'cambridge-flexible' => ['name' => 'Cambridge · Ventana Lun–Vie 9–18', 'supplier' => 'creative', 'msi' => true],
            'cambridge-fixed' => ['name' => 'Cambridge · Convocatorias fijas', 'supplier' => 'creative', 'msi' => true],
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
        $name = trim((string) ($input['name'] ?? ($existing['name'] ?? '')));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del producto es obligatorio.');
        }

        if ($existing !== null) {
            $code = self::normalizeProductCode((string) ($existing['code'] ?? ''));
        } else {
            $code = self::normalizeProductCode((string) ($input['code'] ?? ''));
            if (strlen($code) < 2 || $this->products->findByCode($code) !== null) {
                $code = $this->allocateUniqueProductCode($name);
            }
        }
        if (strlen($code) < 2) {
            throw new \InvalidArgumentException('No se pudo asignar un código interno al producto.');
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
        if ($supplierId !== null && $certifierId !== null) {
            $allowed = $this->suppliers->certifierIds($supplierId);
            if ($allowed !== [] && !in_array($certifierId, $allowed, true)) {
                throw new \InvalidArgumentException(
                    'Esa certificadora no está vinculada a este proveedor. '
                    . 'Agrégala en Proveedores → Certificadoras.'
                );
            }
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
        if ($requireCode) {
            if (strlen($code) < 2) {
                $code = $this->allocateUniqueGroupCode($name);
            } elseif ($this->groups->findByCode($code) !== null) {
                // Si el usuario/legacy envió un código ya usado, regenerar desde el nombre.
                $code = $this->allocateUniqueGroupCode($name);
            }
        }
        // Que el resto de la config (p. ej. reglamento_doc_code) use el código definitivo.
        $input['code'] = $code;

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
        $exam['capture_zoom'] = !empty($input['exam_capture_zoom']);
        $extraLabel = trim((string) ($input['exam_extra_field_label'] ?? ''));
        if ($extraLabel !== '') {
            $exam['extra_field_label'] = mb_substr($extraLabel, 0, 60);
        } else {
            unset($exam['extra_field_label']);
        }
        $config['exam'] = $exam;

        $existingInstr = is_array($config['exam_instructions'] ?? null) ? $config['exam_instructions'] : [];
        $documents = $this->parseInstructionDocumentsFromInput($input, $existingInstr);

        $pdfPath = '';
        $pdfUrl = '';
        $pdfLabel = '';
        $videoUrl = '';
        $videoLabel = '';
        foreach ($documents as $doc) {
            $kind = (string) ($doc['kind'] ?? 'file');
            if ($pdfPath === '' && $pdfUrl === '' && ($kind === 'file' || $kind === 'link')) {
                $pdfPath = trim((string) ($doc['path'] ?? ''));
                $pdfUrl = trim((string) ($doc['url'] ?? ''));
                $pdfLabel = trim((string) ($doc['label'] ?? ''));
            }
            if ($videoUrl === '' && $kind === 'video') {
                $videoUrl = trim((string) ($doc['url'] ?? ''));
                $videoLabel = trim((string) ($doc['label'] ?? ''));
            }
        }

        if ($documents !== []) {
            $config['exam_instructions'] = [
                'pdf_path' => $pdfPath !== '' ? $pdfPath : null,
                'pdf_url' => $pdfUrl !== '' ? $pdfUrl : null,
                'pdf_label' => $pdfLabel !== '' ? mb_substr($pdfLabel, 0, 120) : 'Guía / PDF de instrucciones',
                'video_url' => $videoUrl !== '' ? $videoUrl : null,
                'video_label' => $videoLabel !== '' ? mb_substr($videoLabel, 0, 120) : 'Video de instrucciones',
                'documents' => $documents,
            ];
        } else {
            unset($config['exam_instructions']);
        }

        $schedule = is_array($config['schedule'] ?? null) ? $config['schedule'] : [];
        $schedule['mode'] = ExamScheduleService::normalizeMode(
            (string) ($input['schedule_mode'] ?? ($schedule['mode'] ?? ExamScheduleService::MODE_WINDOW))
        );
        $schedule['min_advance_days'] = max(0, (int) ($input['schedule_min_advance_days'] ?? ($schedule['min_advance_days'] ?? 2)));
        $schedule['available_365'] = !empty($input['schedule_available_365']);
        $schedule['checkout_help'] = trim((string) ($input['schedule_checkout_help'] ?? ''));
        $daysRaw = $input['schedule_days'] ?? [];
        if (!is_array($daysRaw)) {
            $daysRaw = [];
        }
        $days = [];
        foreach ([0, 1, 2, 3, 4, 5, 6] as $d) {
            $days[(string) $d] = !empty($daysRaw[(string) $d]) || !empty($daysRaw[$d]);
        }
        if (!in_array(true, $days, true) && $schedule['mode'] === ExamScheduleService::MODE_WINDOW) {
            throw new \InvalidArgumentException('Marca al menos un día de la semana para aplicar exámenes.');
        }
        $schedule['days'] = $days;
        $schedule['weekdays'] = [
            'start' => $this->normalizeClock((string) ($input['schedule_weekdays_start'] ?? '10:00'), '10:00'),
            'end' => $this->normalizeClock((string) ($input['schedule_weekdays_end'] ?? '17:30'), '17:30', true),
        ];
        $schedule['saturday'] = [
            'start' => $this->normalizeClock((string) ($input['schedule_saturday_start'] ?? '08:00'), '08:00'),
            'end' => $this->normalizeClock((string) ($input['schedule_saturday_end'] ?? '12:00'), '12:00', true),
        ];
        unset($schedule['blocked_dates']);

        $schedule['fixed_slots'] = self::parseFixedSlotsText((string) ($input['schedule_fixed_slots_text'] ?? ''));
        $schedule['sessions'] = self::parseSessionsText((string) ($input['schedule_sessions_text'] ?? ''));
        if (!empty($input['extraordinary_enabled'])) {
            $schedule['extraordinary'] = [
                'enabled' => true,
                'student_may_request' => true,
                'requires_admin_approval' => !empty($input['extraordinary_requires_admin']),
                'surcharge_amount' => max(0, (float) ($input['extraordinary_surcharge'] ?? 0)),
                'surcharge_label' => trim((string) ($input['extraordinary_label'] ?? ''))
                    ?: 'Fecha extraordinaria',
            ];
        } else {
            unset($schedule['extraordinary']);
        }
        if ($schedule['mode'] !== ExamScheduleService::MODE_FIXED_SLOTS) {
            // Conservar texto vacío limpia slots si el admin cambió de modo.
            if (trim((string) ($input['schedule_fixed_slots_text'] ?? '')) === '') {
                unset($schedule['fixed_slots']);
            }
        }
        if ($schedule['mode'] !== ExamScheduleService::MODE_DATED_LIST) {
            if (trim((string) ($input['schedule_sessions_text'] ?? '')) === '') {
                unset($schedule['sessions']);
            }
        }
        $config['schedule'] = $schedule;

        if (!empty($input['inventory_enabled'])) {
            $prevInv = is_array($config['inventory'] ?? null) ? $config['inventory'] : [];
            $config['inventory'] = [
                'enabled' => true,
                'send_access_days_before' => max(0, (int) ($input['inventory_send_days_before'] ?? 3)),
                'assign_within_days' => max(0, (int) ($input['inventory_assign_within_days'] ?? 3)),
                'low_stock_threshold' => max(0, (int) ($input['inventory_low_stock_threshold'] ?? 5)),
                'student_validity_months' => max(1, (int) ($input['inventory_student_months'] ?? 6)),
                'provider_validity_months' => max(1, (int) ($input['inventory_provider_months'] ?? 12)),
                'reallocate_enabled' => !empty($input['inventory_reallocate_enabled']),
                'reallocate_min_future_days' => max(1, (int) ($input['inventory_reallocate_min_future_days'] ?? 14)),
                // Acceso/resultados se definen en Progreso; se conservan defaults internos si ya existían.
                'access_mail_template' => trim((string) ($prevInv['access_mail_template'] ?? ''))
                    ?: 'student_inventory_exam_access',
                'results_mail_template' => trim((string) ($prevInv['results_mail_template'] ?? ''))
                    ?: 'student_results_cenni',
                'low_stock_mail_template' => trim((string) ($input['inventory_low_stock_mail_template'] ?? '')),
            ];
        } else {
            unset($config['inventory']);
        }

        $config = ResultsDeliveryService::applyFromGroupInput($input, $config);

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
            // Si el grupo pide reglamento firmado, siempre es obligatorio antes de pagar.
            $config['reglamento'] = [
                'template_path' => $path,
                'source_url' => $source,
                'signature_mode' => 'append_to_pdf',
                'required_before_checkout' => true,
                'doc_code' => $docCode,
            ];
        } else {
            unset($config['reglamento']);
        }

        // Alta opcional de campo personalizado global (aparece en todos los grupos).
        $newLabel = trim((string) ($input['new_checkout_field_label'] ?? ''));
        if ($newLabel !== '') {
            $newType = (string) ($input['new_checkout_field_type'] ?? 'text');
            $newRequired = !empty($input['new_checkout_field_required']);
            $newOptions = $input['new_checkout_field_options'] ?? null;
            $created = CheckoutRequirements::addCustomField($newLabel, $newType, $newRequired, null, $newOptions);
            $fieldsRaw = $input['checkout_fields'] ?? [];
            if (!is_array($fieldsRaw)) {
                $fieldsRaw = [];
            }
            $fieldsRaw[] = $created['code'];
            $input['checkout_fields'] = $fieldsRaw;
        }

        $fieldsRaw = $input['checkout_fields'] ?? [];
        if (!is_array($fieldsRaw)) {
            $fieldsRaw = [];
        }
        $allMeta = CheckoutRequirements::allFieldMeta();
        $fields = [];
        foreach ($fieldsRaw as $code) {
            if (is_string($code) && isset($allMeta[$code])) {
                $fields[] = $code;
            }
        }
        foreach (['email', 'first_name', 'last_name_p', 'phone'] as $must) {
            if (!in_array($must, $fields, true)) {
                array_unshift($fields, $must);
            }
        }
        $config['checkout_fields'] = array_values(array_unique($fields));

        $reqRaw = $input['checkout_field_required'] ?? [];
        if (!is_array($reqRaw)) {
            $reqRaw = [];
        }
        $requiredMap = [];
        foreach ($config['checkout_fields'] as $code) {
            if (in_array($code, ['email', 'first_name', 'last_name_p', 'phone'], true)) {
                continue;
            }
            if (!array_key_exists($code, $reqRaw)) {
                // Sin override explícito: conservar default del catálogo.
                continue;
            }
            $requiredMap[$code] = ((string) $reqRaw[$code] === '1' || $reqRaw[$code] === true || $reqRaw[$code] === 1);
        }
        if ($requiredMap !== []) {
            $config['checkout_field_required'] = $requiredMap;
        } else {
            unset($config['checkout_field_required']);
        }

        $pipelineCode = strtolower(trim((string) ($input['pipeline_code'] ?? '')));
        $pipelineCode = preg_replace('/[^a-z0-9_-]+/', '_', $pipelineCode) ?? '';
        $pipelineCode = trim($pipelineCode, '_');
        if ($pipelineCode !== '') {
            $config['pipeline_code'] = $pipelineCode;
        } else {
            unset($config['pipeline_code']);
        }

        // El paso inicial siempre es el primero de la plantilla de progreso.
        unset($config['initial_step_code']);

        $config = $this->applyProviderRequestConfig($config, $input);
        $config = $this->applyEmailsConfig($config, $input);
        // Pasos unificados (botón ops + correo + admin_only) pisan on_steps / sync provider.
        if (isset($input['pipeline_steps']) && is_array($input['pipeline_steps'])) {
            $config = GroupStepConfig::applyFromGroupInput($input, $config);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyEmailsConfig(array $config, array $input): array
    {
        $existing = GroupEmailAutomation::normalize($config['emails'] ?? null);
        $onSteps = [];
        $codes = $input['email_step_codes'] ?? [];
        $templates = $input['email_step_templates'] ?? [];
        $modes = $input['email_step_modes'] ?? [];
        $audiences = $input['email_step_audiences'] ?? [];
        if (is_array($codes) && is_array($templates)) {
            foreach ($codes as $i => $codeRaw) {
                $onSteps[] = [
                    'step_code' => (string) $codeRaw,
                    'template_code' => (string) ($templates[$i] ?? ''),
                    'mode' => (string) (is_array($modes) ? ($modes[$i] ?? 'admin') : 'admin'),
                    'audience' => (string) (is_array($audiences) ? ($audiences[$i] ?? 'student') : 'student'),
                ];
            }
        } else {
            $onSteps = $existing['on_steps'];
        }

        $examExisting = is_array($existing['student_exam_access'] ?? null) ? $existing['student_exam_access'] : [];
        $config['emails'] = GroupEmailAutomation::normalize([
            // Legacy: se conservan en JSON pero inertes; solo pasos del progreso envían.
            GroupEmailAutomation::KEY_REGISTRATION => [
                'enabled' => false,
                'template_code' => trim((string) ($existing['student_registration']['template_code']
                    ?? 'student_registration')),
            ],
            GroupEmailAutomation::KEY_PAYMENT => [
                'enabled' => false,
                'template_code' => trim((string) ($existing['student_payment_confirmed']['template_code']
                    ?? 'student_payment_confirmed')),
            ],
            GroupEmailAutomation::KEY_EXAM_ACCESS => [
                'enabled' => array_key_exists('email_exam_access_enabled', $input)
                    ? !empty($input['email_exam_access_enabled'])
                    : !empty($examExisting['enabled']),
                'template_code' => trim((string) ($input['email_exam_access_template']
                    ?? $examExisting['template_code']
                    ?? 'student_elet_exam_access')),
                'mode' => (string) ($input['email_exam_access_mode'] ?? $examExisting['mode'] ?? 'admin'),
            ],
            'on_steps' => $onSteps,
        ]);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyProviderRequestConfig(array $config, array $input): array
    {
        // El panel legacy se unificó en Progreso (step_defs). Si vienen pipeline_steps,
        // no borramos provider_request: GroupStepConfig lo sincroniza y conserva workbook.
        if (isset($input['pipeline_steps']) && is_array($input['pipeline_steps'])) {
            // Permitir subir/limpiar workbook desde el formulario unificado si viene.
            if (!empty($input['provider_request_clear_workbook']) || !empty($input['provider_request_workbook_path'])
                || isset($input['provider_request_workbook_enabled'])) {
                // cae al flujo completo abajo solo para workbook
            } else {
                return $config;
            }
        }

        if (empty($input['provider_request_enabled']) && !(isset($input['pipeline_steps']) && is_array($input['pipeline_steps']))) {
            unset($config['provider_request']);

            return $config;
        }

        if (empty($input['provider_request_enabled']) && isset($input['pipeline_steps'])) {
            // Solo actualizar workbook sobre el existing
            $existing = is_array($config['provider_request'] ?? null) ? $config['provider_request'] : [];
            if ($existing === []) {
                return $config;
            }
            $wb = is_array($existing['workbook'] ?? null) ? $existing['workbook'] : [];
            if (!empty($input['provider_request_clear_workbook'])) {
                $wb['template_path'] = '';
                $wb['enabled'] = false;
            }
            if (!empty($input['provider_request_workbook_path'])) {
                $wb['template_path'] = trim((string) $input['provider_request_workbook_path']);
            }
            if (isset($input['provider_request_workbook_enabled'])) {
                $wb['enabled'] = !empty($input['provider_request_workbook_enabled']);
            }
            $existing['workbook'] = $wb;
            $config['provider_request'] = $existing;

            return $config;
        }

        // Neubox: siempre enlaces firmados (sin adjuntos SMTP).
        $delivery = 'links';

        $step = strtolower(trim((string) ($input['provider_request_step_code'] ?? 'solicitud_proveedor')));
        $step = preg_replace('/[^a-z0-9_]+/', '_', $step) ?? 'solicitud_proveedor';
        $step = trim($step, '_') ?: 'solicitud_proveedor';

        $cellMap = [];
        $cells = $input['provider_request_cells'] ?? [];
        $fields = $input['provider_request_fields'] ?? [];
        if (is_array($cells) && is_array($fields)) {
            foreach ($cells as $i => $cellRaw) {
                $cell = strtoupper(trim((string) $cellRaw));
                $field = trim((string) ($fields[$i] ?? ''));
                if ($cell === '' || $field === '') {
                    continue;
                }
                $cellMap[] = ['cell' => $cell, 'field' => $field];
            }
        }

        $existing = is_array($config['provider_request'] ?? null) ? $config['provider_request'] : [];
        $existingWb = is_array($existing['workbook'] ?? null) ? $existing['workbook'] : [];
        $templatePath = trim((string) ($existingWb['template_path'] ?? ''));
        if (!empty($input['provider_request_clear_workbook'])) {
            $templatePath = '';
        }
        if (!empty($input['provider_request_workbook_path'])) {
            $templatePath = trim((string) $input['provider_request_workbook_path']);
        }

        $normalize = (string) ($input['provider_request_workbook_normalize'] ?? 'none');
        if (!in_array($normalize, ['none', 'toefl'], true)) {
            $normalize = 'none';
        }

        $config['provider_request'] = [
            'enabled' => true,
            'auto_send_on_payment' => !empty($input['provider_request_auto_send']),
            'require_admin_payment_proof' => !empty($input['provider_request_require_admin_proof']),
            'auto_send_on_admin_proof' => !empty($input['provider_request_auto_send_admin_proof']),
            'step_code' => $step,
            'to' => trim((string) ($input['provider_request_to'] ?? '')),
            'cc' => trim((string) ($input['provider_request_cc'] ?? '')),
            'mail_template_code' => trim((string) ($input['provider_request_mail_template'] ?? '')),
            'include_student_data' => true,
            'include_exam_schedule' => true,
            'include_reglamento' => !empty($input['provider_request_include_reglamento']),
            'include_payment_proof' => !empty($input['provider_request_include_proof']),
            'require_reglamento' => !empty($input['provider_request_require_reglamento']),
            'delivery' => $delivery,
            'workbook' => [
                'enabled' => !empty($input['provider_request_workbook_enabled']),
                'template_path' => $templatePath,
                'attach' => false,
                'sheet' => trim((string) ($input['provider_request_workbook_sheet'] ?? '')),
                'normalize' => $normalize,
                'cell_map' => $cellMap,
            ],
        ];

        return $config;
    }

    /**
     * Guarda plantilla Excel de solicitud a proveedor y deja la ruta en el input.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function storeProviderWorkbookFromInput(array $input, ?int $groupId): array
    {
        $file = $input['_provider_workbook_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_NO_FILE) {
            return $input;
        }

        $stored = (new DocumentService())->storeUploaded(
            $file,
            'provider_templates',
            '.xlsx,.xls'
        );
        $input['provider_request_workbook_path'] = $stored['path'];
        $input['provider_request_workbook_enabled'] = '1';
        $input['provider_request_enabled'] = '1';

        return $input;
    }

    /**
     * Guarda archivos de instrucciones (legacy PDF + docs[]) y deja rutas en el input.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function storeInstructionPdfFromInput(array $input, int $groupId): array
    {
        $file = $input['_instruction_pdf_file'] ?? null;
        unset($input['_instruction_pdf_file']);
        if (is_array($file) && (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_NO_FILE) {
            $input['instruction_pdf_path'] = ExamInstructionAssets::storeFileUpload($groupId, $file);
        }

        $multi = $input['_instruction_doc_files'] ?? null;
        unset($input['_instruction_doc_files']);
        if (is_array($multi)) {
            $paths = is_array($input['instruction_doc_path'] ?? null) ? $input['instruction_doc_path'] : [];
            foreach ($multi as $i => $row) {
                if (!is_array($row) || (int) ($row['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $paths[(int) $i] = ExamInstructionAssets::storeFileUpload($groupId, $row);
            }
            $input['instruction_doc_path'] = $paths;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $prevInstructions
     * @return list<array{code:string,label:string,kind:string,path:?string,url:?string}>
     */
    private function parseInstructionDocumentsFromInput(array $input, array $prevInstructions): array
    {
        $prevByCode = [];
        foreach (ExamInstructionAssets::normalizeDocuments($prevInstructions) as $row) {
            $c = (string) ($row['code'] ?? '');
            if ($c !== '') {
                $prevByCode[$c] = $row;
            }
        }

        $codes = $input['instruction_doc_code'] ?? null;
        if (!is_array($codes)) {
            // Sin filas en el formulario: conservar documentos previos (p. ej. guardado parcial).
            return array_values($prevByCode);
        }

        $labels = is_array($input['instruction_doc_label'] ?? null) ? $input['instruction_doc_label'] : [];
        $kinds = is_array($input['instruction_doc_kind'] ?? null) ? $input['instruction_doc_kind'] : [];
        $urls = is_array($input['instruction_doc_url'] ?? null) ? $input['instruction_doc_url'] : [];
        $paths = is_array($input['instruction_doc_path'] ?? null) ? $input['instruction_doc_path'] : [];
        $clears = is_array($input['instruction_doc_clear'] ?? null) ? $input['instruction_doc_clear'] : [];

        $out = [];
        $seen = [];
        $n = count($codes);
        for ($i = 0; $i < $n; $i++) {
            $code = ExamInstructionAssets::sanitizeCode((string) ($codes[$i] ?? ''));
            $label = trim((string) ($labels[$i] ?? ''));
            $kind = strtolower(trim((string) ($kinds[$i] ?? 'file')));
            if (!in_array($kind, ['file', 'link', 'video'], true)) {
                $kind = 'file';
            }
            $url = trim((string) ($urls[$i] ?? ''));
            $path = trim((string) ($paths[$i] ?? ''));
            $clear = !empty($clears[$i]);
            if ($clear) {
                $path = '';
            }
            // Fila vacía del formulario (placeholder).
            if ($code === '' && $label === '' && $url === '' && $path === '' && !$clear) {
                $fileWasUploaded = is_array($input['instruction_doc_path'] ?? null)
                    && trim((string) ($paths[$i] ?? '')) !== '';
                if (!$fileWasUploaded) {
                    continue;
                }
            }
            if ($code === '') {
                $code = ExamInstructionAssets::sanitizeCode($label !== '' ? $label : ($kind === 'video' ? 'video' : 'doc'));
            }
            if ($code === '') {
                continue;
            }
            if (isset($seen[$code])) {
                $base = $code;
                $suffix = 2;
                while (isset($seen[$code])) {
                    $code = $base . '_' . $suffix;
                    $suffix++;
                }
            }
            $seen[$code] = true;
            if ($path === '' && $url === '' && !$clear && isset($prevByCode[$code])) {
                $path = trim((string) ($prevByCode[$code]['path'] ?? ''));
                if ($url === '') {
                    $url = trim((string) ($prevByCode[$code]['url'] ?? ''));
                }
                if ($label === '') {
                    $label = trim((string) ($prevByCode[$code]['label'] ?? ''));
                }
            }
            $normalized = ExamInstructionAssets::normalizeOneDocument([
                'code' => $code,
                'label' => $label,
                'kind' => $kind,
                'path' => $path,
                'url' => $url,
            ]);
            if ($normalized === null) {
                continue;
            }
            if ($normalized['kind'] === 'file' && $normalized['path'] === '' && $normalized['url'] === '') {
                continue;
            }
            if (($normalized['kind'] === 'link' || $normalized['kind'] === 'video') && $normalized['url'] === '') {
                continue;
            }
            $out[] = [
                'code' => $normalized['code'],
                'label' => $normalized['label'],
                'kind' => $normalized['kind'],
                'path' => $normalized['path'] !== '' ? $normalized['path'] : null,
                'url' => $normalized['url'] !== '' ? $normalized['url'] : null,
            ];
        }

        return $out;
    }

    /**
     * Guarda los pasos de la plantilla de progreso seleccionada (si vienen en el formulario).
     *
     * @param array<string, mixed> $input
     */
    public function savePipelineStepsFromInput(array $input): void
    {
        $pipelineCode = strtolower(trim((string) ($input['pipeline_code'] ?? '')));
        $pipelineCode = preg_replace('/[^a-z0-9_-]+/', '_', $pipelineCode) ?? '';
        $pipelineCode = trim($pipelineCode, '_');
        if ($pipelineCode === '' || !isset($input['pipeline_steps']) || !is_array($input['pipeline_steps'])) {
            return;
        }

        $repo = new \App\Repositories\PipelineRepository();
        $tpl = $repo->findByCode($pipelineCode);
        if ($tpl === null) {
            throw new \InvalidArgumentException('La plantilla de progreso "' . $pipelineCode . '" no existe.');
        }

        $rawSteps = $input['pipeline_steps'];
        $steps = [];
        $used = [];
        foreach ($rawSteps as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = GroupStepConfig::normalizeCode((string) ($row['code'] ?? ''));
            if ($code === '') {
                $code = GroupStepConfig::normalizeCode((string) ($row['label'] ?? ''));
            }
            if ($code === '') {
                continue;
            }
            $base = $code;
            $n = 2;
            while (isset($used[$code])) {
                $code = $base . '_' . $n;
                $n++;
            }
            $used[$code] = true;
            $steps[] = [
                'code' => $code,
                'label' => (string) ($row['label'] ?? $code),
                'actor' => (string) ($row['actor'] ?? 'admin'),
                'is_terminal' => false,
            ];
        }
        if ($steps !== []) {
            $steps[count($steps) - 1]['is_terminal'] = true;
        }
        $repo->replaceSteps((int) $tpl['id'], $steps);
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

    /**
     * Normaliza hora HH:MM. Acepta 24:00 como fin del día (medianoche siguiente).
     */
    private function normalizeClock(string $clock, string $fallback, bool $allowEndOfDay = false): string
    {
        $clock = trim($clock);
        if (!preg_match('/^\d{1,2}:\d{2}$/', $clock)) {
            return $fallback;
        }
        [$h, $m] = array_map('intval', explode(':', $clock));
        if ($allowEndOfDay && $h === 24 && $m === 0) {
            return '24:00';
        }
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

    /**
     * Texto admin: una línea por slot `dow|HH:MM|etiqueta`
     * dow: 0=Dom … 6=Sáb. Ejemplo TOEFL: `6|11:00|Sábado 11:00`
     *
     * @param mixed $raw
     */
    public static function fixedSlotsToText(mixed $raw): string
    {
        if (!is_array($raw)) {
            return '';
        }
        $lines = [];
        foreach ($raw as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $dow = (int) ($slot['dow'] ?? -1);
            $time = ExamScheduleService::normalizeClock((string) ($slot['time'] ?? ''));
            if ($dow < 0 || $dow > 6 || $time === null) {
                continue;
            }
            $label = trim((string) ($slot['label'] ?? ''));
            $lines[] = $dow . '|' . $time . ($label !== '' ? '|' . $label : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{dow:int,time:string,label:string}>
     */
    public static function parseFixedSlotsText(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $dow = (int) ($parts[0] ?? -1);
            $time = ExamScheduleService::normalizeClock((string) ($parts[1] ?? ''));
            if ($dow < 0 || $dow > 6 || $time === null) {
                continue;
            }
            $out[] = [
                'dow' => $dow,
                'time' => $time,
                'label' => (string) ($parts[2] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Texto admin: `YYYY-MM-DD|HH:MM|YYYY-MM-DD deadline|etiqueta`
     *
     * @param mixed $raw
     */
    public static function sessionsToText(mixed $raw): string
    {
        if (!is_array($raw)) {
            return '';
        }
        $lines = [];
        foreach ($raw as $session) {
            if (!is_array($session)) {
                continue;
            }
            $date = ExamScheduleService::normalizeDateStatic((string) ($session['exam_date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $time = ExamScheduleService::normalizeClock((string) ($session['exam_time'] ?? '00:00')) ?? '00:00';
            $deadline = ExamScheduleService::normalizeDateStatic((string) ($session['registration_deadline'] ?? '')) ?? '';
            $label = trim((string) ($session['label'] ?? ''));
            $lines[] = $date . '|' . $time . '|' . $deadline . ($label !== '' ? '|' . $label : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{id:string,exam_date:string,exam_time:string,registration_deadline:?string,label:string}>
     */
    public static function parseSessionsText(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $i => $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $date = ExamScheduleService::normalizeDateStatic((string) ($parts[0] ?? ''));
            if ($date === null) {
                continue;
            }
            $time = ExamScheduleService::normalizeClock((string) ($parts[1] ?? '00:00')) ?? '00:00';
            $deadline = ExamScheduleService::normalizeDateStatic((string) ($parts[2] ?? ''));
            $label = (string) ($parts[3] ?? '');
            $out[] = [
                'id' => $date . '_' . str_replace(':', '', $time) . '_' . $i,
                'exam_date' => $date,
                'exam_time' => $time,
                'registration_deadline' => $deadline,
                'label' => $label,
            ];
        }

        return $out;
    }
}
