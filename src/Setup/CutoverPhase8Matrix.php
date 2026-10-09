<?php

declare(strict_types=1);

namespace App\Setup;

use App\Services\CheckoutRequirements;
use App\Services\ExamScheduleService;
use App\Services\VenueScheduleEngine;

/**
 * Fase 8 — matriz A–D automatizable a nivel de servicios (sin comprar en prod).
 *
 * Cubre la lógica de aceptación del plan maestro. Los pasos que requieren UI/BD
 * real (subir PDF, aprobar en ops, enviar correo) quedan como MANUAL.
 *
 * @return array{ok:bool,lines:list<string>,failures:int,manual:int}
 */
final class CutoverPhase8Matrix
{
    /** @return array{ok:bool,lines:list<string>,failures:int,manual:int} */
    public function run(): array
    {
        $lines = [];
        $failures = 0;
        $manual = 0;

        $lines[] = '=== Cutover Fase 8 — matriz automática (servicios) ===';
        $lines[] = '';

        $sections = [
            'A) UKS ELeT — docs + gate' => $this->sectionA(),
            'B) Cambridge — sede A vs B' => $this->sectionB(),
            'C) CENNI — post-pago' => $this->sectionC(),
            'D) Combo examen + CENNI' => $this->sectionD(),
        ];

        foreach ($sections as $title => $cases) {
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
            ? "RESULTADO: MATRIZ LÓGICA OK ({$manual} paso(s) manuales restantes en staging/prod)"
            : "RESULTADO: NO LISTO — {$failures} fallo(s), {$manual} manual(es)";
        $lines[] = 'Manual: docs/products/cutover-phase8-qa.md (A2–A6, B4, C2–C4 con UI/BD).';
        $lines[] = 'Política combo: docs/products/combo-elet-cenni.md';

        return ['ok' => $ok, 'lines' => $lines, 'failures' => $failures, 'manual' => $manual];
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionA(): array
    {
        $uks = $this->product($this->uksConfig());
        $out = [];

        $timing = CheckoutRequirements::studentDocsTiming($uks);
        $before = CheckoutRequirements::docsForProduct($uks);
        $codes = array_column($before, 'code');
        $hasIne = in_array('ine', $codes, true);
        $required = $hasIne && !empty($before[0]['required']);

        $out[] = $this->assert(
            'A1',
            $timing === 'before_payment' && $required,
            $timing === 'before_payment' && $required
                ? 'sin INE en checkout no hay docs requeridos vacíos (bloquea pago)'
                : "timing={$timing}, ine_required=" . ($required ? '1' : '0')
        );

        $out[] = $this->assert(
            'A2',
            $hasIne && $timing === 'before_payment',
            $hasIne
                ? 'con INE configurado el checkout puede confirmar (flujo before_payment)'
                : 'falta INE en required_docs'
        );

        $gate = CheckoutRequirements::studentDocsGateEnabled($uks);
        $requireSend = false;
        foreach ($before as $doc) {
            if (($doc['code'] ?? '') === 'ine' && !empty($doc['require_for_provider_send'])) {
                $requireSend = true;
            }
        }
        $out[] = $this->assert(
            'A3',
            $gate && $requireSend,
            $gate && $requireSend
                ? 'gate ON + require_for_provider_send en INE (ops no envía sin approved)'
                : 'gate=' . ($gate ? '1' : '0') . ' require_send=' . ($requireSend ? '1' : '0')
        );

        $out[] = [
            'id' => 'A4',
            'status' => 'MANUAL',
            'detail' => 'aprobar INE y reenviar uks_solicitud → verificar {{doc_ine_url}} en correo',
        ];
        $out[] = [
            'id' => 'A5',
            'status' => 'MANUAL',
            'detail' => 'rechazar INE → plantilla student_document_rejected + re-subida a pending',
        ];
        $out[] = [
            'id' => 'A6',
            'status' => 'MANUAL',
            'detail' => 'Operaciones → Docs por revisar muestra el caso',
        ];

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionB(): array
    {
        $cambridge = $this->product($this->cambridgeVenueConfig());
        $svc = new ExamScheduleService();
        $venues = $svc->openVenues($cambridge);
        $out = [];

        $out[] = $this->assert(
            'B1',
            count($venues) >= 2,
            count($venues) >= 2
                ? '2 sedes visibles en picker (' . implode(', ', array_column($venues, 'id')) . ')'
                : 'se esperaban ≥2 sedes, hay ' . count($venues)
        );

        $sessionsA = $svc->openSessions($cambridge, 'sede-a');
        $sessionsB = $svc->openSessions($cambridge, 'sede-b');
        $onlyA = $sessionsA !== [] && $this->allVenue($sessionsA, 'sede-a');
        $onlyB = $sessionsB !== [] && $this->allVenue($sessionsB, 'sede-b');
        $out[] = $this->assert(
            'B2',
            $onlyA,
            $onlyA
                ? 'sede A → ' . count($sessionsA) . ' convocatoria(s) con venue_id/city/address'
                : 'filtro sede A falló o sin sesiones'
        );
        $out[] = $this->assert(
            'B3',
            $onlyB && !$this->sameVenueSet($sessionsA, $sessionsB),
            $onlyB
                ? 'sede B distinta a A (' . count($sessionsB) . ' convocatoria(s))'
                : 'filtro sede B falló o igual a A'
        );

        // Persistencia de purchase: validateSelection debe devolver venue fields.
        $sample = $sessionsA[0] ?? null;
        if (is_array($sample)) {
            try {
                $resolved = $svc->validateSelection(
                    $cambridge,
                    (string) $sample['exam_date'],
                    (string) $sample['exam_time'],
                    [
                        'venue_id' => 'sede-a',
                        'session_id' => (string) ($sample['id'] ?? ''),
                    ]
                );
                $ok = ($resolved['venue_id'] ?? '') === 'sede-a'
                    && trim((string) ($resolved['venue'] ?? '')) !== ''
                    && trim((string) ($resolved['city'] ?? '')) !== '';
                $line = trim(implode(', ', array_filter([
                    (string) ($resolved['venue'] ?? ''),
                    (string) ($resolved['city'] ?? ''),
                    (string) ($resolved['address'] ?? ''),
                ], static fn ($v) => $v !== '')));
                $out[] = $this->assert(
                    'B2b',
                    $ok && $line !== '',
                    $ok
                        ? 'exam_schedule persistible + exam_venue_line="' . $line . '"'
                        : 'validateSelection sin venue/city'
                );
            } catch (\Throwable $e) {
                $out[] = $this->assert('B2b', false, 'validateSelection: ' . $e->getMessage());
            }
        } else {
            $out[] = $this->assert('B2b', false, 'sin sesión sede A para validateSelection');
        }

        $out[] = [
            'id' => 'B4',
            'status' => 'MANUAL',
            'detail' => 'enviar correo de prueba con {{exam_venue_line}} en staging',
        ];

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionC(): array
    {
        $cenni = $this->product($this->cenniConfig());
        $out = [];

        $timing = CheckoutRequirements::studentDocsTiming($cenni);
        $before = CheckoutRequirements::docsForProduct($cenni);
        $after = CheckoutRequirements::registrationDocsForProduct($cenni);
        $codes = array_column($after, 'code');
        $need = ['ine', 'curp', 'solicitud', 'certificado_constancia'];
        $missing = array_values(array_diff($need, $codes));

        $out[] = $this->assert(
            'C1',
            $timing === 'after_payment' && $before === [],
            $timing === 'after_payment' && $before === []
                ? 'paga sin docs en checkout (required_docs vacío, timing after_payment)'
                : "timing={$timing}, checkout_docs=" . count($before)
        );

        $out[] = $this->assert(
            'C2',
            $missing === [],
            $missing === []
                ? 'portal pide paquete CENNI completo (' . count($after) . ')'
                : 'faltan: ' . implode(', ', $missing)
        );

        $gate = CheckoutRequirements::studentDocsGateEnabled($cenni);
        $out[] = $this->assert(
            'C3',
            $gate && count($after) === 4,
            $gate
                ? 'gate activo: contador X/Y llega a listo al aprobar los 4'
                : 'gate desactivado en CENNI'
        );

        $out[] = [
            'id' => 'C4',
            'status' => 'MANUAL',
            'detail' => 'enviar cenni_solicitud con docs aprobados → {{doc_*_url}}',
        ];

        return $out;
    }

    /**
     * @return list<array{id:string,status:string,detail:string}>
     */
    private function sectionD(): array
    {
        $exam = $this->product($this->uksConfig());
        $cenni = $this->product($this->cenniConfig());
        $out = [];

        $out[] = [
            'id' => 'D1',
            'status' => 'MANUAL',
            'detail' => 'compra combo real: un pago, N trackings (staging)',
        ];

        $examTiming = CheckoutRequirements::studentDocsTiming($exam);
        $examDocs = array_column(CheckoutRequirements::docsForProduct($exam), 'code');
        $out[] = $this->assert(
            'D2',
            $examTiming === 'before_payment' && in_array('ine', $examDocs, true),
            'docs del examen = INE before_payment (grupo UKS)'
        );

        $cenniTiming = CheckoutRequirements::studentDocsTiming($cenni);
        $cenniBefore = CheckoutRequirements::docsForProduct($cenni);
        $out[] = $this->assert(
            'D3',
            $cenniTiming === 'after_payment' && $cenniBefore === [],
            'docs CENNI after_payment (no entran al checkout del examen)'
        );

        // Gate del examen solo mira studentDocs del producto examen.
        $examGateDocs = array_column(CheckoutRequirements::studentDocsForProduct($exam), 'code');
        $cenniOnly = ['curp', 'solicitud', 'certificado_constancia'];
        $leaked = array_values(array_intersect($examGateDocs, $cenniOnly));
        $out[] = $this->assert(
            'D4',
            $leaked === [] && in_array('ine', $examGateDocs, true),
            $leaked === []
                ? 'gate proveedor examen solo exige INE (no docs CENNI)'
                : 'leak de docs CENNI en gate examen: ' . implode(', ', $leaked)
        );

        return $out;
    }

    /** @return array<string, mixed> */
    private function uksConfig(): array
    {
        return [
            'required_docs' => [[
                'code' => 'ine',
                'label' => 'INE o pasaporte escaneado (PDF)',
                'required' => true,
                'accept' => '.pdf',
                'include_in_provider_mail' => true,
                'require_for_provider_send' => true,
            ]],
            'registration_docs' => [],
            'student_docs_timing' => 'before_payment',
            'student_docs_gate' => ['block_until_approved' => true],
        ];
    }

    /** @return array<string, mixed> */
    private function cenniConfig(): array
    {
        $doc = static function (string $code, string $label): array {
            return [
                'code' => $code,
                'label' => $label,
                'required' => true,
                'accept' => '.pdf',
                'include_in_provider_mail' => true,
                'require_for_provider_send' => true,
            ];
        };

        return [
            'required_docs' => [],
            'registration_docs' => [
                $doc('ine', 'INE / pasaporte'),
                $doc('curp', 'CURP'),
                $doc('solicitud', 'Solicitud'),
                $doc('certificado_constancia', 'Certificado / constancia'),
            ],
            'student_docs_timing' => 'after_payment',
            'student_docs_gate' => ['block_until_approved' => true],
        ];
    }

    /** @return array<string, mixed> */
    private function cambridgeVenueConfig(): array
    {
        $d1 = date('Y-m-d', strtotime('+45 days'));
        $d2 = date('Y-m-d', strtotime('+60 days'));
        $deadline = date('Y-m-d', strtotime('+30 days'));
        $venues = VenueScheduleEngine::normalizeVenues([
            'venues' => [
                [
                    'id' => 'sede-a',
                    'name' => 'Campus Centro',
                    'city' => 'León, Gto.',
                    'address' => 'Av. Ejemplo 123',
                    'active' => true,
                    'rule' => [
                        'type' => 'dated',
                        'sessions' => [[
                            'exam_date' => $d1,
                            'exam_time' => '10:00',
                            'registration_deadline' => $deadline,
                            'label' => 'Sede A',
                        ]],
                    ],
                ],
                [
                    'id' => 'sede-b',
                    'name' => 'Campus Norte',
                    'city' => 'León, Gto.',
                    'address' => 'Blvd. Norte 456',
                    'active' => true,
                    'rule' => [
                        'type' => 'dated',
                        'sessions' => [[
                            'exam_date' => $d2,
                            'exam_time' => '12:00',
                            'registration_deadline' => $deadline,
                            'label' => 'Sede B',
                        ]],
                    ],
                ],
            ],
        ]);

        return [
            'required_docs' => [[
                'code' => 'ine',
                'label' => 'INE',
                'required' => true,
                'accept' => '.pdf',
                'include_in_provider_mail' => true,
                'require_for_provider_send' => true,
            ]],
            'registration_docs' => [],
            'student_docs_timing' => 'before_payment',
            'student_docs_gate' => ['block_until_approved' => true],
            'schedule' => [
                'mode' => ExamScheduleService::MODE_VENUE_SCHEDULES,
                'venues' => $venues,
            ],
        ];
    }

    /** @param array<string, mixed> $cfg */
    private function product(array $cfg): array
    {
        return [
            'config_json' => null,
            'group_config_json' => $cfg,
        ];
    }

    /** @param list<array<string, mixed>> $sessions */
    private function allVenue(array $sessions, string $venueId): bool
    {
        foreach ($sessions as $s) {
            if ((string) ($s['venue_id'] ?? '') !== $venueId) {
                return false;
            }
            if (trim((string) ($s['venue'] ?? '')) === '' || trim((string) ($s['city'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     */
    private function sameVenueSet(array $a, array $b): bool
    {
        $idsA = array_unique(array_map(static fn (array $s): string => (string) ($s['venue_id'] ?? ''), $a));
        $idsB = array_unique(array_map(static fn (array $s): string => (string) ($s['venue_id'] ?? ''), $b));
        sort($idsA);
        sort($idsB);

        return $idsA === $idsB;
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
