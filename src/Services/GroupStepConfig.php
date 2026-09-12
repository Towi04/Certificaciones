<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Config unificada de pasos por grupo (progreso + correo + botón ops).
 *
 * Se guarda en product_groups.config_json.step_defs[code].
 * Migración lazy desde provider_request + emails.on_steps.
 */
final class GroupStepConfig
{
    public const ACTION_NONE = 'none';
    public const ACTION_CONFIRM_PAYMENT = 'confirm_payment';
    public const ACTION_CONFIRM_PAYMENT_POPUP = 'confirm_payment_popup';
    public const ACTION_SEND_MAIL = 'send_mail';
    public const ACTION_EXAM_ACCESS = 'exam_access';
    public const ACTION_ADVANCE = 'advance';
    public const ACTION_EDIT_EXAM = 'edit_exam';
    public const ACTION_EDIT_STUDENT = 'edit_student';
    public const ACTION_DOWNLOAD_CSV = 'download_csv';
    /** @deprecated Migración lazy → send_mail + requires_results */
    public const ACTION_SEND_RESULTS = 'send_results';

    public const ACTIONS = [
        self::ACTION_NONE => 'Solo progreso (sin botón)',
        self::ACTION_SEND_MAIL => 'Enviar correo (plantilla)',
        self::ACTION_DOWNLOAD_CSV => 'Descargar CSV (plantilla)',
        self::ACTION_ADVANCE => 'Avanzar / marcar hecho',
        self::ACTION_CONFIRM_PAYMENT_POPUP => 'Confirmar pago (ver comprobante)',
        // Legado (ya no se eligen en el editor; se siguen ejecutando si existen).
        self::ACTION_SEND_RESULTS => 'Enviar resultados / cancelación',
        self::ACTION_CONFIRM_PAYMENT => 'Confirmar pago (legado)',
        self::ACTION_EXAM_ACCESS => 'Capturar folio/clave (legado)',
        self::ACTION_EDIT_EXAM => 'Editar / reagendar examen (legado)',
        self::ACTION_EDIT_STUDENT => 'Editar datos del alumno (legado)',
    ];

    /**
     * Acciones del editor de grupo. El correo y los datos los define la plantilla/paso,
     * no acciones especiales en código.
     */
    public const ACTIONS_EDITABLE = [
        self::ACTION_NONE => 'Solo progreso (sin botón)',
        self::ACTION_SEND_MAIL => 'Enviar correo (plantilla)',
        self::ACTION_DOWNLOAD_CSV => 'Descargar CSV (plantilla)',
        self::ACTION_ADVANCE => 'Avanzar / marcar hecho',
        self::ACTION_CONFIRM_PAYMENT_POPUP => 'Confirmar pago (ver comprobante)',
    ];

    /** Iconos disponibles para botones de Operación (clave = icon()). */
    public const OPS_ICONS = [
        'mail' => 'Correo',
        'send' => 'Enviar',
        'calendar' => 'Calendario / reagendar',
        'download' => 'Descargar',
        'dollar' => 'Pago / $',
        'factory' => 'Proveedor / fábrica',
        'document' => 'Documento / resultados',
        'award' => 'Certificado / logro',
        'key' => 'Accesos / folio',
        'user' => 'Alumno',
        'link' => 'Enlace',
        'ban' => 'Cancelación',
        'advance' => 'Avanzar',
        'check' => 'Hecho / check',
        'upload' => 'Subir archivo',
        'clock' => 'Pendiente',
    ];

    /** Mapeo de acciones legadas → acción editable al re-guardar el grupo. */
    public const LEGACY_ACTION_MAP = [
        self::ACTION_SEND_RESULTS => self::ACTION_SEND_MAIL,
        self::ACTION_CONFIRM_PAYMENT => self::ACTION_CONFIRM_PAYMENT_POPUP,
        self::ACTION_EXAM_ACCESS => self::ACTION_SEND_MAIL,
        self::ACTION_EDIT_EXAM => self::ACTION_ADVANCE,
        self::ACTION_EDIT_STUDENT => self::ACTION_ADVANCE,
    ];

