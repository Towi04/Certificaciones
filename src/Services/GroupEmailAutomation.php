<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Automatización de correos por grupo de producto.
 *
 * Config en product_groups.config_json → emails:
 * {
 *   "student_registration": {"enabled": true, "template_code": "student_registration"},
 *   "student_payment_confirmed": {"enabled": true, "template_code": "student_payment_confirmed"},
 *   "student_exam_access": {"enabled": true, "template_code": "student_elet_exam_access", "mode": "admin"},
 *   "on_steps": [
 *     {"step_code": "resultados", "template_code": "mi_plantilla", "mode": "auto", "audience": "student"}
 *   ]
 * }
 *
 * Compatibilidad: flags booleanos legacy (payment_confirmed, exam_scheduled, payment_rejected).
 */
final class GroupEmailAutomation
{
    public const KEY_REGISTRATION = 'student_registration';
    public const KEY_PAYMENT = 'student_payment_confirmed';
    public const KEY_EXAM_ACCESS = 'student_exam_access';

    /**
     * @param array<string, mixed> $product
     * @return array{
     *   student_registration: array{enabled:bool,template_code:string},
     *   student_payment_confirmed: array{enabled:bool,template_code:string},
     *   student_exam_access: array{enabled:bool,template_code:string,mode:string},
     *   on_steps: list<array{step_code:string,template_code:string,mode:string,audience:string}>
     * }
     */
    public static function forProduct(array $product): array
    {
        $cfg = CheckoutRequirements::config($product);

        return self::normalize($cfg['emails'] ?? null);
    }

