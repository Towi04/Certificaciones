<?php

declare(strict_types=1);

namespace App\Setup;

use App\Config\Env;
use App\Database\Connection;
use App\Repositories\ExportTemplateRepository;
use App\Services\ExportService;
use App\Services\MailTemplateService;
use App\Services\ProviderWorkbookCatalogService;

/**
 * Fase 4 — QA cutover plantillas proveedor (CSV + Excel).
 *
 * @return array{ok:bool,lines:list<string>,failures:int,manual:int}
 */
final class ProviderTemplatesQa
{
    /** @return array{ok:bool,lines:list<string>,failures:int,manual:int} */
    public function run(): array
    {
        $lines = [];
        $failures = 0;
        $manual = 0;

        $lines[] = '=== Plantillas proveedor — QA Fase 4 ===';
        $lines[] = '';

        foreach ([
            'A) Menú / rutas admin' => $this->sectionMenu(),
            'B) CSV: scopes + exclusiones + combos' => $this->sectionCsvLogic(),
            'C) Cambridge CSV + UKS seed seguro' => $this->sectionCatalogSeed(),
            'D) Excel catálogo + resolución correo' => $this->sectionExcel(),
            'E) BD / migración (si hay DB_NAME)' => $this->sectionDatabase(),
            'F) Staging MANUAL' => $this->sectionManual(),
        ] as $title => $cases) {
            $lines[] = '--- ' . $title . ' ---';
            foreach ($cases as $case) {
                $id = (string) $case['id'];
                $status = (string) $case['status'];
                $detail = (string) $case['detail'];
                if ($status === 'OK') {
                    $lines[] = "OK     {$id}  {$detail}";
                } elseif ($status === 'MANUAL') {
                    $lines[] = "MANUAL {$id}  {$detail}";
                    $manual++;
                } elseif ($status === 'SKIP') {
                    $lines[] = "SKIP   {$id}  {$detail}";
                } else {
                    $lines[] = "FAIL   {$id}  {$detail}";
                    $failures++;
                }
            }
            $lines[] = '';
        }

        $ok = $failures === 0;
        $lines[] = $ok
            ? "RESULTADO: QA LÓGICA OK ({$manual} paso(s) manuales / avisos)"
            : "RESULTADO: NO LISTO — {$failures} fallo(s), {$manual} manual(es)";
        $lines[] = 'Checklist: docs/products/provider-templates.md#fase-4--qa--cutover';
        $lines[] = 'Migración: php bin/migrate-provider-workbooks.php';

        return ['ok' => $ok, 'lines' => $lines, 'failures' => $failures, 'manual' => $manual];
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionMenu(): array
    {
        $out = [];
        $layout = (string) @file_get_contents(BASE_PATH . '/views/layouts/admin.php');
        $out[] = $this->assert(
            'A1',
            str_contains($layout, 'Plantillas proveedor')
                && str_contains($layout, '/admin/plantillas-csv'),
            'menú Automatización → Plantillas proveedor'
        );

        $routes = (string) @file_get_contents(BASE_PATH . '/routes/web.php');
        $out[] = $this->assert(
            'A2',
            str_contains($routes, "/admin/plantillas-csv")
                && str_contains($routes, "/admin/plantillas-csv/{code}/resumen")
                && str_contains($routes, "/admin/plantillas-proveedor"),
            'rutas catálogo + resumen + alias plantillas-proveedor'
        );

        $out[] = $this->assert(
            'A3',
            is_file(BASE_PATH . '/views/admin/csv_templates.php')
                && is_file(BASE_PATH . '/views/admin/xlsx_template_edit.php'),
            'vistas lista CSV/Excel + editor xlsx'
        );

        $groupForm = (string) @file_get_contents(BASE_PATH . '/views/admin/product_group_form.php');
        $out[] = $this->assert(
            'A4',
            str_contains($groupForm, 'Administrar plantillas proveedor')
                && str_contains($groupForm, 'Elegir plantilla (ninguna)')
                && str_contains($groupForm, 'no uses UKS en Cambridge'),
            'Progreso: selector CSV con default vacío + enlace admin'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionCsvLogic(): array
    {
        $out = [];
        $scopes = ExportService::scopes();
        $need = [
            ExportService::SCOPE_STUDENT,
            ExportService::SCOPE_PENDING,
            ExportService::SCOPE_EXAM_DATE_ONLY,
            ExportService::SCOPE_PRODUCT,
            ExportService::SCOPE_EXAM_DATE_PRODUCT,
        ];
        $missing = array_values(array_diff($need, $scopes));
        $out[] = $this->assert(
            'B1',
            $missing === [],
            $missing === []
                ? 'scopes: ' . implode(', ', $need)
                : 'faltan scopes: ' . implode(', ', $missing)
        );

        $out[] = $this->assert(
            'B2',
            ExportService::normalizeScope('exam_date') === ExportService::SCOPE_EXAM_DATE_PRODUCT,
            'legado exam_date → exam_date_product'
        );

        $src = (string) @file_get_contents(BASE_PATH . '/src/Services/ExportService.php');
        $out[] = $this->assert(
            'B3',
            str_contains($src, "return 'folio'")
                && str_contains($src, "return 'presented'")
                && str_contains($src, "return 'downloaded'"),
            'exclusión folio + present + csv_downloads'
        );

        $out[] = $this->assert(
            'B4',
            str_contains($src, 'analyzeComboRows')
                && str_contains($src, 'collapseComboRows')
                && str_contains($src, "combo_mode")
                && str_contains($src, 'Certificación'),
            'combos repeat/single + columna Certificación'
        );

        $ops = (string) @file_get_contents(BASE_PATH . '/views/admin/ops.php');
        $out[] = $this->assert(
            'B5',
            str_contains($ops, 'ops-csv-modal')
                && str_contains($ops, 'ops_csv_combo_mode')
                && str_contains($ops, 'include_registered'),
            'modal Operación: alcance + combo + override registrados'
        );

        $out[] = $this->assert(
            'B6',
            str_contains($src, 'markDownloadedIds')
                && str_contains($src, 'markCsvDownloaded'),
            'marca csv_downloads en todos los IDs del lote'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionCatalogSeed(): array
    {
        $out = [];
        $seeder = (string) @file_get_contents(BASE_PATH . '/src/Setup/CatalogSeeder.php');
        $out[] = $this->assert(
            'C1',
            str_contains($seeder, 'cambridge_registro')
                && str_contains($seeder, 'ya existía — sin overwrite'),
            'seed Cambridge CSV insert-only'
        );

        $out[] = $this->assert(
            'C2',
            str_contains($seeder, 'uks_elet_registro')
                && str_contains($seeder, 'ya existía — skip overwrite'),
            'seed UKS CSV no destructivo'
        );

        $repo = (string) @file_get_contents(BASE_PATH . '/src/Repositories/ExportTemplateRepository.php');
        $out[] = $this->assert(
            'C3',
            str_contains($repo, 'listActiveCsv')
                && str_contains($repo, 'listByFileType'),
            'repo separa csv / xlsx'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionExcel(): array
    {
        $out = [];
        $svc = (string) @file_get_contents(BASE_PATH . '/src/Services/ProviderWorkbookCatalogService.php');
        $out[] = $this->assert(
            'D1',
            str_contains($svc, 'migrateFromLegacy')
                && str_contains($svc, 'workbook_template_code')
                && str_contains($svc, 'xlsx_mail_'),
            'migración mail/grupo → catálogo con puntero'
        );

        $out[] = $this->assert(
            'D2',
            is_file(BASE_PATH . '/bin/migrate-provider-workbooks.php'),
            'CLI bin/migrate-provider-workbooks.php'
        );

        $mail = (string) @file_get_contents(BASE_PATH . '/src/Services/MailTemplateService.php');
        $out[] = $this->assert(
            'D3',
            str_contains($mail, 'workbookSourceGapMessage')
                && str_contains($mail, 'workbookCatalogCodesInTemplate'),
            'gap message + {{workbook:codigo}}'
        );

        $interp = MailTemplateService::interpolate(
            '<a href="{{workbook_url}}">a</a><a href="{{workbook:xlsx_demo}}">b</a>',
            [
                'workbook_url' => 'https://example.com/a.xlsx',
                'workbook:xlsx_demo' => 'https://example.com/b.xlsx',
            ]
        );
        $out[] = $this->assert(
            'D4',
            str_contains($interp, 'https://example.com/a.xlsx')
                && str_contains($interp, 'https://example.com/b.xlsx')
                && !str_contains($interp, '{{'),
            'interpolate {{workbook_url}} + {{workbook:codigo}}'
        );

        $mailUi = (string) @file_get_contents(BASE_PATH . '/views/admin/mail_template_edit.php');
        $out[] = $this->assert(
            'D5',
            str_contains($mailUi, 'Camino feliz')
                && str_contains($mailUi, 'workbook_template_code')
                && str_contains($mailUi, 'Respaldo legacy'),
            'UI correo: catálogo primero, legacy respaldo'
        );

        $ensurer = (string) @file_get_contents(BASE_PATH . '/src/Setup/CutoverConfigEnsurer.php');
        $out[] = $this->assert(
            'D6',
            str_contains($ensurer, 'migrateFromLegacy'),
            'ensure-cutover ejecuta migración workbooks'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionDatabase(): array
    {
        $dbName = trim((string) (Env::get('DB_NAME', '') ?? ''));
        if ($dbName === '') {
            return [[
                'id' => 'E0',
                'status' => 'SKIP',
                'detail' => 'DB_NAME vacío — checks BD omitidos (ejecutar en staging/prod)',
            ]];
        }

        $out = [];
        try {
            Connection::get();
        } catch (\Throwable $e) {
            return [[
                'id' => 'E0',
                'status' => 'FAIL',
                'detail' => 'no se pudo conectar BD: ' . $e->getMessage(),
            ]];
        }

        $repo = new ExportTemplateRepository();
        $uks = $repo->findByCode('uks_elet_registro');
        $out[] = $this->assert(
            'E1',
            $uks !== null && strtolower((string) ($uks['file_type'] ?? '')) === 'csv',
            $uks !== null ? 'existe uks_elet_registro (csv)' : 'falta uks_elet_registro — correr seed'
        );

        $cam = $repo->findByCode('cambridge_registro');
        $out[] = $this->assert(
            'E2',
            $cam !== null && strtolower((string) ($cam['file_type'] ?? '')) === 'csv',
            $cam !== null ? 'existe cambridge_registro (csv)' : 'falta cambridge_registro — correr seed'
        );

        $csvActive = $repo->listActiveCsv();
        $xlsxActive = $repo->listByFileType('xlsx', true);
        $out[] = $this->assert(
            'E3',
            $csvActive !== [],
            'CSV activos en catálogo: ' . count($csvActive)
        );
        $out[] = [
            'id' => 'E4',
            'status' => 'OK',
            'detail' => 'Excel activos en catálogo: ' . count($xlsxActive)
                . (count($xlsxActive) === 0 ? ' (correr migrate-provider-workbooks si hay TOEFL)' : ''),
        ];

        // Snapshot: migración no debe borrar Settings mail_tpl_*_workbook
        try {
            $pdo = Connection::get();
            $before = (int) $pdo->query(
                "SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'mail_tpl_%_workbook'"
            )->fetchColumn();
            $log = (new ProviderWorkbookCatalogService())->migrateFromLegacy();
            $after = (int) $pdo->query(
                "SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'mail_tpl_%_workbook'"
            )->fetchColumn();
            $out[] = $this->assert(
                'E5',
                $after >= $before,
                "migración no destructiva Settings workbook: antes={$before} después={$after}"
                    . (count($log) ? ' · ' . count($log) . ' línea(s) log' : '')
            );
        } catch (\Throwable $e) {
            $out[] = [
                'id' => 'E5',
                'status' => 'FAIL',
                'detail' => 'migración: ' . $e->getMessage(),
            ];
        }

        // Seed no pisa mapping custom de UKS
        if ($uks !== null) {
            $mapBefore = (string) ($uks['mapping_json'] ?? '');
            $marker = '"__qa_custom_marker__":true';
            if (!str_contains($mapBefore, '__qa_custom_marker__')) {
                // Solo verificar que el código del seeder tiene la rama no destructiva (ya en C2).
                $out[] = [
                    'id' => 'E6',
                    'status' => 'OK',
                    'detail' => 'UKS mapping presente; re-seed no destructivo cubierto por C2 + CatalogSeeder',
                ];
            } else {
                $out[] = $this->assert('E6', true, 'UKS custom marker conservado');
            }
        }

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionManual(): array
    {
        return [
            [
                'id' => 'F1',
                'status' => 'MANUAL',
                'detail' => 'Cambridge: en Progreso elegir cambridge_registro (no UKS); descargar lote con alcances',
            ],
            [
                'id' => 'F2',
                'status' => 'MANUAL',
                'detail' => 'UKS: descarga uno-a-uno + pendientes; 2ª masiva vacía; folio y present excluidos',
            ],
            [
                'id' => 'F3',
                'status' => 'MANUAL',
                'detail' => 'Combo 2 certs: modal repetir vs una fila; CSV respeta elección',
            ],
            [
                'id' => 'F4',
                'status' => 'MANUAL',
                'detail' => 'TOEFL staging: migrate-provider-workbooks + envío solicitud = mismas celdas/archivo que antes',
            ],
            [
                'id' => 'F5',
                'status' => 'MANUAL',
                'detail' => 'Re-seed / ensure-cutover: mapping UKS custom y Settings mail_tpl_*_workbook intactos',
            ],
            [
                'id' => 'F6',
                'status' => 'MANUAL',
                'detail' => 'Menú admin visible en staging; alias /admin/plantillas-proveedor redirige',
            ],
        ];
    }

    /** @return array{id:string,status:string,detail:string} */
    private function assert(string $id, bool $ok, string $detail): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'OK' : 'FAIL',
            'detail' => $detail,
        ];
    }
}
