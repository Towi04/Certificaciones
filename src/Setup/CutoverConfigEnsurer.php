<?php

declare(strict_types=1);

namespace App\Setup;

use App\Repositories\MailTemplateRepository;
use App\Repositories\ProductGroupRepository;

/**
 * Parches seguros para el cutover UKS / Cambridge / CENNI.
 * Solo rellena claves faltantes; no pisa listas ni agendas ya editadas.
 */
final class CutoverConfigEnsurer
{
    /** @return list<string> */
    public function run(): array
    {
        $log = [];
        $log = array_merge($log, $this->ensureGroupDocs('uks-elet', $this->uksExamPatch()));
        $log = array_merge($log, $this->ensureGroupDocs('cambridge-flexible', $this->cambridgeInePatch('reglamento_cambridge_flexible')));
        $log = array_merge($log, $this->ensureGroupDocs('cambridge-fixed', $this->cambridgeInePatch('reglamento_cambridge_fixed')));
        $log = array_merge($log, $this->ensureGroupDocs('uks-elet-cenni', $this->cenniPatch()));
        $log = array_merge($log, $this->ensureMailTemplates());

        if ($log === []) {
            $log[] = 'Cutover: nada que parchear (grupos/plantillas ya listos).';
        }

        return $log;
    }

    /**
     * @param array<string, mixed> $patch
     * @return list<string>
     */
    private function ensureGroupDocs(string $groupCode, array $patch): array
    {
        $repo = new ProductGroupRepository();
        $group = $repo->findByCode($groupCode);
        if ($group === null) {
            return ['Grupo ' . $groupCode . ': no existe (omitido).'];
        }

        $cfg = [];
        $raw = $group['config_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $cfg = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw)) {
            $cfg = $raw;
        }

        $changed = [];
        foreach ($patch as $key => $value) {
            if ($key === 'required_docs' || $key === 'registration_docs') {
                $current = is_array($cfg[$key] ?? null) ? $cfg[$key] : null;
                if ($current === null || $current === []) {
                    $cfg[$key] = $value;
                    $changed[] = $key;
                }
                continue;
            }
            if ($key === 'student_docs_timing' || $key === 'student_docs_gate') {
                if (!array_key_exists($key, $cfg) || $cfg[$key] === null || $cfg[$key] === '') {
                    $cfg[$key] = $value;
                    $changed[] = $key;
                }
                continue;
            }
            if ($key === 'reglamento' && is_array($value)) {
                $current = is_array($cfg['reglamento'] ?? null) ? $cfg['reglamento'] : null;
                if ($current === null || $current === []) {
                    $cfg['reglamento'] = $value;
                    $changed[] = 'reglamento';
                    continue;
                }
                $nestedChanged = false;
                foreach ($value as $nk => $nv) {
                    if (!array_key_exists($nk, $current) || $current[$nk] === null || $current[$nk] === '') {
                        $current[$nk] = $nv;
                        $nestedChanged = true;
                    }
                }
                // Upgrade seguro: plantilla Linguaskill con modo viejo → fill_acroform.
                $path = (string) ($current['template_path'] ?? '');
                $mode = strtolower(trim((string) ($current['signature_mode'] ?? '')));
                if (
                    str_contains($path, 'linguaskill')
                    && ($mode === '' || $mode === 'append_to_pdf')
                ) {
                    $current['signature_mode'] = 'fill_acroform';
                    $current['flatten'] = array_key_exists('flatten', $value) ? (bool) $value['flatten'] : true;
                    if (isset($value['form_fields']) && is_array($value['form_fields'])) {
                        $current['form_fields'] = $value['form_fields'];
                    }
                    $nestedChanged = true;
                }
                if ($nestedChanged) {
                    $cfg['reglamento'] = $current;
                    $changed[] = 'reglamento';
                }
                continue;
            }
            // Otras claves del patch: solo si faltan.
            if (!array_key_exists($key, $cfg)) {
                $cfg[$key] = $value;
                $changed[] = $key;
            }
        }

        if ($changed === []) {
            return ['Grupo ' . $groupCode . ': docs/timing/gate ya configurados.'];
        }

        $repo->update((int) $group['id'], [
            'name' => (string) $group['name'],
            'supplier_id' => $group['supplier_id'] ?? null,
            'config_json' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
        ]);

        return ['Grupo ' . $groupCode . ': añadido ' . implode(', ', $changed) . '.'];
    }