    /**
     * @param array<string, mixed> $config config ya mergeado (grupo+producto)
     * @return array<string, array<string, mixed>> keyed by step code
     */
    public static function defsFromConfig(array $config): array
    {
        $defs = [];
        $raw = is_array($config['step_defs'] ?? null) ? $config['step_defs'] : [];
        foreach ($raw as $code => $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = self::normalizeCode(is_string($code) ? $code : (string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $defs[$code] = self::normalizeDef($code, $row);
        }

        // Migración lazy: provider_request → paso send_mail
        $pr = is_array($config['provider_request'] ?? null) ? $config['provider_request'] : [];
        if (!empty($pr['enabled'])) {
            $step = self::normalizeCode((string) ($pr['step_code'] ?? 'solicitud_proveedor'));
            if ($step === '') {
                $step = 'solicitud_proveedor';
            }
            if (!isset($defs[$step])) {
                $defs[$step] = self::normalizeDef($step, [
                    'code' => $step,
                    'label' => 'Solicitud a proveedor',
                    'admin_only' => true,
                    'ops_button' => true,
                    'ops_label' => 'Enviar solicitud',
                    'action' => self::ACTION_SEND_MAIL,
                    'email' => [
                        'enabled' => true,
                        'trigger' => !empty($pr['auto_send_on_payment']) ? 'auto' : 'admin',
                        'template_code' => (string) ($pr['mail_template_code'] ?? ''),
                        'audience' => 'provider',
                        'to' => (string) ($pr['to'] ?? ''),
                        'cc' => (string) ($pr['cc'] ?? ''),
                    ],
                ]);
            } else {
                // Completar huecos sin pisar lo ya definido en step_defs
                $defs[$step]['ops_button'] = $defs[$step]['ops_button'] || true;
                if (($defs[$step]['action'] ?? self::ACTION_NONE) === self::ACTION_NONE) {
                    $defs[$step]['action'] = self::ACTION_SEND_MAIL;
                }
                if (empty($defs[$step]['email']['template_code']) && !empty($pr['mail_template_code'])) {
                    $defs[$step]['email']['template_code'] = (string) $pr['mail_template_code'];
                }
                if (($defs[$step]['email']['audience'] ?? '') === '') {
                    $defs[$step]['email']['audience'] = 'provider';
                }
                $defs[$step]['admin_only'] = true;
            }
        }

        // Migración lazy: emails.on_steps
        $emails = GroupEmailAutomation::normalize($config['emails'] ?? null);
        foreach ($emails['on_steps'] as $rule) {
            $step = self::normalizeCode((string) ($rule['step_code'] ?? ''));
            $tpl = trim((string) ($rule['template_code'] ?? ''));
            if ($step === '' || $tpl === '') {
                continue;
            }
            if (!isset($defs[$step])) {
                $defs[$step] = self::normalizeDef($step, [
                    'code' => $step,
                    'admin_only' => ($rule['audience'] ?? 'student') === 'provider',
                    'ops_button' => ($rule['mode'] ?? 'admin') === 'admin',
                    'ops_label' => 'Enviar correo',
                    'action' => self::ACTION_SEND_MAIL,
                    'email' => [
                        'enabled' => true,
                        'trigger' => (string) ($rule['mode'] ?? 'admin'),
                        'template_code' => $tpl,
                        'audience' => (string) ($rule['audience'] ?? 'student'),
                    ],
                ]);
            } elseif (empty($defs[$step]['email']['enabled'])) {
                $defs[$step]['email'] = [
                    'enabled' => true,
                    'trigger' => (string) ($rule['mode'] ?? 'admin'),
                    'template_code' => $tpl,
                    'audience' => (string) ($rule['audience'] ?? 'student'),
                    'to' => '',
                    'cc' => '',
                ];
                if (($rule['mode'] ?? '') === 'admin') {
                    $defs[$step]['ops_button'] = true;
                    if (($defs[$step]['action'] ?? self::ACTION_NONE) === self::ACTION_NONE) {
                        $defs[$step]['action'] = self::ACTION_SEND_MAIL;
                    }
                }
            }
        }

        return $defs;
    }

    /**
     * Une defs con filas de pipeline_steps (orden/label/actor de DB).
     *
     * @param list<array<string, mixed>> $pipelineSteps
     * @param array<string, array<string, mixed>> $defs
     * @return list<array<string, mixed>>
     */
    public static function mergePipelineSteps(array $pipelineSteps, array $defs): array
    {
        $out = [];
        foreach ($pipelineSteps as $step) {
            $code = self::normalizeCode((string) ($step['code'] ?? ''));
            $def = $defs[$code] ?? self::normalizeDef($code, []);
            $out[] = array_merge($def, [
                'code' => $code,
                'label' => (string) ($step['label'] ?? $def['label'] ?? $code),
                'actor' => (string) ($step['actor'] ?? $def['actor'] ?? 'admin'),
                'is_terminal' => !empty($step['is_terminal']),
                'sort_order' => (int) ($step['sort_order'] ?? count($out)),
            ]);
        }

        return $out;
    }

    /**
     * Botones pendientes para una fila del tablero ops.
     *
     * @param array<string, mixed> $row tracking anotado (con config_json / group_config_json)
     * @param list<array<string, mixed>> $pipelineSteps
     * @return list<array<string, mixed>>
     */
    public static function pendingOpsButtons(array $row, array $pipelineSteps = []): array
    {
        $config = CheckoutRequirements::config([
            'config_json' => $row['config_json'] ?? null,
            'group_config_json' => $row['group_config_json'] ?? null,
        ]);
        $defs = self::defsFromConfig($config);
        $merged = $pipelineSteps !== []
            ? self::mergePipelineSteps($pipelineSteps, $defs)
            : array_values($defs);

        // ¿El grupo ya definió botones en Operación? Si sí, no inyectar legacies.
        $hasConfiguredOps = false;
        foreach ($merged as $step) {
            if (!empty($step['ops_button'])
                && (string) ($step['action'] ?? self::ACTION_NONE) !== self::ACTION_NONE
            ) {
                $hasConfiguredOps = true;
                break;
            }
        }

        // Orden: primero confirm_payment, luego el resto por sort
        usort($merged, static function (array $a, array $b): int {
            $prio = static function (array $s): int {
                return match (self::effectiveOpsAction($s)) {
                    self::ACTION_CONFIRM_PAYMENT, self::ACTION_CONFIRM_PAYMENT_POPUP => 0,
                    self::ACTION_SEND_MAIL => 1,
                    self::ACTION_DOWNLOAD_CSV => 2,
                    self::ACTION_EXAM_ACCESS => 3,
                    self::ACTION_EDIT_EXAM => 4,
                    self::ACTION_EDIT_STUDENT => 5,
                    self::ACTION_ADVANCE => 6,
                    default => 9,
                };
            };

            return $prio($a) <=> $prio($b) ?: ((int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
        });

        $buttons = [];
        foreach ($merged as $step) {
            if (empty($step['ops_button'])) {
                continue;
            }
            $action = self::effectiveOpsAction($step);
            if ($action === self::ACTION_NONE) {
                continue;
            }
            $done = self::isActionDone($action, $row, $step);
            $label = trim((string) ($step['ops_label'] ?? '')) !== ''
                ? (string) $step['ops_label']
                : (string) ($step['label'] ?? $step['code']);
            $email = is_array($step['email'] ?? null) ? $step['email'] : [];
            $csv = is_array($step['csv'] ?? null) ? $step['csv'] : [];
            $audience = self::audienceFromEmail($email);
            $email['audience'] = $audience;
            $collectExam = self::stepCollectsExamSchedule($step);
            if ($done && !$collectExam && $action !== self::ACTION_DOWNLOAD_CSV) {
                $label = match ($action) {
                    self::ACTION_SEND_MAIL => (str_starts_with(mb_strtolower($label), 'reenviar') ? $label : 'Reenviar · ' . $label),
                    self::ACTION_EXAM_ACCESS => (str_starts_with(mb_strtolower($label), 'reenviar') ? $label : 'Reenviar · ' . $label),
                    self::ACTION_EDIT_STUDENT => $label . ' (actualizado)',
                    self::ACTION_ADVANCE => $label . ' (hecho)',
                    default => $label,
                };
            }
            $rescheduleCount = $collectExam
                ? \App\Services\TrackingService::examRescheduleCountFromTracking($row)
                : 0;
            $opsIcon = self::resolveOpsIcon($step, $action);
            $resultsReady = true;
            $resultsBlocked = '';
            if ($action === self::ACTION_SEND_MAIL) {
                $delivery = ResultsDeliveryService::fromConfig($config);
                $tpl = trim((string) ($email['template_code'] ?? ''));
                if (ResultsDeliveryService::stepRequiresResults($step, $delivery, $tpl)) {
                    $resultsReady = ResultsDeliveryService::isReady($row, $delivery);
                    $resultsBlocked = $resultsReady ? '' : ResultsDeliveryService::blockedReason($row, $delivery);
                }
            }
            $buttons[] = [
                'code' => (string) $step['code'],
                'label' => $label,
                'action' => $action,
                'email' => $email,
                'csv' => $csv,
                'ops_icon' => $opsIcon,
                'audience' => $audience,
                'admin_only' => !empty($step['admin_only']),
                'done' => $done,
                'collect_exam' => $collectExam,
                'reschedule_count' => $rescheduleCount,
                'results_ready' => $resultsReady,
                'results_blocked' => $resultsBlocked,
                'requires_results' => !empty($step['requires_results']),
                ...self::opsStatusForButton($row, $step, $action, $done, $collectExam, $audience),
            ];
        }

        $actions = array_column($buttons, 'action');
        $purchaseStatus = (string) ($row['purchase_status'] ?? '');
        $paymentPending = in_array($purchaseStatus, ['awaiting_payment', 'payment_review', 'draft', 'awaiting_docs'], true);
        $isPaid = $purchaseStatus === 'paid';

        $hasConfirmPayment = in_array(self::ACTION_CONFIRM_PAYMENT, $actions, true)
            || in_array(self::ACTION_CONFIRM_PAYMENT_POPUP, $actions, true);

        // Solo fallbacks si el grupo aún no configuró botones de Operación.
        if (!$hasConfiguredOps) {
            if (!$hasConfirmPayment && $paymentPending) {
                array_unshift($buttons, [
                    'code' => 'confirm_pago',
                    'label' => 'Confirmar pago',
                    'action' => self::ACTION_CONFIRM_PAYMENT_POPUP,
                    'email' => [],
                    'ops_icon' => self::defaultOpsIcon(self::ACTION_CONFIRM_PAYMENT_POPUP),
                    'audience' => 'student',
                    'admin_only' => false,
                    'done' => false,
                ]);
            }
            $pipeline = (string) ($row['pipeline_code'] ?? '');
            $isElet = $pipeline === 'elet_uks' || (string) ($row['product_code'] ?? '') === 'ELET-UKS';
            if ($isElet && !in_array(self::ACTION_EXAM_ACCESS, $actions, true) && $isPaid) {
                $examStep = $defs['codigos'] ?? null;
                $synthetic = [
                    'code' => 'codigos',
                    'label' => 'Enviar folio/clave',
                    'action' => self::ACTION_EXAM_ACCESS,
                    'email' => is_array($examStep['email'] ?? null) ? $examStep['email'] : [],
                    'ops_icon' => self::defaultOpsIcon(self::ACTION_EXAM_ACCESS),
                    'audience' => 'student',
                    'admin_only' => true,
                ];
                $done = self::isActionDone(self::ACTION_EXAM_ACCESS, $row, $synthetic);
                $synthetic['done'] = $done;
                if ($done) {
                    $synthetic['label'] = 'Reenviar accesos';
                }
                $buttons[] = $synthetic;
            }
            if (!in_array(self::ACTION_SEND_MAIL, $actions, true) && $isPaid) {
                $pr = [];
                if (!empty($row['extra_json']) && is_string($row['extra_json'])) {
                    $decoded = json_decode($row['extra_json'], true);
                    $pr = is_array($decoded['provider_request'] ?? null) ? $decoded['provider_request'] : [];
                } elseif (is_array($row['extra_json'] ?? null)) {
                    $pr = is_array($row['extra_json']['provider_request'] ?? null)
                        ? $row['extra_json']['provider_request']
                        : [];
                }
                if (!empty($pr['required']) || !empty($pr['enabled']) || trim((string) ($pr['sent_at'] ?? '')) !== '') {
                    $sent = trim((string) ($pr['sent_at'] ?? ''));
                    $done = $sent !== '' && $sent !== 'null';
                    $buttons[] = [
                        'code' => (string) ($pr['step_code'] ?? 'solicitud_proveedor'),
                        'label' => $done ? 'Reenviar solicitud' : 'Enviar solicitud',
                        'action' => self::ACTION_SEND_MAIL,
                        'email' => ['audience' => 'provider', 'enabled' => true],
                        'ops_icon' => self::defaultOpsIcon(self::ACTION_SEND_MAIL, [
                            'email' => ['audience' => 'provider'],
                        ]),
                        'audience' => 'provider',
                        'admin_only' => true,
                        'done' => $done,
                    ];
                }
            }
            $actions = array_column($buttons, 'action');
            $hasConfirmPayment = in_array(self::ACTION_CONFIRM_PAYMENT, $actions, true)
                || in_array(self::ACTION_CONFIRM_PAYMENT_POPUP, $actions, true);
        } elseif ($paymentPending && !$hasConfirmPayment) {
            // Grupo configurado pero sin «Confirmar pago»: inyectar solo ese fallback mínimo.
            array_unshift($buttons, [
                'code' => 'confirm_pago',
                'label' => 'Confirmar pago',
                'action' => self::ACTION_CONFIRM_PAYMENT_POPUP,
                'email' => [],
                'ops_icon' => self::defaultOpsIcon(self::ACTION_CONFIRM_PAYMENT_POPUP),
                'audience' => 'student',
                'admin_only' => false,
                'done' => false,
            ]);
        }

        $isConfirmPaymentAction = static fn (string $a): bool => in_array($a, [
            self::ACTION_CONFIRM_PAYMENT,
            self::ACTION_CONFIRM_PAYMENT_POPUP,
        ], true);

        // Si el pago no está confirmado, solo Confirmar pago.
        if ($paymentPending) {
            $buttons = array_values(array_filter(
                $buttons,
                static fn (array $b): bool => $isConfirmPaymentAction((string) ($b['action'] ?? ''))
            ));
        } else {
            // Pago confirmado: ocultar confirmar pago.
            $buttons = array_values(array_filter(
                $buttons,
                static fn (array $b): bool => !$isConfirmPaymentAction((string) ($b['action'] ?? ''))
            ));
        }

        // Varias acciones del mismo tipo pueden coexistir (un botón por paso).
        $seenActions = [];
        $unique = [];
        foreach ($buttons as $btn) {
            $action = (string) ($btn['action'] ?? '');
            $key = $action . ':' . (string) ($btn['code'] ?? '');
            if ($isConfirmPaymentAction($action)) {
                $key = 'confirm_payment'; // solo un confirmar pago
            }
            if (isset($seenActions[$key])) {
                continue;
            }
            $seenActions[$key] = true;
            if (!array_key_exists('done', $btn)) {
                $btn['done'] = false;
            }
            if (!isset($btn['status'])) {
                $btn = array_merge(
                    $btn,
                    self::opsStatusForButton(
                        $row,
                        ['code' => (string) ($btn['code'] ?? ''), 'email' => $btn['email'] ?? []],
                        (string) ($btn['action'] ?? ''),
                        !empty($btn['done']),
                        !empty($btn['collect_exam']),
                        (string) ($btn['audience'] ?? 'student')
                    )
                );
            }
            $unique[] = $btn;
        }

        return $unique;
    }

    /**
     * Interpreta la acción real del botón en Operación.
     * Compatibilidad: configs viejas usaban «Avanzar» para confirmar pago
     * o «Enviar correo» al alumno con plantilla de accesos.
     *
     * @param array<string, mixed> $step
     */
    public static function effectiveOpsAction(array $step): string
    {
        $action = (string) ($step['action'] ?? self::ACTION_NONE);
        if ($action === self::ACTION_SEND_RESULTS) {
            $action = self::ACTION_SEND_MAIL;
        }
        if ($action === self::ACTION_CONFIRM_PAYMENT_POPUP) {
            // Misma semántica operativa que confirmar pago; el UI abre el popup.
            return self::ACTION_CONFIRM_PAYMENT_POPUP;
        }
        if (!isset(self::ACTIONS[$action])) {
            $action = self::ACTION_NONE;
        }

        $blob = mb_strtolower(trim(
            (string) ($step['code'] ?? '') . ' '
            . (string) ($step['ops_label'] ?? '') . ' '
            . (string) ($step['label'] ?? '')
        ));

        if ($action === self::ACTION_ADVANCE
            && preg_match('/confirm.*pago|pago.*confirm|confirmaci[oó]n\s+de\s+pago/u', $blob) === 1
        ) {
            return self::ACTION_CONFIRM_PAYMENT_POPUP;
        }

        if ($action === self::ACTION_SEND_MAIL) {
            // Reagendar/fecha: no convertir a folio/clave aunque el texto mencione «examen».
            if (self::stepCollectsExamSchedule($step)) {
                return self::ACTION_SEND_MAIL;
            }
            $email = is_array($step['email'] ?? null) ? $step['email'] : [];
            $audience = self::audienceFromEmail($email);
            $tpl = mb_strtolower((string) ($email['template_code'] ?? ''));
            if ($audience === 'student'
                && (
                    str_contains($tpl, 'exam_access')
                    || str_contains($tpl, 'acceso')
                    || preg_match('/folio|clave|acceso/u', $blob) === 1
                )
            ) {
                return self::ACTION_EXAM_ACCESS;
            }
        }

        return $action;
    }

    /**
     * ¿Este paso captura/reagenda fecha de examen en Operación?
     * (por acción legada o por etiqueta/código/plantilla).
     *
     * @param array<string, mixed> $step
     */
    public static function stepCollectsExamSchedule(array $step): bool
    {
        $action = (string) ($step['action'] ?? self::ACTION_NONE);
        if ($action === self::ACTION_EDIT_EXAM) {
            return true;
        }

        $tpl = mb_strtolower(trim((string) (($step['email']['template_code'] ?? ''))));
        if (
            MailTemplateService::rescheduleTemplateHeuristic($tpl)
            || str_contains($tpl, 'exam_schedule')
            || str_contains($tpl, 'examen_fecha')
        ) {
            return true;
        }

        $blob = mb_strtolower(trim(
            (string) ($step['code'] ?? '') . ' '
            . (string) ($step['ops_label'] ?? '') . ' '
            . (string) ($step['label'] ?? '')
        ));
        // Botón/etiqueta de reagendar: siempre captura fecha (nunca solicitud a proveedor).
        if (preg_match('/reagend|re-?agend|reschedule/u', $blob) === 1) {
            return true;
        }
        if (preg_match('/fecha.*exam|exam.*fecha|examen.*hora|hora.*examen|programar.*exam/u', $blob) === 1) {
            // Evitar falsos positivos de «solicitud examen» a proveedor.
            $email = is_array($step['email'] ?? null) ? $step['email'] : [];
            $audience = self::audienceFromEmail($email);
            if ($audience === 'provider') {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Destinatario según la plantilla (no se pregunta en el grupo).
     *
     * @param array<string, mixed> $email
     */
    public static function audienceFromEmail(array $email): string
    {
        $tpl = trim((string) ($email['template_code'] ?? ''));
        if ($tpl !== '') {
            return MailTemplateService::audienceForTemplate($tpl);
        }

        return MailTemplateService::normalizeAudience((string) ($email['audience'] ?? 'student'));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $step
     */
    public static function isActionDone(string $action, array $row, array $step = []): bool
    {
        // Reagendar/fecha: verde si ya hay fecha, pero los controles siguen visibles siempre.
        if (self::stepCollectsExamSchedule($step) || $action === self::ACTION_EDIT_EXAM) {
            return trim((string) ($row['exam_date'] ?? '')) !== '';
        }

        return match ($action) {
            self::ACTION_CONFIRM_PAYMENT,
            self::ACTION_CONFIRM_PAYMENT_POPUP => !in_array(
                (string) ($row['purchase_status'] ?? ''),
                ['awaiting_payment', 'payment_review', 'draft', 'awaiting_docs'],
                true
            ),
            self::ACTION_SEND_MAIL,
            self::ACTION_SEND_RESULTS => self::isMailStepDone($row, $step),
            self::ACTION_DOWNLOAD_CSV => false,
            self::ACTION_EXAM_ACCESS => trim((string) ($row['folio'] ?? '')) !== ''
                && trim((string) ($row['access_key'] ?? '')) !== '',
            self::ACTION_EDIT_STUDENT => self::isAdvanceDone($row, $step),
            self::ACTION_ADVANCE => self::isAdvanceDone($row, $step),
            default => false,
        };
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @param array<string, array<string, mixed>> $defs
     * @return list<array<string, mixed>>
     */
    public static function visibleToStudent(array $steps, array $defs): array
    {
        $out = [];
        foreach ($steps as $step) {
            $code = self::normalizeCode((string) ($step['code'] ?? ''));
            $def = $defs[$code] ?? [];
            if (!empty($def['admin_only'])) {
                continue;
            }
            $out[] = $step;
        }

        return $out;
    }

    /**
     * Construye step_defs + sincroniza provider_request / emails.on_steps legacy
     * a partir del POST del formulario de grupo.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function applyFromGroupInput(array $input, array $config): array
    {
        $defs = [];
        $rawSteps = is_array($input['pipeline_steps'] ?? null) ? $input['pipeline_steps'] : [];
        foreach ($rawSteps as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = self::normalizeCode((string) ($row['code'] ?? ''));
            if ($code === '') {
                $code = self::normalizeCode((string) ($row['label'] ?? ''));
            }
            if ($code === '') {
                continue;
            }
            $base = $code;
            $n = 2;
            while (isset($defs[$code])) {
                $code = $base . '_' . $n;
                $n++;
            }
            $rawAction = (string) ($row['action'] ?? self::ACTION_NONE);
            $defs[$code] = self::normalizeDef($code, [
                'code' => $code,
                'label' => (string) ($row['label'] ?? $code),
                'actor' => (string) ($row['actor'] ?? 'admin'),
                'admin_only' => !empty($row['admin_only']),
                'ops_button' => !empty($row['ops_button']),
                'ops_label' => trim((string) ($row['ops_label'] ?? '')),
                'ops_icon' => trim((string) ($row['ops_icon'] ?? '')),
                'action' => self::LEGACY_ACTION_MAP[$rawAction] ?? $rawAction,
                'requires_results' => !empty($row['requires_results'])
                    || $rawAction === self::ACTION_SEND_RESULTS,
                'email' => [
                    'enabled' => !empty($row['email_enabled'])
                        || $rawAction === self::ACTION_SEND_RESULTS,
                    'trigger' => (string) ($row['email_trigger'] ?? 'admin'),
                    'template_code' => trim((string) ($row['email_template'] ?? '')),
                    // Destinatario lo define la plantilla; no se pide en el grupo.
                    'audience' => '',
                    'to' => trim((string) ($row['email_to'] ?? '')),
                    'cc' => trim((string) ($row['email_cc'] ?? '')),
                ],
                'csv' => [
                    'template_code' => trim((string) ($row['csv_template'] ?? '')),
                    'scope' => (string) ($row['csv_scope'] ?? 'student'),
                ],
            ]);
        }

        $config['step_defs'] = $defs;

        // Mantener provider_request sincronizado para runtime existente
        $providerStep = null;
        foreach ($defs as $def) {
            $email = is_array($def['email'] ?? null) ? $def['email'] : [];
            if (($def['action'] ?? '') === self::ACTION_SEND_MAIL
                && self::audienceFromEmail($email) === 'provider'
            ) {
                $providerStep = $def;
                break;
            }
        }
        if ($providerStep !== null && !empty($providerStep['email']['enabled'])) {
            $existing = is_array($config['provider_request'] ?? null) ? $config['provider_request'] : [];
            $config['provider_request'] = array_merge($existing, [
                'enabled' => true,
                'step_code' => (string) $providerStep['code'],
                'mail_template_code' => (string) ($providerStep['email']['template_code'] ?? ''),
                'to' => (string) ($providerStep['email']['to'] ?? ($existing['to'] ?? '')),
                'cc' => (string) ($providerStep['email']['cc'] ?? ($existing['cc'] ?? '')),
                'auto_send_on_payment' => ($providerStep['email']['trigger'] ?? '') === 'auto',
                'delivery' => 'links',
                'include_student_data' => true,
                'include_exam_schedule' => true,
                'include_reglamento' => $existing['include_reglamento'] ?? true,
                'include_payment_proof' => $existing['include_payment_proof'] ?? true,
                'require_reglamento' => $existing['require_reglamento'] ?? true,
                'require_admin_payment_proof' => $existing['require_admin_payment_proof'] ?? false,
                'auto_send_on_admin_proof' => $existing['auto_send_on_admin_proof'] ?? false,
            ]);
        }

        // on_steps auto desde defs
        $onSteps = [];
        foreach ($defs as $def) {
            $email = is_array($def['email'] ?? null) ? $def['email'] : [];
            if (empty($email['enabled']) || trim((string) ($email['template_code'] ?? '')) === '') {
                continue;
            }
            if (($email['trigger'] ?? '') !== 'auto') {
                continue;
            }
            $onSteps[] = [
                'step_code' => (string) $def['code'],
                'template_code' => (string) $email['template_code'],
                'mode' => 'auto',
                'audience' => (string) ($email['audience'] ?? 'student'),
            ];
        }
        $emails = GroupEmailAutomation::normalize($config['emails'] ?? null);
        $emails['on_steps'] = $onSteps;

        // Sincronizar correo de accesos con el paso exam_access (fuente de verdad en Progreso).
        $examAccessFromStep = null;
        $hasExamAccessStep = false;
        foreach ($defs as $def) {
            if ((string) ($def['action'] ?? '') !== self::ACTION_EXAM_ACCESS) {
                continue;
            }
            $hasExamAccessStep = true;
            $email = is_array($def['email'] ?? null) ? $def['email'] : [];
            $tpl = trim((string) ($email['template_code'] ?? ''));
            if (!empty($email['enabled']) && $tpl !== '') {
                $examAccessFromStep = [
                    'enabled' => true,
                    'template_code' => $tpl,
                    'mode' => (($email['trigger'] ?? '') === 'auto') ? 'auto' : 'admin',
                ];
                break;
            }
        }
        if ($examAccessFromStep !== null) {
            $emails[GroupEmailAutomation::KEY_EXAM_ACCESS] = array_merge(
                $emails[GroupEmailAutomation::KEY_EXAM_ACCESS],
                $examAccessFromStep
            );
        } elseif ($hasExamAccessStep) {
            $emails[GroupEmailAutomation::KEY_EXAM_ACCESS]['enabled'] = false;
        }
        $config['emails'] = $emails;

        return $config;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeDef(string $code, array $row): array
    {
        $action = (string) ($row['action'] ?? self::ACTION_NONE);
        $wasSendResults = $action === self::ACTION_SEND_RESULTS;
        if ($wasSendResults) {
            $action = self::ACTION_SEND_MAIL;
        }
        if (!isset(self::ACTIONS[$action])) {
            $action = self::ACTION_NONE;
        }
        $emailRaw = is_array($row['email'] ?? null) ? $row['email'] : [];
        $trigger = (string) ($emailRaw['trigger'] ?? $row['email_trigger'] ?? 'admin');
        if (!in_array($trigger, ['admin', 'auto'], true)) {
            $trigger = 'admin';
        }
        $templateCode = trim((string) ($emailRaw['template_code'] ?? $row['email_template'] ?? ''));
        $audience = self::audienceFromEmail([
            'template_code' => $templateCode,
            'audience' => (string) ($emailRaw['audience'] ?? $row['email_audience'] ?? ''),
        ]);
        $csvRaw = is_array($row['csv'] ?? null) ? $row['csv'] : [];
        $csvScope = strtolower(trim((string) ($csvRaw['scope'] ?? $row['csv_scope'] ?? 'student')));
        if (!in_array($csvScope, ['student', 'exam_date'], true)) {
            $csvScope = 'student';
        }
        $csvTemplate = trim((string) ($csvRaw['template_code'] ?? $row['csv_template'] ?? ''));
        $opsIcon = strtolower(trim((string) ($row['ops_icon'] ?? '')));
        if ($opsIcon !== '' && !isset(self::OPS_ICONS[$opsIcon])) {
            $opsIcon = '';
        }

        $emailEnabled = !empty($emailRaw['enabled']) || !empty($row['email_enabled']) || $wasSendResults;
        $requiresResults = !empty($row['requires_results']) || $wasSendResults;

        return [
            'code' => $code,
            'label' => trim((string) ($row['label'] ?? $code)),
            'actor' => in_array((string) ($row['actor'] ?? 'admin'), ['system', 'admin', 'student', 'partner', 'provider'], true)
                ? (string) ($row['actor'] ?? 'admin')
                : 'admin',
            'admin_only' => !empty($row['admin_only']),
            'ops_button' => !empty($row['ops_button']),
            'ops_label' => trim((string) ($row['ops_label'] ?? '')),
            'ops_icon' => $opsIcon,
            'action' => $action,
            'requires_results' => $requiresResults,
            'email' => [
                'enabled' => $emailEnabled,
                'trigger' => $trigger,
                'template_code' => $templateCode,
                'audience' => $audience,
                'to' => trim((string) ($emailRaw['to'] ?? $row['email_to'] ?? '')),
                'cc' => trim((string) ($emailRaw['cc'] ?? $row['email_cc'] ?? '')),
            ],
            'csv' => [
                'template_code' => $csvTemplate,
                'scope' => $csvScope,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $step
     */
    public static function resolveOpsIcon(array $step, ?string $action = null): string
    {
        $action = $action ?? (string) ($step['action'] ?? self::ACTION_NONE);
        $icon = strtolower(trim((string) ($step['ops_icon'] ?? '')));
        if ($icon !== '' && isset(self::OPS_ICONS[$icon])) {
            return $icon;
        }

        return self::defaultOpsIcon($action, $step);
    }

    /**
     * @param array<string, mixed> $step
     */
    public static function defaultOpsIcon(string $action, array $step = []): string
    {
        if ($action === self::ACTION_SEND_MAIL) {
            $email = is_array($step['email'] ?? null) ? $step['email'] : [];
            if (self::audienceFromEmail($email) === 'provider') {
                return 'factory';
            }

            return 'mail';
        }

        return match ($action) {
            self::ACTION_CONFIRM_PAYMENT,
            self::ACTION_CONFIRM_PAYMENT_POPUP => 'dollar',
            self::ACTION_SEND_RESULTS => 'document',
            self::ACTION_DOWNLOAD_CSV => 'download',
            self::ACTION_EXAM_ACCESS => 'key',
            self::ACTION_EDIT_EXAM => 'calendar',
            self::ACTION_EDIT_STUDENT => 'user',
            self::ACTION_ADVANCE => 'advance',
            default => 'check',
        };
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?? '';

        return trim($code, '_');
    }

    /**
     * Estado visual del botón en Operación (excepto reagendar, que usa contador).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $step
     * @return array{status:string,status_detail:string}
     */
    private static function opsStatusForButton(
        array $row,
        array $step,
        string $action,
        bool $done,
        bool $collectExam,
        string $audience
    ): array {
        if ($collectExam || $action === self::ACTION_EDIT_EXAM) {
            return ['status' => 'none', 'status_detail' => ''];
        }

        $extra = self::decodeExtra($row);
        $code = (string) ($step['code'] ?? '');
        $error = '';

        if ($action === self::ACTION_SEND_MAIL || $action === self::ACTION_SEND_RESULTS) {
            if ($audience === 'provider') {
                $pr = is_array($extra['provider_request'] ?? null) ? $extra['provider_request'] : [];
                $error = trim((string) ($pr['last_error'] ?? ''));
            }
            // También errores de StepMailService (alumno/partner/plantillas ligeras de proveedor).
            if ($error === '') {
                $sentMap = is_array($extra['step_mail_sent'] ?? null) ? $extra['step_mail_sent'] : [];
                $entry = is_array($sentMap[$code] ?? null) ? $sentMap[$code] : [];
                $error = trim((string) ($entry['error'] ?? $entry['last_error'] ?? ''));
            }
            if ($error === '') {
                $errMap = is_array($extra['step_mail_errors'] ?? null) ? $extra['step_mail_errors'] : [];
                if (is_array($errMap[$code] ?? null)) {
                    $error = trim((string) ($errMap[$code]['error'] ?? ''));
                } elseif (is_string($errMap[$code] ?? null)) {
                    $error = trim((string) $errMap[$code]);
                }
            }
        }

        if ($error !== '' && !$done) {
            return [
                'status' => 'error',
                'status_detail' => mb_substr($error, 0, 180),
            ];
        }

        if ($done) {
            return ['status' => 'ok', 'status_detail' => 'Enviado / completado'];
        }

        return ['status' => 'pending', 'status_detail' => 'Pendiente'];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $step
     */
    private static function isMailStepDone(array $row, array $step): bool
    {
        $extra = self::decodeExtra($row);
        $email = is_array($step['email'] ?? null) ? $step['email'] : [];
        $audience = self::audienceFromEmail($email);
        $code = (string) ($step['code'] ?? '');

        if ($audience === 'provider') {
            $pr = is_array($extra['provider_request'] ?? null) ? $extra['provider_request'] : [];
            $sent = trim((string) ($pr['sent_at'] ?? ''));

            return $sent !== '' && $sent !== 'null';
        }

        $sentMap = is_array($extra['step_mail_sent'] ?? null) ? $extra['step_mail_sent'] : [];
        if ($code !== '' && !empty($sentMap[$code])) {
            return true;
        }

        // Si el caso ya pasó ese paso, consideramos el correo admin pendiente solo si aún no hay registro.
        // Para ops: si current está después del paso, ocultar botón de envío one-shot.
        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $step
     */
    private static function isAdvanceDone(array $row, array $step): bool
    {
        $extra = self::decodeExtra($row);
        $code = (string) ($step['code'] ?? '');
        $done = is_array($extra['step_done'] ?? null) ? $extra['step_done'] : [];

        return $code !== '' && !empty($done[$code]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decodeExtra(array $row): array
    {
        if (is_array($row['extra_json'] ?? null)) {
            return $row['extra_json'];
        }
        if (!empty($row['extra_json']) && is_string($row['extra_json'])) {
            $decoded = json_decode($row['extra_json'], true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
