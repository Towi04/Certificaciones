<?php

declare(strict_types=1);

namespace App\Setup;

use App\Config\Env;
use App\Repositories\MailTemplateRepository;
use App\Repositories\ProductGroupRepository;
use App\Services\CheckoutRequirements;
use App\Services\ExamScheduleService;
use App\Services\VenueScheduleEngine;

/**
 * Fase 8 — valida que la config de cutover esté lista (sin comprar nada).
 *
 * @return array{ok:bool,lines:list<string>,failures:int,warnings:int}
 */
final class CutoverReadinessChecker
{
    /** @return array{ok:bool,lines:list<string>,failures:int,warnings:int} */
    public function run(): array
    {
        $lines = [];
        $failures = 0;
        $warnings = 0;

        $lines[] = '=== Cutover readiness (Fase 8) ===';
        $lines[] = '';

        // Smoke sin BD: motores.
        $engineOk = $this->smokeEngines($lines);
        if (!$engineOk) {
            $failures++;
        }

        $lines[] = '';
        $lines[] = '--- Grupos / plantillas (BD) ---';

        $dbName = trim((string) (Env::get('DB_NAME', '') ?? ''));
        if ($dbName === '') {
            $lines[] = 'WARN  BD no configurada (DB_NAME vacío): solo se validó el smoke de motores.';
            $lines[] = '      En staging/prod ejecuta de nuevo con .env completo.';
            $warnings++;
            $lines[] = '';
            $ok = $failures === 0;
            $lines[] = $ok
                ? "RESULTADO: SMOKE OK ({$warnings} aviso(s); falta validar BD)"
                : "RESULTADO: NO LISTO — {$failures} fallo(s)";
            $lines[] = 'Siguiente: docs/products/cutover-phase8-qa.md';

            return ['ok' => $ok, 'lines' => $lines, 'failures' => $failures, 'warnings' => $warnings];
        }

        try {
            $gRepo = new ProductGroupRepository();
            $mailRepo = new MailTemplateRepository();
        } catch (\Throwable $e) {
            $lines[] = 'FAIL  No se pudo conectar a BD: ' . $e->getMessage();

            return ['ok' => false, 'lines' => $lines, 'failures' => $failures + 1, 'warnings' => $warnings];
        }

        $checks = [
            'uks-elet' => fn (array $p): array => $this->expectBeforePaymentIne($p, true),
            'cambridge-flexible' => fn (array $p): array => $this->expectBeforePaymentIne($p, false),
            'cambridge-fixed' => fn (array $p): array => $this->expectCambridgeFixed($p),
            'uks-elet-cenni' => fn (array $p): array => $this->expectCenni($p),
        ];

        foreach ($checks as $code => $fn) {
            $group = $gRepo->findByCode($code);
            if ($group === null) {
                $lines[] = "WARN  Grupo {$code}: no existe";
                $warnings++;
                continue;
            }
            $product = [
                'config_json' => null,
                'group_config_json' => $this->decodeConfig($group['config_json'] ?? null),
            ];
            [$ok, $msgs] = $fn($product);
            foreach ($msgs as $m) {
                $prefix = str_starts_with($m, 'aviso:') ? 'WARN  ' : ($ok ? 'OK    ' : 'FAIL  ');
                if (str_starts_with($m, 'aviso:')) {
                    $warnings++;
                }
                $lines[] = $prefix . "{$code}: {$m}";
            }
            if (!$ok) {
                $failures++;
            }
        }

        $lines[] = '';
        $lines[] = '--- Plantillas correo ---';
        foreach ([
            'uks_solicitud' => ['doc_ine_url', 'reglamento_url'],
            'cenni_solicitud' => ['student_docs_html', 'doc_ine_url'],
            'student_document_rejected' => ['doc_label', 'rejection_reason', 'case_url'],
        ] as $code => $needles) {
            $tpl = $mailRepo->findByCode($code);
            if ($tpl === null) {
                $lines[] = "FAIL  Plantilla {$code}: no existe";
                $failures++;
                continue;
            }
            $hay = (string) ($tpl['subject'] ?? '') . ' ' . (string) ($tpl['body_html'] ?? '');
            $missing = [];
            foreach ($needles as $n) {
                if (!str_contains($hay, $n)) {
                    $missing[] = '{{' . $n . '}}';
                }
            }
            if ($missing === []) {
                $lines[] = "OK    Plantilla {$code}: placeholders clave presentes";
            } else {
                $lines[] = "FAIL  Plantilla {$code}: faltan " . implode(', ', $missing);
                $failures++;
            }
        }

        $lines[] = '';
        $ok = $failures === 0;
        $lines[] = $ok
            ? "RESULTADO: LISTO ({$warnings} aviso(s))"
            : "RESULTADO: NO LISTO — {$failures} fallo(s), {$warnings} aviso(s)";
        $lines[] = 'Siguiente: ejecutar matriz manual en docs/products/cutover-phase8-qa.md';

        return ['ok' => $ok, 'lines' => $lines, 'failures' => $failures, 'warnings' => $warnings];
    }

