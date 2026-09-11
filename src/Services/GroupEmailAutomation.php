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
    /** Confirmación de pago cuando el partner registró / pagó su tarifa (no al alumno). */
    public const KEY_PAYMENT_PARTNER = 'partner_payment_confirmed';
    public const KEY_EXAM_ACCESS = 'student_exam_access';

    /**
     * Evita correos auto duplicados en la misma petición HTTP.
     * Clave: purchase_id|template_code|destinatario
     * Sirve para paquetes/combos: varios productos con la misma bienvenida
     * no deben mandar 2–3 mails idénticos al alumno.
     *
     * @var array<string, true>
     */
    private static array $autoSentInRequest = [];

    /** Limpia el dedupe (útil en tests o jobs largos). */
    public static function resetAutoDedupe(): void
    {
        self::$autoSentInRequest = [];
    }

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
     * En paquetes/combos (varios trackings de la misma compra), la misma plantilla
     * al mismo destinatario solo se envía una vez por petición. Así un paquete con
     * 3 productos y bienvenida auto no dispara 3 correos idénticos.
     * Las solicitudes UKS/proveedor no se deduplican (van por producto).
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
                // No deduplicar: cada producto del paquete puede requerir su solicitud.
                if (MailTemplateService::isUksSolicitudCode($code) && $trackingId > 0 && $purchaseId > 0) {
                    (new ProviderRequestService())->send($trackingId, $purchaseId, null, true);
                    continue;
                }

                $audience = MailTemplateService::audienceForTemplate($code);
                $to = $stepMail->resolveRecipient($tracking, $audience, $code, $mail);
                if (self::shouldSkipDuplicateAuto($purchaseId, $code, $to)) {
                    continue;
                }
                $mergedVars = array_merge($stepMail->buildVars($tracking), $vars);
                if ($mail->render($code, $mergedVars) !== null) {
                    $mail->send($code, $to, $mergedVars);
                    self::markAutoSent($purchaseId, $code, $to);
                }
            } catch (\Throwable $e) {
                error_log('[Doceo] Correo automático paso ' . $stepCode . '/' . $code . ': ' . $e->getMessage());
            }
        }
    }

    private static function autoDedupeKey(int $purchaseId, string $templateCode, string $to): string
    {
        return $purchaseId . '|' . strtolower(trim($templateCode)) . '|' . strtolower(trim($to));
    }

    private static function shouldSkipDuplicateAuto(int $purchaseId, string $templateCode, string $to): bool
    {
        if ($purchaseId < 1 || trim($to) === '' || trim($templateCode) === '') {
            return false;
        }
        $key = self::autoDedupeKey($purchaseId, $templateCode, $to);
        if (isset(self::$autoSentInRequest[$key])) {
            error_log(
                '[Doceo] Correo auto omitido (paquete/duplicado): compra '
                . $purchaseId . ' plantilla ' . $templateCode . ' → ' . $to
            );

            return true;
        }

        return false;
    }

    private static function markAutoSent(int $purchaseId, string $templateCode, string $to): void
    {
        if ($purchaseId < 1 || trim($to) === '' || trim($templateCode) === '') {
            return;
        }
        self::$autoSentInRequest[self::autoDedupeKey($purchaseId, $templateCode, $to)] = true;
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

    /**
     * ¿La compra la pagó/registró el partner (precio de nivel), no el alumno?
     * - Partner portal o partner logueado en checkout → partner_credit_earned ≈ 0
     * - Alumno con código de partner → partner_credit_earned > 0 (crédito al partner)
     *
     * @param array<string, mixed> $purchase
     */
    public static function isPartnerPaidPurchase(array $purchase): bool
    {
        if (empty($purchase['partner_id'])) {
            return false;
        }

        return (float) ($purchase['partner_credit_earned'] ?? 0) < 0.005;
    }

    /**
     * Envía confirmación de pago tras CheckoutService::confirmPayment.
     * - Compra partner (tarifa de nivel) → plantilla partner (el alumno no ve el monto partner)
     * - Compra alumno → plantilla alumno
     * Un solo correo por compra (aunque haya varios productos/paquete).
     */
    public static function sendPaymentConfirmedEmails(int $purchaseId): void
    {
        if ($purchaseId < 1) {
            return;
        }

        $pdo = \App\Database\Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? LIMIT 1');
        $stmt->execute([$purchaseId]);
        $purchase = $stmt->fetch();
        if (!is_array($purchase)) {
            return;
        }

        $trackStmt = $pdo->prepare('SELECT id FROM trackings WHERE purchase_id = ? ORDER BY id ASC');
        $trackStmt->execute([$purchaseId]);
        $trackingIds = array_map('intval', $trackStmt->fetchAll(\PDO::FETCH_COLUMN));
        if ($trackingIds === []) {
            return;
        }

        $trackingSvc = new TrackingService();
        $tracking = $trackingSvc->find($trackingIds[0]);
        if ($tracking === null) {
            return;
        }

        $productNames = [];
        foreach ($trackingIds as $tid) {
            $row = $trackingSvc->find($tid);
            if ($row !== null) {
                $name = trim((string) ($row['product_name'] ?? ''));
                if ($name !== '' && !in_array($name, $productNames, true)) {
                    $productNames[] = $name;
                }
            }
        }
        $productLabel = $productNames !== []
            ? implode(', ', $productNames)
            : (string) ($tracking['product_name'] ?? '');

        $amount = money($purchase['charged_amount'] ?? 0);
        $partnerPaid = self::isPartnerPaidPurchase($purchase);
        $templateCode = $partnerPaid ? self::KEY_PAYMENT_PARTNER : self::KEY_PAYMENT;

        $mail = new MailTemplateService();
        $mail->ensureDefaults();
        $stepMail = new StepMailService();
        $audience = MailTemplateService::audienceForTemplate($templateCode);
        // Forzar audiencia coherente con el tipo de confirmación.
        if ($partnerPaid) {
            $audience = 'partner';
        } elseif ($audience === 'partner') {
            $audience = 'student';
        }

        try {
            $to = $stepMail->resolveRecipient($tracking, $audience, $templateCode, $mail);
            if (trim($to) === '') {
                throw new \RuntimeException(
                    $partnerPaid
                        ? 'No hay correo del partner para la confirmación de pago.'
                        : 'No hay correo del alumno para la confirmación de pago.'
                );
            }
            if (self::shouldSkipDuplicateAuto($purchaseId, $templateCode, $to)) {
                return;
            }

            $vars = array_merge($stepMail->buildVars($tracking), [
                'product_name' => $productLabel,
                'certificacion' => $productLabel,
                'amount' => $amount,
                'charged_amount' => $amount,
            ]);

            if ($mail->render($templateCode, $vars) === null) {
                throw new \RuntimeException('Plantilla de confirmación de pago no encontrada: ' . $templateCode);
            }
            $mail->send($templateCode, $to, $vars);
            self::markAutoSent($purchaseId, $templateCode, $to);

            $trackingSvc->addLog(
                (int) $tracking['id'],
                (string) ($tracking['current_step_code'] ?? 'confirm_pago'),
                'Confirmación de pago enviada a '
                    . ($partnerPaid ? 'partner' : 'alumno')
                    . ' (' . $to . ') · plantilla ' . $templateCode,
                null
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] Confirmación de pago compra ' . $purchaseId . ': ' . $e->getMessage());
            try {
                $trackingSvc->addLog(
                    (int) $tracking['id'],
                    (string) ($tracking['current_step_code'] ?? 'confirm_pago'),
                    'Confirmación de pago NO enviada: ' . $e->getMessage(),
                    null
                );
            } catch (\Throwable) {
                // ignore secondary log failure
            }
        }
    }
}