    /** @return array<string, mixed> */
    private function ineDoc(): array
    {
        return [
            'code' => 'ine',
            'label' => 'INE o pasaporte escaneado (PDF)',
            'description' => 'PDF con ambos lados, nítido y completo. No fotos borrosas ni recortes.',
            'required' => true,
            'accept' => '.pdf',
            'include_in_provider_mail' => true,
            'require_for_provider_send' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function uksExamPatch(): array
    {
        return [
            'required_docs' => [$this->ineDoc()],
            'registration_docs' => [],
            'student_docs_timing' => 'before_payment',
            'student_docs_gate' => ['block_until_approved' => true],
        ];
    }

    /** @return array<string, mixed> */
    private function cambridgeInePatch(string $reglamentoDocCode): array
    {
        return [
            'required_docs' => [$this->ineDoc()],
            'registration_docs' => [],
            'student_docs_timing' => 'before_payment',
            'student_docs_gate' => ['block_until_approved' => true],
            'reglamento' => $this->cambridgeReglamento($reglamentoDocCode),
        ];
    }

    /** @return array<string, mixed> */
    private function cambridgeReglamento(string $docCode): array
    {
        return [
            'template_path' => '/assets/reglamentos/linguaskill-terminos-condiciones.pdf',
            'source_url' => '',
            'signature_mode' => 'fill_acroform',
            'required_before_checkout' => true,
            'doc_code' => $docCode,
            'flatten' => true,
            'form_fields' => [
                'name' => ['NOMBRE'],
                'date' => ['FECHA'],
                'initials' => ['FIRMA O INICIALES'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function cenniPatch(): array
    {
        return [
            'required_docs' => [],
            'registration_docs' => [
                [
                    'code' => 'ine',
                    'label' => 'INE / pasaporte',
                    'description' => 'Ambos lados en un PDF legible.',
                    'required' => true,
                    'accept' => '.pdf',
                    'include_in_provider_mail' => true,
                    'require_for_provider_send' => true,
                ],
                [
                    'code' => 'curp',
                    'label' => 'CURP',
                    'description' => 'PDF o imagen legible de la CURP.',
                    'required' => true,
                    'accept' => '.pdf,.jpg,.jpeg,.png',
                    'include_in_provider_mail' => true,
                    'require_for_provider_send' => true,
                ],
                [
                    'code' => 'solicitud',
                    'label' => 'Solicitud',
                    'description' => 'Solicitud del trámite completa y firmada (PDF).',
                    'required' => true,
                    'accept' => '.pdf',
                    'include_in_provider_mail' => true,
                    'require_for_provider_send' => true,
                ],
                [
                    'code' => 'certificado_constancia',
                    'label' => 'Certificado / constancia',
                    'description' => 'Certificado o constancia que respalda el trámite (PDF).',
                    'required' => true,
                    'accept' => '.pdf',
                    'include_in_provider_mail' => true,
                    'require_for_provider_send' => true,
                ],
            ],
            'student_docs_timing' => 'after_payment',
            'student_docs_gate' => ['block_until_approved' => true],
        ];
    }

    /** @return list<string> */
    private function ensureMailTemplates(): array
    {
        $log = [];
        $repo = new MailTemplateRepository();

        // UKS solicitud: enlace INE / student_docs_html si faltan.
        $uks = $repo->findByCode('uks_solicitud');
        if ($uks !== null) {
            $body = (string) ($uks['body_html'] ?? '');
            $newBody = $body;
            $bits = [];
            if ($newBody !== '' && !str_contains($newBody, 'doc_ine_url')) {
                $patched = str_replace(
                    '<a href="{{reglamento_url}}">Reglamento</a>',
                    '<a href="{{doc_ine_url}}">INE / identificación</a> · <a href="{{reglamento_url}}">Reglamento</a>',
                    $newBody
                );
                if ($patched === $newBody) {
                    $patched = $newBody . "\n<p>Identificación: <a href=\"{{doc_ine_url}}\">{{doc_ine_label}}</a></p>";
                }
                $newBody = $patched;
                $bits[] = '{{doc_ine_url}}';
            }
            if ($newBody !== '' && !str_contains($newBody, 'student_docs_html')) {
                if (str_contains($newBody, '{{documentos_html}}')) {
                    $newBody = str_replace(
                        '{{documentos_html}}',
                        "{{documentos_html}}\n{{student_docs_html}}",
                        $newBody
                    );
                } else {
                    $newBody .= "\n{{student_docs_html}}";
                }
                $bits[] = '{{student_docs_html}}';
            }
            if ($bits !== [] && $newBody !== $body) {
                $repo->upsert(
                    'uks_solicitud',
                    (string) ($uks['name'] ?? 'UKS · Solicitud de examen'),
                    (string) ($uks['subject'] ?? ''),
                    $newBody,
                    (string) ($uks['trigger_mode'] ?? 'automatic')
                );
                $log[] = 'Plantilla uks_solicitud: añadido ' . implode(', ', $bits) . '.';
            }
        } else {
            $log[] = 'Plantilla uks_solicitud: no existe (ejecuta seed de plantillas).';
        }

        if ($repo->findByCode('cenni_solicitud') === null) {
            $repo->upsert(
                'cenni_solicitud',
                'CENNI · Solicitud de trámite (docs alumno)',
                'Trámite CENNI · {{full_name}} · {{matricula}}',
                '<p>Solicitud de trámite <strong>CENNI</strong> — Instituto DOCEO</p>'
                . '<ul>'
                . '<li><strong>Alumno:</strong> {{full_name}}</li>'
                . '<li><strong>Matrícula:</strong> {{matricula}}</li>'
                . '<li><strong>Correo:</strong> {{student_email}}</li>'
                . '<li><strong>Producto:</strong> {{product_name}}</li>'
                . '</ul>'
                . '{{student_docs_html}}'
                . '<p>Enlaces:</p><ul>'
                . '<li><a href="{{doc_ine_url}}">{{doc_ine_label}}</a></li>'
                . '<li><a href="{{doc_curp_url}}">{{doc_curp_label}}</a></li>'
                . '<li><a href="{{doc_solicitud_url}}">{{doc_solicitud_label}}</a></li>'
                . '<li><a href="{{doc_certificado_constancia_url}}">{{doc_certificado_constancia_label}}</a></li>'
                . '</ul>'
                . '<p>— Instituto DOCEO</p>',
                'manual'
            );
            $log[] = 'Plantilla correo creada: cenni_solicitud.';
        }

        return $log;
    }
}