    /** @param list<string> $lines */
    private function smokeEngines(array &$lines): bool
    {
        $lines[] = '--- Smoke motores (sin BD) ---';
        $ok = true;
        try {
            $venues = VenueScheduleEngine::normalizeVenues([
                'venues' => [
                    [
                        'id' => 'sede-a',
                        'name' => 'Sede A',
                        'city' => 'León',
                        'address' => 'Calle 1',
                        'active' => true,
                        'rule' => [
                            'type' => 'recurring',
                            'dows' => [2],
                            'times' => ['10:00'],
                            'horizon_weeks' => 8,
                            'deadline' => ['type' => 'previous_weekday', 'dow' => 3, 'weeks_before' => 1],
                        ],
                    ],
                    [
                        'id' => 'sede-b',
                        'name' => 'Sede B',
                        'city' => 'Gto',
                        'address' => 'Calle 2',
                        'active' => true,
                        'rule' => [
                            'type' => 'dated',
                            'sessions' => [
                                [
                                    'exam_date' => date('Y-m-d', strtotime('+60 days')),
                                    'exam_time' => '10:00',
                                    'registration_deadline' => date('Y-m-d', strtotime('+40 days')),
                                    'label' => 'Demo',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
            $sessions = VenueScheduleEngine::materializeSessions($venues, []);
            if ($venues === [] || $sessions === []) {
                $lines[] = 'FAIL  VenueScheduleEngine no materializó sedes/sesiones';
                $ok = false;
            } else {
                $lines[] = 'OK    VenueScheduleEngine: ' . count($venues) . ' sedes, '
                    . count($sessions) . ' sesiones materializadas';
            }

            $product = [
                'config_json' => null,
                'group_config_json' => [
                    'required_docs' => [[
                        'code' => 'ine',
                        'label' => 'INE',
                        'required' => true,
                        'accept' => '.pdf',
                    ]],
                    'student_docs_timing' => 'before_payment',
                    'student_docs_gate' => ['block_until_approved' => true],
                    'schedule' => ['mode' => ExamScheduleService::MODE_VENUE_SCHEDULES, 'venues' => $venues],
                ],
            ];
            if (CheckoutRequirements::studentDocsTiming($product) !== 'before_payment') {
                $lines[] = 'FAIL  studentDocsTiming esperado before_payment';
                $ok = false;
            } else {
                $lines[] = 'OK    CheckoutRequirements timing before_payment';
            }
            if (!CheckoutRequirements::studentDocsGateEnabled($product)) {
                $lines[] = 'FAIL  studentDocsGateEnabled debería ser true';
                $ok = false;
            } else {
                $lines[] = 'OK    studentDocsGateEnabled activo con INE required';
            }
            $mode = ExamScheduleService::normalizeMode(ExamScheduleService::MODE_VENUE_SCHEDULES);
            if ($mode !== ExamScheduleService::MODE_VENUE_SCHEDULES) {
                $lines[] = 'FAIL  MODE_VENUE_SCHEDULES no reconocido';
                $ok = false;
            } else {
                $lines[] = 'OK    ExamScheduleService reconoce venue_schedules';
            }
        } catch (\Throwable $e) {
            $lines[] = 'FAIL  Smoke motores: ' . $e->getMessage();
            $ok = false;
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $product
     * @return array{0:bool,1:list<string>}
     */
    private function expectBeforePaymentIne(array $product, bool $uksOnlineOk): array
    {
        $msgs = [];
        $ok = true;
        $timing = CheckoutRequirements::studentDocsTiming($product);
        if ($timing !== 'before_payment') {
            $ok = false;
            $msgs[] = "timing={$timing} (esperado before_payment)";
        } else {
            $msgs[] = 'timing before_payment';
        }
        $docs = CheckoutRequirements::studentDocsForProduct($product);
        $codes = array_column($docs, 'code');
        if (!in_array('ine', $codes, true)) {
            $ok = false;
            $msgs[] = 'falta doc ine en required_docs';
        } else {
            $msgs[] = 'INE configurado';
        }
        if (!CheckoutRequirements::studentDocsGateEnabled($product)) {
            $ok = false;
            $msgs[] = 'gate de aprobación desactivado';
        } else {
            $msgs[] = 'gate activo';
        }
        $cfg = CheckoutRequirements::config($product);
        $mode = ExamScheduleService::normalizeMode((string) (($cfg['schedule']['mode'] ?? 'window')));
        if ($uksOnlineOk) {
            $msgs[] = "agenda actual={$mode} (online OK hasta cutover presencial)";
        } else {
            $msgs[] = "agenda={$mode}";
        }

        return [$ok, $msgs];
    }

    /**
     * @param array<string, mixed> $product
     * @return array{0:bool,1:list<string>}
     */
    private function expectCambridgeFixed(array $product): array
    {
        [$ok, $msgs] = $this->expectBeforePaymentIne($product, false);
        $cfg = CheckoutRequirements::config($product);
        $mode = ExamScheduleService::normalizeMode((string) (($cfg['schedule']['mode'] ?? '')));
        $schedule = is_array($cfg['schedule'] ?? null) ? $cfg['schedule'] : [];
        if (!in_array($mode, [ExamScheduleService::MODE_DATED_LIST, ExamScheduleService::MODE_VENUE_SCHEDULES], true)) {
            $ok = false;
            $msgs[] = "agenda={$mode} (esperado dated_list o venue_schedules)";
        } else {
            $msgs[] = "agenda sede-ready ({$mode})";
        }
        $venues = 0;
        if ($mode === ExamScheduleService::MODE_VENUE_SCHEDULES) {
            $venues = count(VenueScheduleEngine::normalizeVenues($schedule));
        } else {
            $svc = new ExamScheduleService();
            $venues = count($svc->openVenues($product));
        }
        if ($venues < 1) {
            // No es fallo duro: admin puede cargar sedes el día del cutover.
            $msgs[] = 'aviso: aún no hay sedes/convocatorias cargadas (cargar antes de QA sede A vs B)';
        } else {
            $msgs[] = "sedes/convocatorias visibles: {$venues}";
        }

        return [$ok, $msgs];
    }

    /**
     * @param array<string, mixed> $product
     * @return array{0:bool,1:list<string>}
     */
    private function expectCenni(array $product): array
    {
        $msgs = [];
        $ok = true;
        $timing = CheckoutRequirements::studentDocsTiming($product);
        if ($timing !== 'after_payment') {
            $ok = false;
            $msgs[] = "timing={$timing} (esperado after_payment)";
        } else {
            $msgs[] = 'timing after_payment';
        }
        $docs = CheckoutRequirements::studentDocsForProduct($product);
        $codes = array_column($docs, 'code');
        foreach (['ine', 'curp', 'solicitud', 'certificado_constancia'] as $need) {
            if (!in_array($need, $codes, true)) {
                $ok = false;
                $msgs[] = "falta doc {$need}";
            }
        }
        if ($ok) {
            $msgs[] = 'paquete docs CENNI completo (' . count($docs) . ')';
        }
        if (!CheckoutRequirements::studentDocsGateEnabled($product)) {
            $ok = false;
            $msgs[] = 'gate desactivado';
        } else {
            $msgs[] = 'gate activo';
        }
        $before = CheckoutRequirements::docsForProduct($product);
        if ($before !== []) {
            $ok = false;
            $msgs[] = 'required_docs debería estar vacío en CENNI';
        }

        return [$ok, $msgs];
    }

    /** @return array<string, mixed> */
    private function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
