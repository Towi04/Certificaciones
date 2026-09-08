<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Automatización de correos por paso de progreso (product_groups.config_json).
 *
 * Fuente de verdad: step_defs[].email (enabled + trigger + template_code).
 * Al guardar el grupo se sincroniza emails.on_steps (mode=auto).
 *
 * - trigger=auto → GroupEmailAutomation al llegar al paso (TrackingService::setStep)
 * - trigger=admin → botón en Operación (StepMailService / ProviderRequestService)
 *
 * Los envíos hardcodeados de ciclo (registro/pago/Moodle/etc.) están desactivados:
 * configúralos como pasos del progreso con la plantilla deseada.
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

        return [
            // Legacy keys: desactivados por defecto (usar pasos del progreso).
            self::KEY_REGISTRATION => self::normalizeEvent(
                $raw[self::KEY_REGISTRATION] ?? null,
                false,
                'student_registration'
            ),
            self::KEY_PAYMENT => self::normalizeEvent(
                $raw[self::KEY_PAYMENT] ?? null,
                false,
                'student_payment_confirmed'
            ),
            self::KEY_EXAM_ACCESS => self::normalizeEvent(
                $raw[self::KEY_EXAM_ACCESS] ?? null,
                false,
                'student_elet_exam_access',
                'admin'
            ),
            'on_steps' => self::normalizeStepRules($raw['on_steps'] ?? []),
        ];
    }

    public static function isEnabled(array $product, string $key): bool
    {
        $cfg = self::forProduct($product);

        return !empty($cfg[$key]['enabled']);
    }

    public static function templateCode(array $product, string $key, string $fallback): string
    {
        $cfg = self::forProduct($product);
        $code = trim((string) ($cfg[$key]['template_code'] ?? ''));

        return $code !== '' ? $code : $fallback;
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
     * Envía plantillas mode=auto al entrar a un paso.
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
        $trackingId = (int) ($tracking['id'] ?? 0);
        $purchaseId = (int) ($tracking['purchase_id'] ?? 0);

        foreach (self::rulesForStep($product, $stepCode) as $rule) {
            if ($rule['mode'] !== 'auto') {
                continue;
            }
            $code = $rule['template_code'];
            try {
                // Solicitud UKS inicial: enlaces reglamento/pago/Excel.
                if (MailTemplateService::isUksSolicitudCode($code) && $trackingId > 0 && $purchaseId > 0) {
                    (new ProviderRequestService())->send($trackingId, $purchaseId, null, true);
                    continue;
                }

                $audience = MailTemplateService::audienceForTemplate($code);
                $to = $stepMail->resolveRecipient($tracking, $audience, $code, $mail);
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
                ? (string) ($value['mode'] ?? $defaultMode)
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
        if ($mode !== null) {
            $out['mode'] = $mode === 'auto' ? 'auto' : 'admin';
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
            $step = trim((string) ($row['step_code'] ?? ''));
            $tpl = trim((string) ($row['template_code'] ?? ''));
            if ($step === '' || $tpl === '') {
                continue;
            }
            $mode = ((string) ($row['mode'] ?? 'admin')) === 'auto' ? 'auto' : 'admin';
            $audience = MailTemplateService::normalizeAudience((string) ($row['audience'] ?? 'student'));
            $out[] = [
                'step_code' => $step,
                'template_code' => $tpl,
                'mode' => $mode,
                'audience' => $audience,
            ];
        }

        return $out;
    }
}
