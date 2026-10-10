<?php

declare(strict_types=1);

namespace App\Setup;

use App\Services\CheckoutRequirements;

/**
 * Fase 4 — QA reglamento Cambridge (`fill_acroform`) + regresión UKS.
 *
 * @return array{ok:bool,lines:list<string>,failures:int,manual:int}
 */
final class CambridgeReglamentoQa
{
    /** @return array{ok:bool,lines:list<string>,failures:int,manual:int} */
    public function run(): array
    {
        $lines = [];
        $failures = 0;
        $manual = 0;

        $lines[] = '=== Reglamento Cambridge — QA Fase 4 ===';
        $lines[] = '';

        foreach ([
            'A) Plantilla PDF AcroForm' => $this->sectionPdf(),
            'B) CheckoutRequirements (modos)' => $this->sectionRequirements(),
            'C) UI checkout + admin' => $this->sectionUi(),
            'D) Seed / ensurer / doc_code' => $this->sectionSeed(),
            'E) Correo proveedor (placeholders)' => $this->sectionMail(),
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
        $lines[] = 'Checklist: docs/products/cambridge-reglamento-fill.md#qa-fase-4';

        return ['ok' => $ok, 'lines' => $lines, 'failures' => $failures, 'manual' => $manual];
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionPdf(): array
    {
        $out = [];
        $rel = '/assets/reglamentos/linguaskill-terminos-condiciones.pdf';
        $abs = BASE_PATH . '/public' . $rel;
        $out[] = $this->assert('A1', is_file($abs), "plantilla existe: public{$rel}");

        $elet = BASE_PATH . '/public/assets/reglamentos/elet-reglamento.pdf';
        $out[] = $this->assert('A2', is_file($elet), 'plantilla UKS elet-reglamento.pdf existe (regresión)');

        $probe = $this->probePdfFields($abs);
        if ($probe === null) {
            $out[] = [
                'id' => 'A3',
                'status' => 'FAIL',
                'detail' => 'no se pudo inspeccionar AcroForm (¿python3 + pypdf?)',
            ];
            $out[] = [
                'id' => 'A4',
                'status' => 'FAIL',
                'detail' => 'relleno de prueba omitido',
            ];

            return $out;
        }

        $fields = $probe['fields'] ?? [];
        $needed = ['NOMBRE', 'FECHA', 'FIRMA O INICIALES'];
        $missing = array_values(array_diff($needed, $fields));
        $out[] = $this->assert(
            'A3',
            $missing === [],
            $missing === []
                ? 'campos AcroForm: ' . implode(', ', $needed)
                : 'faltan campos: ' . implode(', ', $missing) . ' (hay: ' . implode(', ', $fields) . ')'
        );

        $filled = $probe['filled_ok'] ?? false;
        $out[] = $this->assert(
            'A4',
            $filled === true,
            $filled === true
                ? 'relleno pypdf OK (nombre/fecha/iniciales legibles en form fields)'
                : ('relleno falló: ' . (string) ($probe['fill_error'] ?? 'desconocido'))
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionRequirements(): array
    {
        $out = [];

        $cambridge = $this->fakeProduct([
            'reglamento' => [
                'template_path' => '/assets/reglamentos/linguaskill-terminos-condiciones.pdf',
                'signature_mode' => 'fill_acroform',
                'doc_code' => 'reglamento_cambridge_flexible',
                'flatten' => true,
                'form_fields' => [
                    'name' => ['NOMBRE'],
                    'date' => ['FECHA'],
                    'initials' => ['FIRMA O INICIALES'],
                ],
            ],
        ]);
        $reg = CheckoutRequirements::reglamentoForProduct($cambridge);
        $out[] = $this->assert(
            'B1',
            is_array($reg)
                && ($reg['signature_mode'] ?? '') === 'fill_acroform'
                && ($reg['flatten'] ?? false) === true
                && ($reg['doc_code'] ?? '') === 'reglamento_cambridge_flexible',
            'Cambridge → fill_acroform + flatten + doc_code'
        );
        $out[] = $this->assert(
            'B2',
            is_array($reg)
                && ($reg['form_fields']['name'][0] ?? '') === 'NOMBRE'
                && ($reg['form_fields']['date'][0] ?? '') === 'FECHA'
                && ($reg['form_fields']['initials'][0] ?? '') === 'FIRMA O INICIALES',
            'form_fields alias por defecto NOMBRE/FECHA/FIRMA O INICIALES'
        );

        $uks = $this->fakeProduct([
            'reglamento' => [
                'template_path' => '/assets/reglamentos/elet-reglamento.pdf',
                'signature_mode' => 'append_to_pdf',
                'doc_code' => 'reglamento_firmado',
            ],
        ]);
        $uksReg = CheckoutRequirements::reglamentoForProduct($uks);
        $out[] = $this->assert(
            'B3',
            is_array($uksReg)
                && ($uksReg['signature_mode'] ?? '') === 'append_to_pdf'
                && ($uksReg['doc_code'] ?? '') === 'reglamento_firmado',
            'UKS → append_to_pdf + reglamento_firmado (sin romper)'
        );

        $legacy = $this->fakeProduct([
            'reglamento' => [
                'template_path' => '/assets/reglamentos/linguaskill-terminos-condiciones.pdf',
                'doc_code' => 'reglamento_cambridge',
            ],
        ]);
        $legacyReg = CheckoutRequirements::reglamentoForProduct($legacy);
        $out[] = $this->assert(
            'B4',
            is_array($legacyReg) && ($legacyReg['signature_mode'] ?? '') === 'append_to_pdf',
            'sin signature_mode → default append_to_pdf'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionUi(): array
    {
        $out = [];
        $view = (string) @file_get_contents(BASE_PATH . '/views/checkout/_reglamento_signature.php');
        $out[] = $this->assert(
            'C1',
            str_contains($view, "fill_acroform")
                && str_contains($view, 'buildFilledAcroformPdf')
                && str_contains($view, 'reglamento-preview-btn'),
            'checkout: modo fill_acroform + preview “Ver PDF con mis datos”'
        );
        $out[] = $this->assert(
            'C2',
            str_contains($view, 'reglamento-initials-input')
                && str_contains($view, 'reglamento-upload-fallback')
                && str_contains($view, 'Subir PDF firmado'),
            'checkout: iniciales editables + fallback upload'
        );
        $out[] = $this->assert(
            'C3',
            str_contains($view, 'signature-canvas')
                && str_contains($view, "append_to_pdf")
                && str_contains($view, 'buildSignedPdf'),
            'checkout: canvas append_to_pdf sigue presente (UKS)'
        );

        $admin = (string) @file_get_contents(BASE_PATH . '/views/admin/product_group_form.php');
        $out[] = $this->assert(
            'C4',
            str_contains($admin, 'value="fill_acroform"')
                && str_contains($admin, 'reglamento_field_name')
                && str_contains($admin, 'reglamento_field_initials')
                && str_contains($admin, 'reglamento-acroform-options'),
            'admin: selector fill_acroform + aliases + flatten UI'
        );

        $svc = (string) @file_get_contents(BASE_PATH . '/src/Services/ProductAdminService.php');
        $out[] = $this->assert(
            'C5',
            str_contains($svc, "'fill_acroform'")
                && str_contains($svc, 'form_fields')
                && str_contains($svc, 'reglamento_field_initials'),
            'ProductAdminService persiste mode/form_fields/flatten'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionSeed(): array
    {
        $out = [];
        $seeder = (string) @file_get_contents(BASE_PATH . '/src/Setup/CatalogSeeder.php');
        $out[] = $this->assert(
            'D1',
            str_contains($seeder, "'signature_mode' => 'fill_acroform'")
                && str_contains($seeder, 'reglamento_cambridge_flexible')
                && str_contains($seeder, 'reglamento_cambridge_fixed'),
            'CatalogSeeder: Cambridge fill_acroform + doc_codes distintos'
        );
        $out[] = $this->assert(
            'D2',
            str_contains($seeder, "'signature_mode' => 'append_to_pdf'")
                && str_contains($seeder, 'reglamento_firmado')
                && str_contains($seeder, 'elet-reglamento.pdf'),
            'CatalogSeeder: UKS sigue append_to_pdf'
        );

        $ensurer = (string) @file_get_contents(BASE_PATH . '/src/Setup/CutoverConfigEnsurer.php');
        $out[] = $this->assert(
            'D3',
            str_contains($ensurer, 'fill_acroform')
                && str_contains($ensurer, 'reglamento_cambridge_flexible')
                && str_contains($ensurer, 'reglamento_cambridge_fixed')
                && str_contains($ensurer, 'linguaskill-terminos-condiciones.pdf'),
            'CutoverConfigEnsurer parchea Cambridge a fill_acroform'
        );

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionMail(): array
    {
        $out = [];
        $mail = (string) @file_get_contents(BASE_PATH . '/src/Services/MailTemplateService.php');
        $out[] = $this->assert(
            'E1',
            str_contains($mail, 'reglamento_url')
                && str_contains($mail, "{{reglamento_url}}"),
            'MailTemplateService expone {{reglamento_url}}'
        );

        $checkout = (string) @file_get_contents(BASE_PATH . '/src/Services/CheckoutService.php');
        $out[] = $this->assert(
            'E2',
            str_contains($checkout, 'reglamentoForProduct')
                || str_contains($checkout, 'doc_code'),
            'CheckoutService sigue atando reglamento al caso'
        );

        $out[] = [
            'id' => 'E3',
            'status' => 'MANUAL',
            'detail' => 'staging: correo proveedor Cambridge adjunta doc_code del grupo (flexible/fixed)',
        ];

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
                'detail' => 'Chrome: camino feliz Cambridge sin download/upload',
            ],
            [
                'id' => 'F2',
                'status' => 'MANUAL',
                'detail' => 'Safari: preview “Ver PDF con mis datos” + envío',
            ],
            [
                'id' => 'F3',
                'status' => 'MANUAL',
                'detail' => 'Firefox: check → PDF relleno adjunto',
            ],
            [
                'id' => 'F4',
                'status' => 'MANUAL',
                'detail' => 'iPad mostrador: prefill + touch en aceptación (sin canvas)',
            ],
            [
                'id' => 'F5',
                'status' => 'MANUAL',
                'detail' => 'Acrobat/Preview: campos visibles tras flatten',
            ],
            [
                'id' => 'F6',
                'status' => 'MANUAL',
                'detail' => 'Regresión UKS: canvas o subir PDF; sin UI AcroForm preview',
            ],
            [
                'id' => 'F7',
                'status' => 'MANUAL',
                'detail' => 'Fallback Cambridge: PDF manual reemplaza automático',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function fakeProduct(array $config): array
    {
        return [
            'id' => 0,
            'code' => 'qa-fake',
            'name' => 'QA fake',
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @return array{fields?:list<string>,filled_ok?:bool,fill_error?:string}|null
     */
    private function probePdfFields(string $absPath): ?array
    {
        if (!is_file($absPath)) {
            return null;
        }

        $script = <<<'PY'
import json, sys, tempfile
from pathlib import Path
try:
    from pypdf import PdfReader, PdfWriter
except Exception as e:
    print(json.dumps({"error": f"pypdf: {e}"}))
    sys.exit(2)

path = Path(sys.argv[1])
try:
    reader = PdfReader(str(path))
    fields = list((reader.get_fields() or {}).keys())
    writer = PdfWriter()
    writer.append(reader)
    values = {
        "NOMBRE": "Juan Perez Gomez",
        "FECHA": "10 de octubre de 2026",
        "FIRMA O INICIALES": "JPG",
    }
    writer.update_page_form_field_values(writer.pages[0], values)
    try:
        writer.set_need_appearances_writer(True)
    except Exception:
        pass
    with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as tmp:
        writer.write(tmp)
        out = Path(tmp.name)
    r2 = PdfReader(str(out))
    got = r2.get_fields() or {}
    ok = True
    for k, v in values.items():
        cur = got.get(k)
        val = getattr(cur, "value", None) if cur is not None else None
        if str(val or "") != v:
            ok = False
            break
    try:
        out.unlink(missing_ok=True)
    except Exception:
        pass
    print(json.dumps({"fields": fields, "filled_ok": ok}))
except Exception as e:
    print(json.dumps({"error": str(e)}))
    sys.exit(1)
PY;

        $cmd = 'python3 -c ' . escapeshellarg($script) . ' ' . escapeshellarg($absPath) . ' 2>/dev/null';
        $raw = shell_exec($cmd);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($raw)))));
        $jsonLine = $lines === [] ? '' : (string) end($lines);
        $data = json_decode($jsonLine, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['error'])) {
            return [
                'fields' => [],
                'filled_ok' => false,
                'fill_error' => (string) $data['error'],
            ];
        }

        /** @var list<string> $fields */
        $fields = [];
        foreach (($data['fields'] ?? []) as $f) {
            $fields[] = (string) $f;
        }

        return [
            'fields' => $fields,
            'filled_ok' => (bool) ($data['filled_ok'] ?? false),
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