    /**
     * @param mixed $raw
     * @return array{
     *   student_registration: array{enabled:bool,template_code:string},
     *   student_payment_confirmed: array{enabled:bool,template_code:string},
     *   student_exam_access: array{enabled:bool,template_code:string,mode:string},
     *   on_steps: list<array{step_code:string,template_code:string,mode:string,audience:string}>
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        $legacyPayment = array_key_exists('payment_confirmed', $raw)
            ? (bool) $raw['payment_confirmed']
            : true;

        return [
            self::KEY_REGISTRATION => self::normalizeEvent(
                $raw[self::KEY_REGISTRATION] ?? null,
                true,
                'student_registration'
            ),
            self::KEY_PAYMENT => self::normalizeEvent(
                $raw[self::KEY_PAYMENT] ?? ($legacyPayment ? ['enabled' => true] : ['enabled' => false]),
                $legacyPayment,
                'student_payment_confirmed'
            ),
            self::KEY_EXAM_ACCESS => self::normalizeEvent(
                $raw[self::KEY_EXAM_ACCESS] ?? null,
                true,
                'student_elet_exam_access',
                'admin'
            ),
            'on_steps' => self::normalizeStepRules($raw['on_steps'] ?? []),
        ];
    }

    public static function isEnabled(array $product, string $key): bool
    {
        if ($key === self::KEY_EXAM_ACCESS) {
            return self::resolveExamAccessMail($product)['send'];
        }
        $cfg = self::forProduct($product);

        return !empty($cfg[$key]['enabled']);
    }

    public static function templateCode(array $product, string $key, string $fallback): string
    {
        if ($key === self::KEY_EXAM_ACCESS) {
            $resolved = self::resolveExamAccessMail($product);
            $code = trim($resolved['template_code']);

            return $code !== '' ? $code : $fallback;
        }
        $cfg = self::forProduct($product);
        $code = trim((string) ($cfg[$key]['template_code'] ?? ''));

        return $code !== '' ? $code : $fallback;
    }

    public static function examAccessMode(array $product): string
    {
        $cfg = self::forProduct($product);
        $mode = (string) ($cfg[self::KEY_EXAM_ACCESS]['mode'] ?? 'admin');

        return $mode === 'auto' ? 'auto' : 'admin';
    }

    /**
     * Resuelve si debe enviarse el correo de accesos y con qué plantilla.
     * Prioridad: paso exam_access en step_defs → emails.student_exam_access → default.
     *
     * @param array<string, mixed> $product
     * @return array{send:bool,template_code:string,source:string}
     */
    public static function resolveExamAccessMail(array $product): array
    {
        $full = CheckoutRequirements::config($product);
        $defs = is_array($full['step_defs'] ?? null) ? $full['step_defs'] : [];
        $hasExamStep = false;
        $stepDisabled = false;

        foreach ($defs as $def) {
            if (!is_array($def)) {
                continue;
            }
            if ((string) ($def['action'] ?? '') !== GroupStepConfig::ACTION_EXAM_ACCESS) {
                continue;
            }
            $hasExamStep = true;
            $email = is_array($def['email'] ?? null) ? $def['email'] : [];
            $tpl = trim((string) ($email['template_code'] ?? ''));
            if (!empty($email['enabled']) && $tpl !== '') {
                return [
                    'send' => true,
                    'template_code' => $tpl,
                    'source' => 'step',
                ];
            }
            if (array_key_exists('enabled', $email) && empty($email['enabled'])) {
                $stepDisabled = true;
            }
        }

        if ($hasExamStep && $stepDisabled) {
            return [
                'send' => false,
                'template_code' => 'student_elet_exam_access',
                'source' => 'step',
            ];
        }

        $emails = self::normalize($full['emails'] ?? null);
        $ev = $emails[self::KEY_EXAM_ACCESS];
        $tpl = trim((string) ($ev['template_code'] ?? 'student_elet_exam_access'));
        if ($tpl === '') {
            $tpl = 'student_elet_exam_access';
        }

        return [
            'send' => !empty($ev['enabled']),
            'template_code' => $tpl,
            'source' => 'emails',
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return list<array{step_code:string,template_code:string,mode:string,audience:string}>
     */
    public static function rulesForStep(array $product, string $stepCode): array
    {
        $stepCode = trim($stepCode);
        if ($stepCode === '') {
            return [];
        }
        $out = [];
        foreach (self::forProduct($product)['on_steps'] as $rule) {
            if ($rule['step_code'] === $stepCode) {
                $out[] = $rule;
            }
        }

        return $out;
    }

    /**
     * Envía plantillas mode=auto al entrar a un paso (alumno).
     *
     * @param array<string, mixed> $tracking
     * @param array<string, string> $vars
     */
    public static function sendAutoEmailsForStep(array $tracking, string $stepCode, array $vars = []): void
    {
        $product = [
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
            'id' => $tracking['product_id'] ?? 0,
            'name' => $tracking['product_name'] ?? '',
            'code' => $tracking['product_code'] ?? '',
        ];

        $mail = new MailTemplateService();
        $stepMail = new StepMailService();
        foreach (self::rulesForStep($product, $stepCode) as $rule) {
            if ($rule['mode'] !== 'auto') {
                continue;
            }
            $code = $rule['template_code'];
            $audience = MailTemplateService::audienceForTemplate($code);
            if ($audience === 'provider') {
                continue;
            }
            try {
                $to = $stepMail->resolveRecipient($tracking, $audience);
                $mergedVars = array_merge($stepMail->buildVars($tracking), $vars);
                if ($mail->render($code, $mergedVars) !== null) {
                    $mail->send($code, $to, $mergedVars);
                }
            } catch (\Throwable $e) {
                error_log('[Doceo] Correo automático paso ' . $stepCode . '/' . $code . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param mixed $value
     * @return array{enabled:bool,template_code:string,mode?:string}
     */
    private static function normalizeEvent(
        mixed $value,
        bool $defaultEnabled,
        string $defaultTemplate,
        ?string $defaultMode = null
    ): array {
        if (is_bool($value) || is_int($value)) {
            $enabled = (bool) $value;
            $template = $defaultTemplate;
            $mode = $defaultMode;
        } elseif (is_array($value)) {
            $enabled = array_key_exists('enabled', $value)
                ? (bool) $value['enabled']
                : $defaultEnabled;
            $template = trim((string) ($value['template_code'] ?? $defaultTemplate));
            if ($template === '') {
                $template = $defaultTemplate;
            }
            $mode = $defaultMode !== null
                ? (((string) ($value['mode'] ?? $defaultMode)) === 'auto' ? 'auto' : 'admin')
                : null;
        } else {
            $enabled = $defaultEnabled;
            $template = $defaultTemplate;
            $mode = $defaultMode;
        }

        $out = [
            'enabled' => $enabled,
            'template_code' => $template,
        ];
        if ($defaultMode !== null) {
            $out['mode'] = $mode ?? $defaultMode;
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<array{step_code:string,template_code:string,mode:string,audience:string}>
     */
    private static function normalizeStepRules(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $step = strtolower(trim((string) ($row['step_code'] ?? '')));
            $step = preg_replace('/[^a-z0-9_]+/', '_', $step) ?? '';
            $step = trim($step, '_');
            $template = trim((string) ($row['template_code'] ?? ''));
            if ($step === '' || $template === '') {
                continue;
            }
            $mode = ((string) ($row['mode'] ?? 'admin')) === 'auto' ? 'auto' : 'admin';
            $audience = MailTemplateService::audienceForTemplate($template);
            if (($row['audience'] ?? '') !== '' && $audience === 'student') {
                // Si no hay audiencia en plantilla guardada, respeta la del rule solo como fallback tipado.
                $audience = MailTemplateService::normalizeAudience((string) $row['audience']);
            }
            $out[] = [
                'step_code' => $step,
                'template_code' => $template,
                'mode' => $mode,
                'audience' => $audience,
            ];
        }

        return $out;
    }
}
