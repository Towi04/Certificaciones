<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\ExportTemplateRepository;
use App\Repositories\ProductGroupRepository;
use App\Support\Settings;
use PDO;

/**
 * Catálogo de plantillas Excel (.xlsx) para correo proveedor ({{workbook_url}}).
 * Vive en export_templates con file_type=xlsx. Migración no destructiva desde
 * Settings mail_tpl_*_workbook y provider_request.workbook de grupos.
 */
final class ProviderWorkbookCatalogService
{
    private ExportTemplateRepository $templates;
    private PDO $pdo;

    public function __construct(?ExportTemplateRepository $templates = null)
    {
        $this->templates = $templates ?? new ExportTemplateRepository();
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->templates->listByFileType('xlsx', false);
    }

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        return $this->templates->listByFileType('xlsx', true);
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        $row = $this->templates->findByCode($code);
        if ($row === null) {
            return null;
        }
        if (strtolower((string) ($row['file_type'] ?? '')) !== 'xlsx') {
            return null;
        }

        return $row;
    }

    /**
     * Forma compatible con MailTemplateService::workbookConfig / ProviderRequestService.
     *
     * @return array{enabled:bool,template_path:string,sheet:string,normalize:string,cell_map:list<array<string,string>>,workbook_template_code:string}|null
     */
    public function workbookConfig(string $code): ?array
    {
        $row = $this->find($code);
        if ($row === null || empty($row['is_active'])) {
            return null;
        }
        $map = $this->mapping($row);
        $path = trim((string) ($row['storage_path'] ?? ''));
        $cellMap = $this->normalizeCellMap($map['cell_map'] ?? []);

        return [
            'enabled' => $path !== '' && $cellMap !== [],
            'template_path' => $path,
            'sheet' => trim((string) ($map['sheet'] ?? '')),
            'normalize' => in_array((string) ($map['normalize'] ?? 'none'), ['none', 'toefl'], true)
                ? (string) ($map['normalize'] ?? 'none')
                : 'none',
            'cell_map' => $cellMap,
            'workbook_template_code' => (string) ($row['code'] ?? $code),
        ];
    }

    /**
     * Resuelve workbook efectivo: catálogo → legacy mail → legacy grupo.
     *
     * @param array<string, mixed>|null $groupWorkbook provider_request.workbook
     * @return array{enabled:bool,template_path:string,sheet:string,normalize:string,cell_map:list<array<string,string>>,workbook_template_code:string,source:string}
     */
    public function resolve(?array $groupWorkbook, string $mailCode = ''): array
    {
        $empty = [
            'enabled' => false,
            'template_path' => '',
            'sheet' => '',
            'normalize' => 'none',
            'cell_map' => [],
            'workbook_template_code' => '',
            'source' => 'none',
        ];

        $groupWorkbook = is_array($groupWorkbook) ? $groupWorkbook : [];
        $groupCode = trim((string) ($groupWorkbook['workbook_template_code'] ?? ''));
        if ($groupCode !== '') {
            $fromCatalog = $this->workbookConfig($groupCode);
            if ($fromCatalog !== null && !empty($fromCatalog['enabled'])) {
                return $fromCatalog + ['source' => 'catalog_group'];
            }
        }

        if ($mailCode !== '') {
            $mailRaw = MailTemplateService::workbookConfig($mailCode);
            $mailCatalogCode = trim((string) ($mailRaw['workbook_template_code'] ?? ''));
            if ($mailCatalogCode !== '') {
                $fromCatalog = $this->workbookConfig($mailCatalogCode);
                if ($fromCatalog !== null && !empty($fromCatalog['enabled'])) {
                    return $fromCatalog + ['source' => 'catalog_mail'];
                }
            }
            if (!empty($mailRaw['enabled']) && trim((string) ($mailRaw['template_path'] ?? '')) !== '') {
                return [
                    'enabled' => true,
                    'template_path' => trim((string) $mailRaw['template_path']),
                    'sheet' => trim((string) ($mailRaw['sheet'] ?? '')),
                    'normalize' => (string) ($mailRaw['normalize'] ?? 'none'),
                    'cell_map' => $this->normalizeCellMap($mailRaw['cell_map'] ?? []),
                    'workbook_template_code' => $mailCatalogCode,
                    'source' => 'legacy_mail',
                ];
            }
        }

        if (!empty($groupWorkbook['enabled']) && trim((string) ($groupWorkbook['template_path'] ?? '')) !== '') {
            return [
                'enabled' => true,
                'template_path' => trim((string) $groupWorkbook['template_path']),
                'sheet' => trim((string) ($groupWorkbook['sheet'] ?? '')),
                'normalize' => in_array((string) ($groupWorkbook['normalize'] ?? 'none'), ['none', 'toefl'], true)
                    ? (string) ($groupWorkbook['normalize'] ?? 'none')
                    : 'none',
                'cell_map' => $this->normalizeCellMap($groupWorkbook['cell_map'] ?? []),
                'workbook_template_code' => $groupCode,
                'source' => 'legacy_group',
            ];
        }

        return $empty;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $upload $_FILES entry
     */
    public function createFromAdmin(array $input, ?array $upload = null): string
    {
        $parsed = $this->parseAdminInput($input, null, $upload);
        if ($this->templates->findByCode($parsed['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe una plantilla con el código ' . $parsed['code']);
        }
        $this->templates->upsert($parsed['code'], $parsed['data']);

        return $parsed['code'];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $upload
     */
    public function updateFromAdmin(string $code, array $input, ?array $upload = null): void
    {
        if ($this->find($code) === null) {
            throw new \InvalidArgumentException('Plantilla Excel no encontrada.');
        }
        $parsed = $this->parseAdminInput($input, $code, $upload);
        $this->templates->upsert($code, $parsed['data']);
    }

    public function delete(string $code): void
    {
        if ($this->find($code) === null) {
            throw new \InvalidArgumentException('Plantilla Excel no encontrada.');
        }
        // No borra el archivo en storage (ops puede reutilizarlo / legacy).
        $this->templates->deleteByCode($code);
    }

    /**
     * Inventaria e importa workbooks legacy → catálogo. Idempotente; no DELETE de Settings.
     *
     * @return list<string>
     */
    public function migrateFromLegacy(): array
    {
        $log = [];
        $log = array_merge($log, $this->migrateMailWorkbooks());
        $log = array_merge($log, $this->migrateGroupWorkbooks());
        if ($log === []) {
            $log[] = 'Workbook migración: nada que importar.';
        }

        return $log;
    }

    /** @param array<string, mixed> $template */
    public function mapping(array $template): array
    {
        $raw = $template['mapping_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $upload
     * @return array{code:string,data:array<string,mixed>}
     */
    private function parseAdminInput(array $input, ?string $existingCode, ?array $upload): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? $existingCode ?? '')));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?? '';
        $code = trim($code, '_');
        if ($code === '') {
            throw new \InvalidArgumentException('El código de la plantilla es obligatorio.');
        }
        if ($existingCode !== null && $existingCode !== '' && $code !== $existingCode) {
            throw new \InvalidArgumentException('El código de la plantilla no se puede cambiar.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre de la plantilla es obligatorio.');
        }

        $existing = $existingCode !== null ? $this->templates->findByCode($existingCode) : null;
        $path = trim((string) ($existing['storage_path'] ?? ($input['template_path'] ?? '')));
        if (!empty($input['clear_file'])) {
            $path = '';
        }
        if (is_array($upload) && (int) ($upload['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_OK) {
            $stored = (new DocumentService())->storeUploaded($upload, 'provider_workbook_templates', '.xlsx');
            $path = $stored['path'];
        }

        $cellMap = $this->cellMapFromAdminInput($input);
        if ($path === '') {
            throw new \InvalidArgumentException('Sube un archivo .xlsx (o conserva el actual).');
        }
        if ($cellMap === []) {
            throw new \InvalidArgumentException('Define al menos una celda con campo o fórmula.');
        }

        $normalize = (string) ($input['normalize'] ?? 'none');
        if (!in_array($normalize, ['none', 'toefl'], true)) {
            $normalize = 'none';
        }

        $mapping = [
            'kind' => 'xlsx',
            'sheet' => trim((string) ($input['sheet'] ?? '')),
            'normalize' => $normalize,
            'cell_map' => $cellMap,
        ];
        if ($existing !== null) {
            $prev = $this->mapping($existing);
            if (!empty($prev['source']) && is_array($prev['source'])) {
                $mapping['source'] = $prev['source'];
            }
        }

        return [
            'code' => $code,
            'data' => [
                'name' => function_exists('mb_substr') ? \mb_substr($name, 0, 190) : substr($name, 0, 190),
                'supplier_id' => !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null,
                'file_type' => 'xlsx',
                'storage_path' => $path,
                'delivery' => 'email_attach',
                'batch_by' => 'none',
                'mapping_json' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
                'is_active' => (!empty($input['is_active']) || !empty($input['active'])) ? 1 : 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, string>>
     */
    private function cellMapFromAdminInput(array $input): array
    {
        $cells = is_array($input['workbook_cells'] ?? null) ? $input['workbook_cells'] : [];
        $fields = is_array($input['workbook_fields'] ?? null) ? $input['workbook_fields'] : [];
        $formulas = is_array($input['workbook_formulas'] ?? null) ? $input['workbook_formulas'] : [];
        $out = [];
        foreach ($cells as $i => $cellRaw) {
            $cell = strtoupper(trim((string) $cellRaw));
            $field = trim((string) ($fields[$i] ?? ''));
            $formula = trim((string) ($formulas[$i] ?? ''));
            if ($cell === '' || ($field === '' && $formula === '')) {
                continue;
            }
            $row = ['cell' => $cell, 'field' => $field];
            if ($formula !== '') {
                $row['formula'] = function_exists('mb_substr') ? \mb_substr($formula, 0, 500) : substr($formula, 0, 500);
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<string> */
    private function migrateMailWorkbooks(): array
    {
        $log = [];
        try {
            $stmt = $this->pdo->query(
                "SELECT setting_key, setting_value FROM settings
                 WHERE setting_key LIKE 'mail_tpl_%_workbook'"
            );
            $rows = $stmt ? $stmt->fetchAll() : [];
        } catch (\Throwable $e) {
            return ['Workbook mail: no se pudo leer settings (' . $e->getMessage() . ').'];
        }

        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (!preg_match('/^mail_tpl_(.+)_workbook$/', $key, $m)) {
                continue;
            }
            $mailCode = $m[1];
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $path = trim((string) ($decoded['template_path'] ?? ''));
            $cellMap = $this->normalizeCellMap($decoded['cell_map'] ?? []);
            $enabled = !empty($decoded['enabled']);
            if (!$enabled && $path === '' && $cellMap === []) {
                continue;
            }
            if ($path === '' && $cellMap === []) {
                continue;
            }

            $catalogCode = $this->stableMailCatalogCode($mailCode);
            $existingPtr = trim((string) ($decoded['workbook_template_code'] ?? ''));
            if ($existingPtr !== '' && $this->find($existingPtr) !== null) {
                $log[] = "Workbook mail «{$mailCode}»: ya apunta a catálogo «{$existingPtr}» — skip.";
                continue;
            }

            if ($this->templates->findByCode($catalogCode) !== null) {
                // Solo escribir puntero; no pisar catálogo.
                $decoded['workbook_template_code'] = $catalogCode;
                Settings::set($key, json_encode($decoded, JSON_UNESCAPED_UNICODE));
                $log[] = "Workbook mail «{$mailCode}»: catálogo «{$catalogCode}» ya existía — puntero escrito, sin overwrite.";
                continue;
            }

            $mailTpl = (new MailTemplateService())->find($mailCode);
            $name = 'Excel · ' . (is_array($mailTpl) ? (string) ($mailTpl['name'] ?? $mailCode) : $mailCode);
            $supplierId = null;
            if (stripos($mailCode, 'toefl') !== false || stripos($name, 'toefl') !== false) {
                $supplierId = $this->supplierIdByCode('linguafranca');
            }

            $mapping = [
                'kind' => 'xlsx',
                'sheet' => trim((string) ($decoded['sheet'] ?? '')),
                'normalize' => in_array((string) ($decoded['normalize'] ?? 'none'), ['none', 'toefl'], true)
                    ? (string) ($decoded['normalize'] ?? 'none')
                    : 'none',
                'cell_map' => $cellMap,
                'source' => ['type' => 'mail', 'code' => $mailCode],
            ];
            $this->templates->upsert($catalogCode, [
                'name' => function_exists('mb_substr') ? \mb_substr($name, 0, 190) : substr($name, 0, 190),
                'supplier_id' => $supplierId,
                'file_type' => 'xlsx',
                'storage_path' => $path,
                'delivery' => 'email_attach',
                'batch_by' => 'none',
                'mapping_json' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
                'is_active' => ($enabled && $path !== '' && $cellMap !== []) ? 1 : 0,
            ]);
            $decoded['workbook_template_code'] = $catalogCode;
            Settings::set($key, json_encode($decoded, JSON_UNESCAPED_UNICODE));
            $log[] = "Workbook mail «{$mailCode}»: importado a catálogo «{$catalogCode}» (Settings conservado).";
        }

        return $log;
    }

    /** @return list<string> */
    private function migrateGroupWorkbooks(): array
    {
        $log = [];
        $groups = (new ProductGroupRepository())->all();
        foreach ($groups as $group) {
            $groupCode = trim((string) ($group['code'] ?? ''));
            if ($groupCode === '') {
                continue;
            }
            $cfg = [];
            $raw = $group['config_json'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $cfg = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $cfg = $raw;
            }
            $pr = is_array($cfg['provider_request'] ?? null) ? $cfg['provider_request'] : [];
            $wb = is_array($pr['workbook'] ?? null) ? $pr['workbook'] : [];
            if ($wb === []) {
                continue;
            }
            $path = trim((string) ($wb['template_path'] ?? ''));
            $cellMap = $this->normalizeCellMap($wb['cell_map'] ?? []);
            $enabled = !empty($wb['enabled']);
            // Seed TOEFL a menudo tiene cell_map sin archivo aún: igual importar el mapa.
            if (!$enabled && $path === '' && $cellMap === []) {
                continue;
            }
            if ($cellMap === [] && $path === '') {
                continue;
            }

            $existingPtr = trim((string) ($wb['workbook_template_code'] ?? ''));
            if ($existingPtr !== '' && $this->find($existingPtr) !== null) {
                $log[] = "Workbook grupo «{$groupCode}»: ya apunta a «{$existingPtr}» — skip.";
                continue;
            }

            $catalogCode = $this->stableGroupCatalogCode($groupCode);
            if ($this->templates->findByCode($catalogCode) !== null) {
                $wb['workbook_template_code'] = $catalogCode;
                $pr['workbook'] = $wb;
                $cfg['provider_request'] = $pr;
                (new ProductGroupRepository())->update((int) $group['id'], [
                    'name' => (string) ($group['name'] ?? $groupCode),
                    'supplier_id' => isset($group['supplier_id']) ? (int) $group['supplier_id'] : null,
                    'config_json' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
                ]);
                $log[] = "Workbook grupo «{$groupCode}»: catálogo «{$catalogCode}» ya existía — puntero escrito, sin overwrite.";
                continue;
            }

            $name = 'Excel · grupo ' . (string) ($group['name'] ?? $groupCode);
            $mapping = [
                'kind' => 'xlsx',
                'sheet' => trim((string) ($wb['sheet'] ?? '')),
                'normalize' => in_array((string) ($wb['normalize'] ?? 'none'), ['none', 'toefl'], true)
                    ? (string) ($wb['normalize'] ?? 'none')
                    : 'none',
                'cell_map' => $cellMap,
                'source' => ['type' => 'group', 'code' => $groupCode],
            ];
            $this->templates->upsert($catalogCode, [
                'name' => function_exists('mb_substr') ? \mb_substr($name, 0, 190) : substr($name, 0, 190),
                'supplier_id' => !empty($group['supplier_id']) ? (int) $group['supplier_id'] : null,
                'file_type' => 'xlsx',
                'storage_path' => $path,
                'delivery' => 'email_attach',
                'batch_by' => 'none',
                'mapping_json' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
                'is_active' => ($enabled && $cellMap !== []) ? 1 : 0,
            ]);
            $wb['workbook_template_code'] = $catalogCode;
            $pr['workbook'] = $wb;
            $cfg['provider_request'] = $pr;
            (new ProductGroupRepository())->update((int) $group['id'], [
                'name' => (string) ($group['name'] ?? $groupCode),
                'supplier_id' => isset($group['supplier_id']) ? (int) $group['supplier_id'] : null,
                'config_json' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            ]);
            $log[] = "Workbook grupo «{$groupCode}»: importado a catálogo «{$catalogCode}» (config_json conservado).";
        }

        return $log;
    }

    private function stableMailCatalogCode(string $mailCode): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9_-]+/', '_', $mailCode) ?? '');
        $slug = trim($slug, '_') ?: 'mail';

        return 'xlsx_mail_' . $slug;
    }

    private function stableGroupCatalogCode(string $groupCode): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9_-]+/', '_', $groupCode) ?? '');
        $slug = trim($slug, '_') ?: 'group';

        return 'xlsx_group_' . $slug;
    }

    private function supplierIdByCode(string $code): ?int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT id FROM suppliers WHERE code = ? LIMIT 1');
            $stmt->execute([$code]);
            $id = $stmt->fetchColumn();

            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $raw
     * @return list<array<string, string>>
     */
    private function normalizeCellMap(mixed $raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cell = strtoupper(trim((string) ($item['cell'] ?? '')));
            $field = trim((string) ($item['field'] ?? ''));
            $formula = trim((string) ($item['formula'] ?? ''));
            if ($cell === '' || ($field === '' && $formula === '')) {
                continue;
            }
            $row = ['cell' => $cell, 'field' => $field];
            if ($formula !== '') {
                $row['formula'] = $formula;
            }
            $out[] = $row;
        }

        return $out;
    }
}
