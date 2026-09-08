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
    public const ACTION_SEND_MAIL = 'send_mail';
    public const ACTION_EXAM_ACCESS = 'exam_access';
    public const ACTION_ADVANCE = 'advance';

    public const ACTIONS = [
        self::ACTION_NONE => 'Solo progreso (sin botón)',
        self::ACTION_CONFIRM_PAYMENT => 'Confirmar pago',
        self::ACTION_SEND_MAIL => 'Enviar correo (plantilla)',
        self::ACTION_EXAM_ACCESS => 'Capturar folio/clave y notificar',
        self::ACTION_ADVANCE => 'Avanzar / marcar hecho',
    ];

    /**
     * Acciones configurables en el editor de pasos.
     * Confirmar pago y capturar folio se resuelven solos en Operación
     * según el estado del caso (no se eligen aquí).
     */
    public const ACTIONS_EDITABLE = [
        self::ACTION_NONE => 'Solo progreso (sin botón)',
        self::ACTION_SEND_MAIL => 'Enviar correo (plantilla)',
        self::ACTION_ADVANCE => 'Avanzar / marcar hecho',
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

        // Orden: primero confirm_payment, luego el resto por sort
        usort($merged, static function (array $a, array $b): int {
            $prio = static function (array $s): int {
                return match ((string) ($s['action'] ?? '')) {
                    self::ACTION_CONFIRM_PAYMENT => 0,
                    self::ACTION_SEND_MAIL => 1,
                    self::ACTION_EXAM_ACCESS => 2,
                    default => 5,
                };
            };

            return $prio($a) <=> $prio($b) ?: ((int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
        });

        $buttons = [];
        foreach ($merged as $step) {
            if (empty($step['ops_button'])) {
                continue;
            }
            $action = (string) ($step['action'] ?? self::ACTION_NONE);
            if ($action === self::ACTION_NONE) {
                continue;
            }
            $done = self::isActionDone($action, $row, $step);
            $label = trim((string) ($step['ops_label'] ?? '')) !== ''
                ? (string) $step['ops_label']
                : (string) ($step['label'] ?? $step['code']);
            if ($done) {
                $label = match ($action) {
                    self::ACTION_SEND_MAIL => (str_starts_with(mb_strtolower($label), 'reenviar') ? $label : 'Reenviar · ' . $label),
                    self::ACTION_EXAM_ACCESS => 'Reenviar accesos',
                    self::ACTION_ADVANCE => $label . ' (hecho)',
                    default => $label,
                };
            }
            $buttons[] = [
                'code' => (string) $step['code'],
                'label' => $label,
                'action' => $action,
                'email' => is_array($step['email'] ?? null) ? $step['email'] : [],
                'admin_only' => !empty($step['admin_only']),
                'done' => $done,
            ];
        }

        // Fallbacks si el grupo aún no configuró step_defs ricos
        $actions = array_column($buttons, 'action');
        $purchaseStatus = (string) ($row['purchase_status'] ?? '');
        $paymentPending = in_array($purchaseStatus, ['awaiting_payment', 'payment_review', 'draft', 'awaiting_docs'], true);
        $isPaid = $purchaseStatus === 'paid';

        if (!in_array(self::ACTION_CONFIRM_PAYMENT, $actions, true) && $paymentPending) {
            array_unshift($buttons, [
                'code' => 'confirm_pago',
                'label' => 'Confirmar pago',
                'action' => self::ACTION_CONFIRM_PAYMENT,
                'email' => [],
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
                    'admin_only' => true,
                    'done' => $done,
                ];
            }
        }

        // Si el pago no está confirmado, solo mostrar Confirmar pago (no solicitud/accesos/avance).
        if ($paymentPending) {
            $buttons = array_values(array_filter(
                $buttons,
                static fn (array $b): bool => ($b['action'] ?? '') === self::ACTION_CONFIRM_PAYMENT
            ));
        } else {
            // Pago confirmado: no mostrar botón de confirmar pago.
            $buttons = array_values(array_filter(
                $buttons,
                static fn (array $b): bool => ($b['action'] ?? '') !== self::ACTION_CONFIRM_PAYMENT
            ));
        }

        // Una sola acción por tipo (evita "Confirmar pago" duplicado amarillo/azul).
        $seenActions = [];
        $unique = [];
        foreach ($buttons as $btn) {
            $action = (string) ($btn['action'] ?? '');
            if ($action === self::ACTION_SEND_MAIL) {
                // Varios envíos de correo por paso distintos sí pueden coexistir.
                $key = $action . ':' . (string) ($btn['code'] ?? '');
            } else {
                $key = $action;
            }
            if (isset($seenActions[$key])) {
                continue;
            }
            $seenActions[$key] = true;
            if (!array_key_exists('done', $btn)) {
                $btn['done'] = false;
            }
            $unique[] = $btn;
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $step
     */
    public static function isActionDone(string $action, array $row, array $step = []): bool
    {
        return match ($action) {
            self::ACTION_CONFIRM_PAYMENT => !in_array(
                (string) ($row['purchase_status'] ?? ''),
                ['awaiting_payment', 'payment_review', 'draft', 'awaiting_docs'],
                true
            ),
            self::ACTION_SEND_MAIL => self::isMailStepDone($row, $step),
            self::ACTION_EXAM_ACCESS => trim((string) ($row['folio'] ?? '')) !== ''
                && trim((string) ($row['access_key'] ?? '')) !== '',
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
            $defs[$code] = self::normalizeDef($code, [
                'code' => $code,
                'label' => (string) ($row['label'] ?? $code),
                'actor' => (string) ($row['actor'] ?? 'admin'),
                'admin_only' => !empty($row['admin_only']),
                'ops_button' => !empty($row['ops_button']),
                'ops_label' => trim((string) ($row['ops_label'] ?? '')),
                'action' => (string) ($row['action'] ?? self::ACTION_NONE),
                'email' => [
                    'enabled' => !empty($row['email_enabled']),
                    'trigger' => (string) ($row['email_trigger'] ?? 'admin'),
                    'template_code' => trim((string) ($row['email_template'] ?? '')),
                    'audience' => (string) ($row['email_audience'] ?? 'student'),
                    'to' => trim((string) ($row['email_to'] ?? '')),
                    'cc' => trim((string) ($row['email_cc'] ?? '')),
                ],
            ]);
        }

        $config['step_defs'] = $defs;

        // Mantener provider_request sincronizado para runtime existente
        $providerStep = null;
        foreach ($defs as $def) {
            if (($def['action'] ?? '') === self::ACTION_SEND_MAIL
                && (($def['email']['audience'] ?? '') === 'provider')
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
        if (!isset(self::ACTIONS[$action])) {
            $action = self::ACTION_NONE;
        }
        $emailRaw = is_array($row['email'] ?? null) ? $row['email'] : [];
        $trigger = (string) ($emailRaw['trigger'] ?? $row['email_trigger'] ?? 'admin');
        if (!in_array($trigger, ['admin', 'auto'], true)) {
            $trigger = 'admin';
        }
        $audience = (string) ($emailRaw['audience'] ?? $row['email_audience'] ?? 'student');
        if (!in_array($audience, ['student', 'provider'], true)) {
            $audience = 'student';
        }

        return [
            'code' => $code,
            'label' => trim((string) ($row['label'] ?? $code)),
            'actor' => in_array((string) ($row['actor'] ?? 'admin'), ['system', 'admin', 'student', 'partner', 'provider'], true)
                ? (string) ($row['actor'] ?? 'admin')
                : 'admin',
            'admin_only' => !empty($row['admin_only']),
            'ops_button' => !empty($row['ops_button']),
            'ops_label' => trim((string) ($row['ops_label'] ?? '')),
            'action' => $action,
            'email' => [
                'enabled' => !empty($emailRaw['enabled']) || !empty($row['email_enabled']),
                'trigger' => $trigger,
                'template_code' => trim((string) ($emailRaw['template_code'] ?? $row['email_template'] ?? '')),
                'audience' => $audience,
                'to' => trim((string) ($emailRaw['to'] ?? $row['email_to'] ?? '')),
                'cc' => trim((string) ($emailRaw['cc'] ?? $row['email_cc'] ?? '')),
            ],
        ];
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?? '';

        return trim($code, '_');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $step
     */
    private static function isMailStepDone(array $row, array $step): bool
    {
        $extra = self::decodeExtra($row);
        $audience = (string) (($step['email']['audience'] ?? '') ?: 'student');
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
