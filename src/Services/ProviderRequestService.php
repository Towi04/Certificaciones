<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Integrations\Mailer;
use App\Repositories\PurchaseRepository;
use App\Support\WorkbookValueResolver;
use App\Support\XlsxCellFiller;
use PDO;

/**
 * Solicitud configurable al proveedor tras confirmar el pago.
 *
 * Config en product_groups.config_json → provider_request.
 */
final class ProviderRequestService
{
    public const EXTRA_KEY = 'provider_request';

    /** doc_type en documents para el comprobante DOCEO → proveedor subido por admin */
    public const ADMIN_PROOF_DOC_TYPE = 'provider_payment_proof';

    /** @var list<array{value:string,label:string}> */
    public const FIELD_OPTIONS = [
        ['value' => 'full_name', 'label' => 'Nombre completo'],
        ['value' => 'first_name', 'label' => 'Nombre(s)'],
        ['value' => 'last_name_p', 'label' => 'Apellido paterno'],
        ['value' => 'last_name_m', 'label' => 'Apellido materno'],
        ['value' => 'email', 'label' => 'Correo del alumno'],
        ['value' => 'phone', 'label' => 'Teléfono'],
        ['value' => 'matricula', 'label' => 'Matrícula'],
        ['value' => 'exam_date', 'label' => 'Fecha de examen'],
        ['value' => 'exam_time', 'label' => 'Hora de examen'],
        ['value' => 'product_name', 'label' => 'Nombre del producto'],
        ['value' => 'product_code', 'label' => 'Código del producto'],
        ['value' => 'curp', 'label' => 'CURP'],
        ['value' => 'birth_date', 'label' => 'Fecha de nacimiento'],
        ['value' => 'sex', 'label' => 'Sexo'],
        ['value' => 'nationality', 'label' => 'Nacionalidad'],
        ['value' => 'passport', 'label' => 'Pasaporte / ID'],
        ['value' => 'address', 'label' => 'Dirección'],
        ['value' => 'city', 'label' => 'Ciudad'],
        ['value' => 'state', 'label' => 'Estado'],
        ['value' => 'partner_code', 'label' => 'Código partner'],
    ];

    private PDO $pdo;
    private DocumentService $documents;
    private TrackingService $tracking;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->documents = new DocumentService();
        $this->tracking = new TrackingService();
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    public static function configForProduct(array $product): ?array
    {
        $cfg = CheckoutRequirements::config($product);
        $raw = is_array($cfg['provider_request'] ?? null) ? $cfg['provider_request'] : null;
        $fromSteps = self::providerRequestFromStepDefs($cfg);

        // El botón de Operación nace de Progreso (step_defs). Si provider_request
        // no está sincronizado o le falta plantilla/to, completar desde el paso.
        if (!is_array($raw) || empty($raw['enabled'])) {
            $raw = $fromSteps;
        } elseif ($fromSteps !== null) {
            if (trim((string) ($raw['mail_template_code'] ?? '')) === ''
                && trim((string) ($fromSteps['mail_template_code'] ?? '')) !== ''
            ) {
                $raw['mail_template_code'] = $fromSteps['mail_template_code'];
            }
            if (trim((string) ($raw['to'] ?? '')) === '' && trim((string) ($fromSteps['to'] ?? '')) !== '') {
                $raw['to'] = $fromSteps['to'];
            }
            if (trim((string) ($raw['cc'] ?? '')) === '' && trim((string) ($fromSteps['cc'] ?? '')) !== '') {
                $raw['cc'] = $fromSteps['cc'];
            }
            if (trim((string) ($raw['step_code'] ?? '')) === ''
                && trim((string) ($fromSteps['step_code'] ?? '')) !== ''
            ) {
                $raw['step_code'] = $fromSteps['step_code'];
            }
            $raw['enabled'] = true;
        }

        if (!is_array($raw) || empty($raw['enabled'])) {
            return null;
        }

        // Neubox bloquea adjuntos: siempre enlaces firmados en el correo.
        $delivery = 'links';

        $workbook = is_array($raw['workbook'] ?? null) ? $raw['workbook'] : [];
        $cellMap = [];
        foreach (is_array($workbook['cell_map'] ?? null) ? $workbook['cell_map'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cell = strtoupper(trim((string) ($item['cell'] ?? '')));
            $field = trim((string) ($item['field'] ?? ''));
            $formula = trim((string) ($item['formula'] ?? ''));
            if ($cell === '' || ($field === '' && $formula === '')) {
                continue;
            }
            $row = ['cell' => $cell, 'field' => $field];
            if ($formula !== '') {
                $row['formula'] = $formula;
            }
            $cellMap[] = $row;
        }

        $step = strtolower(trim((string) ($raw['step_code'] ?? 'solicitud_proveedor')));
        $step = preg_replace('/[^a-z0-9_]+/', '_', $step) ?? 'solicitud_proveedor';
        $step = trim($step, '_') ?: 'solicitud_proveedor';

        $includeReglamento = array_key_exists('include_reglamento', $raw)
            ? (bool) $raw['include_reglamento']
            : true;

        $out = [
            'enabled' => true,
            'auto_send_on_payment' => array_key_exists('auto_send_on_payment', $raw)
                ? (bool) $raw['auto_send_on_payment']
                : false,
            'step_code' => $step,
            'to' => trim((string) ($raw['to'] ?? '')),
            'cc' => trim((string) ($raw['cc'] ?? '')),
            'mail_template_code' => trim((string) ($raw['mail_template_code'] ?? '')),
            'include_student_data' => array_key_exists('include_student_data', $raw)
                ? (bool) $raw['include_student_data']
                : true,
            'include_exam_schedule' => array_key_exists('include_exam_schedule', $raw)
                ? (bool) $raw['include_exam_schedule']
                : true,
            'include_reglamento' => $includeReglamento,
            'include_payment_proof' => array_key_exists('include_payment_proof', $raw)
                ? (bool) $raw['include_payment_proof']
                : true,
            'require_reglamento' => array_key_exists('require_reglamento', $raw)
                ? (bool) $raw['require_reglamento']
                : $includeReglamento,
            'delivery' => $delivery,
            'require_admin_payment_proof' => array_key_exists('require_admin_payment_proof', $raw)
                ? (bool) $raw['require_admin_payment_proof']
                : true,
            'auto_send_on_admin_proof' => array_key_exists('auto_send_on_admin_proof', $raw)
                ? (bool) $raw['auto_send_on_admin_proof']
                : true,
            'workbook' => [
                'enabled' => !empty($workbook['enabled']),
                'template_path' => trim((string) ($workbook['template_path'] ?? '')),
                'attach' => array_key_exists('attach', $workbook) ? (bool) $workbook['attach'] : false,
                'sheet' => trim((string) ($workbook['sheet'] ?? '')),
                'normalize' => in_array((string) ($workbook['normalize'] ?? 'none'), ['none', 'toefl'], true)
                    ? (string) ($workbook['normalize'] ?? 'none')
                    : 'none',
                'cell_map' => $cellMap,
            ],
        ];

        // Heredar Excel de la plantilla de correo SOLO si esa plantilla realmente
        // usa placeholders de Excel. Evita forzar Excel (y fallos) cuando el paso
        // apunta a un correo sin plantilla Excel.
        $mailCode = trim((string) ($out['mail_template_code'] ?? ''));
        if ($mailCode !== '' && empty($out['workbook']['enabled'])) {
            $fromMail = MailTemplateService::workbookConfig($mailCode);
            if (
                !empty($fromMail['enabled'])
                && trim((string) ($fromMail['template_path'] ?? '')) !== ''
                && MailTemplateService::templateUsesWorkbookPlaceholders($mailCode)
            ) {
                $out['workbook'] = array_merge($out['workbook'], $fromMail, ['attach' => false]);
            }
        }

        return $out;
    }

    /**
     * Arma provider_request desde Progreso (step_defs con Enviar correo → proveedor).
     * Prefiere plantillas UKS/solicitud cuando hay varias.
     *
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>|null
     */
    public static function providerRequestFromStepDefs(array $cfg): ?array
    {
        // Leer step_defs en crudo para no depender de heurísticas de audiencia
        // (y evitar fallos si falta la extensión mbstring en CLI).
        $rawDefs = $cfg['step_defs'] ?? null;
        if (!is_array($rawDefs)) {
            $rawDefs = GroupStepConfig::defsFromConfig($cfg);
        }

        $fallback = null;
        foreach ($rawDefs as $code => $def) {
            if (!is_array($def)) {
                continue;
            }
            $email = is_array($def['email'] ?? null) ? $def['email'] : [];
            $action = (string) ($def['action'] ?? '');
            if ($action !== GroupStepConfig::ACTION_SEND_MAIL && $action !== 'send_mail') {
                continue;
            }
            if (array_key_exists('enabled', $email) && empty($email['enabled'])) {
                continue;
            }
            $audience = strtolower(trim((string) ($email['audience'] ?? '')));
            $tpl = trim((string) ($email['template_code'] ?? ''));
            // audience explícita alumno/partner → no es solicitud a proveedor.
            if ($audience !== '' && $audience !== 'provider') {
                continue;
            }
            $isProvider = $audience === 'provider'
                || ($tpl !== '' && MailTemplateService::isUksSolicitudCode($tpl))
                || $tpl === '';
            if (!$isProvider) {
                continue;
            }
            $stepCode = trim((string) ($def['code'] ?? (is_string($code) ? $code : '')));
            if ($stepCode === '') {
                $stepCode = 'solicitud_proveedor';
            }
            $candidate = [
                'enabled' => true,
                'step_code' => $stepCode,
                'mail_template_code' => $tpl,
                'to' => trim((string) ($email['to'] ?? '')),
                'cc' => trim((string) ($email['cc'] ?? '')),
                'auto_send_on_payment' => ($email['trigger'] ?? '') === 'auto',
                'include_reglamento' => true,
                'include_payment_proof' => true,
                'require_reglamento' => true,
                'require_admin_payment_proof' => true,
            ];
            if ($tpl !== '' && MailTemplateService::isUksSolicitudCode($tpl)) {
                return $candidate;
            }
            if ($tpl === '') {
                // Solicitud pesada sin código explícito (ops la trata como UKS).
                return $candidate;
            }
            $fallback ??= $candidate;
        }

        return $fallback;
    }

    /** @param array<string, mixed> $tracking */
    public function isPendingSend(array $tracking): bool
    {
        $extra = $this->decodeExtra($tracking['extra_json'] ?? null);
        $pr = is_array($extra[self::EXTRA_KEY] ?? null) ? $extra[self::EXTRA_KEY] : [];

        return !empty($pr['required']) && empty($pr['sent_at']);
    }

    public function onPaymentConfirmed(int $trackingId, int $purchaseId, int $adminUserId): void
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return;
        }

        $product = $this->productRowForTracking($tracking);
        $config = self::configForProduct($product);
        if ($config === null) {
            return;
        }

        $this->markRequired($trackingId, $tracking, $config);

        $needsAdminProof = !empty($config['require_admin_payment_proof']);
        $auto = !empty($config['auto_send_on_payment']) && !$needsAdminProof;
        $note = 'Pago confirmado · pendiente enviar solicitud al proveedor';
        if ($needsAdminProof) {
            $note = 'Pago confirmado · sube el comprobante de pago al proveedor para enviar la solicitud';
        } elseif ($auto) {
            $note = 'Pago confirmado · preparando solicitud al proveedor';
        }
        $this->tracking->setStep(
            $trackingId,
            (string) $config['step_code'],
            $adminUserId,
            $note,
            $auto ? 'waiting_provider' : 'waiting_admin'
        );

        if (!$auto) {
            return;
        }

        // El envío automático lo dispara GroupEmailAutomation al entrar al paso
        // (trigger=auto en Progreso). No reenviar aquí para evitar duplicados.
    }

    /**
     * @param array{mail_template_code?:string,step_code?:string,to?:string,cc?:string} $overrides
     * @return array{
     *   to:string,
     *   template:string,
     *   workbook_url:string,
     *   workbook_skip:string,
     *   transport:string,
     *   smtp_fallback:bool,
     *   smtp_errors:string,
     *   comprobante_url:string,
     *   attachments:bool
     * }
     */
    public function send(
        int $trackingId,
        int $purchaseId,
        ?int $actorUserId = null,
        bool $forceIncludePaymentProof = false,
        bool $allowSkipAdminProof = false,
        array $overrides = []
    ): array {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        $purchase = (new PurchaseRepository())->find($purchaseId);
        if ($purchase === null) {
            throw new \InvalidArgumentException('Compra no encontrada.');
        }

        $product = $this->productRowForTracking($tracking);
        $config = self::configForProduct($product);
        if ($config === null) {
            throw new \RuntimeException(
                'Este producto no tiene solicitud a proveedor habilitada. '
                . 'Revisa Progreso del grupo: un paso con «Enviar correo» y audiencia proveedor.'
            );
        }

        foreach (['mail_template_code', 'step_code', 'to', 'cc'] as $key) {
            if (isset($overrides[$key]) && trim((string) $overrides[$key]) !== '') {
                $config[$key] = trim((string) $overrides[$key]);
            }
        }

        $to = $this->resolveRecipient($config);
        if ($to === '') {
            throw new \RuntimeException(
                'Configura el correo del proveedor en Admin → Correos (Para), '
                . 'o en el grupo si aún usas el campo legado de solicitud a proveedor.'
            );
        }

        if (
            !empty($config['require_admin_payment_proof'])
            && !$allowSkipAdminProof
            && $this->findAdminPaymentProof($trackingId) === null
        ) {
            throw new \RuntimeException(
                'Debes subir el comprobante de pago (DOCEO → proveedor) antes de enviar la solicitud.'
            );
        }

        $tmpFiles = [];
        try {
            $vars = $this->buildMailVars(
                $tracking,
                $purchase,
                $product,
                $config,
                $forceIncludePaymentProof,
                $tmpFiles
            );

            // Nunca adjuntar archivos: Neubox/hosting bloquea SMTP con attachments.
            $cc = trim((string) ($config['cc'] ?? ''));
            if ($cc === '') {
                $tplCode = trim((string) ($config['mail_template_code'] ?? ''));
                if ($tplCode !== '') {
                    $routingCc = (new MailTemplateService())->routing($tplCode)['cc'] ?? '';
                    $cc = trim((string) $routingCc);
                }
            }
            $templateCode = (string) $config['mail_template_code'];
            $this->dispatchMail(
                $to,
                $cc,
                $templateCode,
                $vars,
                []
            );

            $endpoint = Mailer::lastEndpoint();
            $transport = is_array($endpoint)
                ? (string) ($endpoint['transport'] ?? 'desconocido')
                : 'desconocido';
            $smtpHost = is_array($endpoint)
                ? trim((string) ($endpoint['host'] ?? ''))
                : '';
            $messageId = is_array($endpoint)
                ? trim((string) ($endpoint['message_id'] ?? ''))
                : '';
            $smtpDataResponse = is_array($endpoint)
                ? trim((string) ($endpoint['smtp_data_response'] ?? ''))
                : '';
            $smtpFallback = is_array($endpoint) && !empty($endpoint['fallback']);
            $smtpErrors = '';
            if ($smtpFallback && is_array($endpoint['smtp_errors'] ?? null)) {
                $smtpErrors = implode('; ', array_map('strval', $endpoint['smtp_errors']));
            }

            $step = (string) $config['step_code'];
            if ((string) ($tracking['current_step_code'] ?? '') !== $step) {
                $this->tracking->setStep(
                    $trackingId,
                    $step,
                    $actorUserId,
                    'Solicitud enviada al proveedor',
                    'waiting_provider'
                );
            } else {
                $this->pdo->prepare('UPDATE trackings SET status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute(['waiting_provider', $trackingId]);
            }

            $this->markSent($trackingId, $to, $actorUserId);
            $workbookSkip = trim((string) ($vars['_workbook_skip'] ?? ''));
            $note = 'Solicitud enviada a ' . $to
                . ' (solo enlaces, sin adjuntos · transporte ' . $transport
                . ($smtpHost !== '' ? ' @ ' . $smtpHost : '')
                . ')';
            if ($smtpFallback) {
                $note .= ' · aviso: SMTP falló y se usó mail() local';
            }
            if (($vars['workbook_url'] ?? '') !== '') {
                $note .= ' · Excel';
            } elseif ($workbookSkip !== '') {
                $note .= ' · Excel omitido: ' . $workbookSkip;
            }
            if (($vars['reglamento_url'] ?? '') !== '') {
                $note .= ' · reglamento';
            }
            if (($vars['comprobante_url'] ?? '') !== '') {
                $note .= ' · comprobante (enlace)';
            }
            $this->tracking->addLog($trackingId, $step, $note, $actorUserId);

            return [
                'to' => $to,
                'template' => $templateCode,
                'workbook_url' => (string) ($vars['workbook_url'] ?? ''),
                'workbook_skip' => $workbookSkip,
                'transport' => $transport,
                'smtp_host' => $smtpHost,
                'smtp_fallback' => $smtpFallback,
                'smtp_errors' => $smtpErrors,
                'message_id' => $messageId,
                'smtp_data_response' => $smtpDataResponse,
                'comprobante_url' => (string) ($vars['comprobante_url'] ?? ''),
                'attachments' => false,
            ];
        } finally {
            foreach ($tmpFiles as $tmp) {
                if (is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function resolveRecipient(array $config): string
    {
        $to = trim((string) ($config['to'] ?? ''));
        if ($to !== '') {
            return $to;
        }

        $mail = new MailTemplateService();
        $tplCode = trim((string) ($config['mail_template_code'] ?? ''));
        if ($tplCode !== '') {
            $routing = $mail->routing($tplCode);
            if (($routing['to'] ?? '') !== '') {
                return (string) $routing['to'];
            }
        }

        return $mail->uksSolicitudRecipient();
    }

    /**
     * @param array<string, mixed> $tracking
     * @param array<string, mixed> $purchase
     * @param array<string, mixed> $product
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    /**
     * @param list<string> $tmpFiles
     */
    private function buildMailVars(
        array $tracking,
        array $purchase,
        array $product,
        array $config,
        bool $forceIncludePaymentProof,
        array &$tmpFiles = []
    ): array {
        $fields = $this->fieldValues($tracking, $purchase, $product);
        $includeProof = $forceIncludePaymentProof || !empty($config['include_payment_proof']);
        $includeReglamento = !empty($config['include_reglamento']);
        $requireReglamento = !empty($config['require_reglamento']);

        $reglamentoUrl = '';
        $comprobanteUrl = '';
        $workbookUrl = '';
        $fileLinks = new SignedFileLinkService();

        if ($includeReglamento || $requireReglamento) {
            $regDoc = $this->findSignedReglamentoDocument((int) $tracking['id'], (int) $purchase['id'], $product);
            if ($regDoc === null) {
                if ($requireReglamento) {
                    throw new \RuntimeException(
                        'No se encontró el reglamento firmado del alumno. No se puede enviar la solicitud.'
                    );
                }
            } else {
                $abs = $this->documents->absolutePath((string) $regDoc['storage_path']);
                if (!is_file($abs) && $requireReglamento) {
                    throw new \RuntimeException('El archivo del reglamento firmado no está disponible.');
                }
                if (is_file($abs)) {
                    $reglamentoUrl = $fileLinks->documentLink((int) $regDoc['id']);
                }
            }
        }

        if ($includeProof) {
            $adminDoc = $this->findAdminPaymentProof((int) ($tracking['id'] ?? 0));
            if ($adminDoc !== null) {
                $abs = $this->documents->absolutePath((string) $adminDoc['storage_path']);
                if (is_file($abs)) {
                    $comprobanteUrl = $fileLinks->documentLink((int) $adminDoc['id']);
                }
            } else {
                $proofPath = (string) ($purchase['payment_proof_path'] ?? $tracking['payment_proof_path'] ?? '');
                if ($proofPath !== '') {
                    $abs = $this->documents->absolutePath($proofPath);
                    if (is_file($abs)) {
                        $comprobanteUrl = $fileLinks->purchaseProofLink((int) $purchase['id']);
                    }
                }
            }
        }

        if (!empty($config['workbook']['enabled'])) {
            $mailCode = trim((string) ($config['mail_template_code'] ?? ''));
            $templateWantsWorkbook = $mailCode === ''
                || MailTemplateService::isUksSolicitudCode($mailCode)
                || MailTemplateService::templateUsesWorkbookPlaceholders($mailCode);
            if (!$templateWantsWorkbook) {
                // El grupo/plantilla tenía Excel, pero el correo del paso no lo usa.
                $workbookUrl = '';
                $varsWorkbookSkip = 'la plantilla de correo no incluye Excel';
            } else {
                try {
                    $workbookUrl = $this->prepareWorkbookLink($tracking, $purchase, $product, $config, $tmpFiles);
                    $varsWorkbookSkip = '';
                } catch (\Throwable $e) {
                    // No tumbar el correo entero si falla el Excel; el admin puede reenviar luego.
                    error_log('[Doceo] Excel solicitud proveedor: ' . $e->getMessage());
                    $workbookUrl = '';
                    $varsWorkbookSkip = $e->getMessage();
                }
            }
        } else {
            $varsWorkbookSkip = '';
        }

        $workbookNote = $workbookUrl !== ''
            ? 'Plantilla Excel disponible por enlace seguro.'
            : '';

        return [
            'certificacion' => $fields['product_name'],
            'product_name' => $fields['product_name'],
            'full_name' => $fields['full_name'],
            'matricula' => $fields['matricula'],
            'student_email' => $fields['email'],
            'student_phone' => $fields['phone'],
            'exam_date' => $fields['exam_date'],
            'exam_time' => $fields['exam_time'],
            'reglamento_url' => $reglamentoUrl,
            'comprobante_url' => $comprobanteUrl,
            'pago_proveedor' => $comprobanteUrl,
            'workbook_url' => $workbookUrl,
            'documentos_html' => $this->documentosHtml($reglamentoUrl, $comprobanteUrl, $workbookUrl),
            'attachment_note' => 'Documentos por enlace seguro (sin adjuntos en el correo).',
            'workbook_note' => $workbookNote,
            'first_name' => $fields['first_name'],
            'last_name_p' => $fields['last_name_p'],
            'last_name_m' => $fields['last_name_m'],
            '_workbook_skip' => $varsWorkbookSkip,
        ];
    }

    /**
     * @param array<string, mixed> $tracking
     * @param array<string, mixed> $purchase
     * @param array<string, mixed> $product
     * @return array<string, string>
     */
    public function fieldValues(array $tracking, array $purchase, array $product): array
    {
        $checkout = [];
        $raw = $tracking['checkout_json'] ?? $purchase['checkout_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $checkout = $decoded;
            }
        } elseif (is_array($raw)) {
            $checkout = $raw;
        }

        $first = (string) ($tracking['first_name'] ?? $checkout['first_name'] ?? '');
        $lp = (string) ($tracking['last_name_p'] ?? $checkout['last_name_p'] ?? '');
        $lm = (string) ($tracking['last_name_m'] ?? $checkout['last_name_m'] ?? '');

        $values = [
            'full_name' => trim(implode(' ', array_filter([$first, $lp, $lm]))),
            'first_name' => $first,
            'last_name_p' => $lp,
            'last_name_m' => $lm,
            'email' => (string) ($tracking['student_email'] ?? $checkout['email'] ?? ''),
            'phone' => (string) ($tracking['student_phone'] ?? $checkout['phone'] ?? ''),
            'matricula' => (string) ($tracking['matricula'] ?? $purchase['matricula'] ?? ''),
            'exam_date' => (string) ($tracking['exam_date'] ?? ''),
            'exam_time' => !empty($tracking['exam_time']) ? substr((string) $tracking['exam_time'], 0, 5) : '',
            'product_name' => trim((string) ($tracking['product_name'] ?? $product['name'] ?? 'Certificación')),
            'product_code' => (string) ($product['code'] ?? ''),
            'curp' => (string) ($checkout['curp'] ?? $tracking['curp'] ?? ''),
            'birth_date' => (string) ($checkout['birth_date'] ?? $tracking['birth_date'] ?? ''),
            'sex' => (string) ($checkout['sex'] ?? $tracking['sex'] ?? ''),
            'nationality' => (string) ($checkout['nationality'] ?? $tracking['nationality'] ?? ''),
            'passport' => (string) ($checkout['passport'] ?? $checkout['id_number'] ?? $tracking['passport'] ?? ''),
            'address' => (string) ($checkout['address'] ?? $checkout['street'] ?? ''),
            'city' => (string) ($checkout['city'] ?? ''),
            'state' => (string) ($checkout['state'] ?? $checkout['estado'] ?? ''),
            'partner_code' => (string) ($tracking['partner_code'] ?? $purchase['partner_code'] ?? $checkout['partner_code'] ?? ''),
        ];

        foreach ($checkout as $key => $val) {
            if (!is_string($key) || $key === '' || array_key_exists($key, $values)) {
                continue;
            }
            if (is_scalar($val)) {
                $values[$key] = (string) $val;
            }
        }

        return $values;
    }

    /**
     * LEGACY / no usar: Neubox bloquea SMTP con adjuntos.
     * La solicitud a proveedor envía solo enlaces ({{comprobante_url}}, etc.).
     *
     * @param array<string, mixed> $tracking
     * @param array<string, mixed> $purchase
     * @param array<string, mixed> $product
     * @param array<string, mixed> $config
     * @return list<array{path:string,name:string,mime:string}>
     */
    private function buildFileAttachments(
        array $tracking,
        array $purchase,
        array $product,
        array $config,
        bool $forceIncludePaymentProof
    ): array {
        $out = [];
        if (!empty($config['include_reglamento'])) {
            $regDoc = $this->findSignedReglamentoDocument((int) $tracking['id'], (int) $purchase['id'], $product);
            if ($regDoc !== null) {
                $abs = $this->documents->absolutePath((string) $regDoc['storage_path']);
                if (is_file($abs)) {
                    $out[] = [
                        'path' => $abs,
                        'name' => 'reglamento_firmado.pdf',
                        'mime' => 'application/pdf',
                    ];
                }
            }
        }

        if ($forceIncludePaymentProof || !empty($config['include_payment_proof'])) {
            $adminDoc = $this->findAdminPaymentProof((int) ($tracking['id'] ?? 0));
            if ($adminDoc !== null) {
                $abs = $this->documents->absolutePath((string) $adminDoc['storage_path']);
                if (is_file($abs)) {
                    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION) ?: 'pdf');
                    $out[] = [
                        'path' => $abs,
                        'name' => 'comprobante_pago_proveedor.' . $ext,
                        'mime' => $ext === 'pdf' ? 'application/pdf' : 'application/octet-stream',
                    ];
                }
            } else {
                $proofPath = (string) ($purchase['payment_proof_path'] ?? '');
                if ($proofPath !== '') {
                    $abs = $this->documents->absolutePath($proofPath);
                    if (is_file($abs)) {
                        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION) ?: 'pdf');
                        $out[] = [
                            'path' => $abs,
                            'name' => 'comprobante_pago.' . $ext,
                            'mime' => $ext === 'pdf' ? 'application/pdf' : 'application/octet-stream',
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tracking
     * @param array<string, mixed> $purchase
     * @param array<string, mixed> $product
     * @param array<string, mixed> $config
     * @param list<string> $tmpFiles
     */
    /**
     * Rellena la plantilla Excel, la guarda como documento y devuelve enlace firmado.
     *
     * @param list<string> $tmpFiles
     */
    private function prepareWorkbookLink(
        array $tracking,
        array $purchase,
        array $product,
        array $config,
        array &$tmpFiles
    ): string {
        $wb = is_array($config['workbook'] ?? null) ? $config['workbook'] : [];
        if (empty($wb['enabled'])) {
            return '';
        }

        $relative = trim((string) ($wb['template_path'] ?? ''));
        if ($relative === '') {
            throw new \RuntimeException(
                'La solicitud requiere plantilla Excel: súbela en Grupos → Solicitud a proveedor.'
            );
        }

        $abs = $this->documents->absolutePath($relative);
        if (!is_file($abs) && defined('BASE_PATH')) {
            $alt = BASE_PATH . '/storage/' . ltrim($relative, '/');
            if (is_file($alt)) {
                $abs = $alt;
            }
        }
        if (!is_file($abs)) {
            throw new \RuntimeException('No se encontró el archivo de plantilla Excel del grupo.');
        }

        $fields = $this->fieldValues($tracking, $purchase, $product);
        $normalize = (string) ($wb['normalize'] ?? 'none');
        $cellValues = [];
        foreach (is_array($wb['cell_map'] ?? null) ? $wb['cell_map'] : [] as $map) {
            if (!is_array($map)) {
                continue;
            }
            $cell = strtoupper(trim((string) ($map['cell'] ?? '')));
            if ($cell === '') {
                continue;
            }
            try {
                $cellValues[$cell] = WorkbookValueResolver::resolve($map, $fields, $normalize);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Error en fórmula de la celda ' . $cell . ': ' . $e->getMessage()
                );
            }
        }
        if ($cellValues === []) {
            throw new \RuntimeException(
                'Define al menos un mapeo celda → dato en Grupos → Solicitud a proveedor.'
            );
        }

        $sheet = trim((string) ($wb['sheet'] ?? ''));
        $filled = (new XlsxCellFiller())->fill(
            $abs,
            $cellValues,
            null,
            $sheet !== '' ? $sheet : null
        );
        $tmpFiles[] = $filled;
        $matricula = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $fields['matricula']) ?: 'alumno';
        $fileName = 'solicitud_' . $matricula . '.xlsx';

        $dir = BASE_PATH . '/storage/uploads/provider_workbooks';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo guardar la plantilla Excel rellenada.');
        }
        $safe = bin2hex(random_bytes(16)) . '.xlsx';
        $dest = $dir . '/' . $safe;
        if (!@copy($filled, $dest)) {
            throw new \RuntimeException('No se pudo copiar la plantilla Excel rellenada.');
        }
        $relative = 'uploads/provider_workbooks/' . $safe;

        $studentUserId = (int) ($tracking['student_user_id'] ?? $tracking['purchase_student_id'] ?? 0);
        if ($studentUserId < 1) {
            throw new \RuntimeException('No se pudo determinar el alumno para guardar el Excel.');
        }

        $this->pdo->prepare(
            'INSERT INTO documents (tracking_id, purchase_id, student_user_id, doc_type, original_name, storage_path, status, uploaded_by)
             VALUES (?,?,?,?,?,?,\'approved\',?)'
        )->execute([
            (int) ($tracking['id'] ?? 0),
            (int) ($purchase['id'] ?? $tracking['purchase_id'] ?? 0),
            $studentUserId,
            'provider_workbook',
            $fileName,
            $relative,
            null,
        ]);
        $docId = (int) $this->pdo->lastInsertId();

        return (new SignedFileLinkService())->documentLink($docId);
    }

    /**
     * @param array<string, string> $vars
     * @param list<array{path:string,name?:string,mime?:string}> $attachments Ignorado: Neubox bloquea adjuntos.
     */
    private function dispatchMail(
        string $to,
        string $cc,
        string $templateCode,
        array $vars,
        array $attachments = []
    ): void {
        // Política fija: solicitud a proveedor NUNCA lleva adjuntos (Neubox/SMTP los bloquea).
        // El comprobante/reglamento/Excel van solo como {{comprobante_url}} / enlaces en la plantilla.
        if ($attachments !== []) {
            error_log('[Doceo] Solicitud proveedor: se ignoraron ' . count($attachments) . ' adjunto(s); solo enlaces.');
        }

        $options = [
            // Proveedor = dominio externo. mail() en Neubox suele «aceptar» y no entregar.
            // Exigir SMTP real: si AUTH/relay falla, el admin verá error (no éxito falso).
            'prefer_smtp' => true,
            'force_smtp' => true,
            'smtp_only' => true,
            'log_outbound' => true,
        ];
        if ($cc !== '') {
            $options['cc'] = $cc;
        }

        $mailTpl = new MailTemplateService();
        try {
            if ($templateCode !== '') {
                if (MailTemplateService::isUksSolicitudCode($templateCode)) {
                    $mailTpl->sendUksSolicitud($to, $vars, $options);
                } elseif ($mailTpl->render($templateCode, $vars) !== null) {
                    $mailTpl->send($templateCode, $to, $vars, $options);
                } else {
                    throw new \RuntimeException(
                        'Configura la plantilla de solicitud al proveedor en el paso del grupo '
                        . '(Progreso → Enviar correo + plantilla). Código no encontrado: '
                        . $templateCode
                    );
                }
            } else {
                $mailTpl->sendUksSolicitud($to, $vars, $options);
            }
        } catch (\Throwable $e) {
            $endpoint = Mailer::lastEndpoint();
            $errors = Mailer::lastErrors();
            $hint = ' El correo NO se marcó como enviado. '
                . 'La bienvenida puede llegar con mail() aunque SMTP AUTH falle (535). '
                . 'Revisa SMTP_PASS, restablece la clave del buzón, o pide a Neubox desbloquear AUTH SMTP '
                . '(cPHulk a veces no aparece en el panel). Destino: ' . $to . '. ';
            if ($errors !== []) {
                $hint .= ' Detalle: ' . implode(' | ', $errors);
            }
            if (is_array($endpoint)) {
                $hint .= ' Último intento: ' . json_encode($endpoint, JSON_UNESCAPED_UNICODE);
            }
            throw new \RuntimeException(rtrim($e->getMessage(), '.') . '.' . $hint, 0, $e);
        }

        $endpoint = Mailer::lastEndpoint();
        $transport = is_array($endpoint) ? (string) ($endpoint['transport'] ?? '') : '';
        // smtp = AUTH remoto OK; smtp_local = Exim local sin AUTH (respaldo Neubox tras 535).
        if (!in_array($transport, ['smtp', 'smtp_local'], true)) {
            throw new \RuntimeException(
                'El correo al proveedor no usó un transporte SMTP válido (transporte: '
                . ($transport !== '' ? $transport : 'desconocido')
                . '). No se confirma entrega a ' . $to . '. '
                . 'Nota: el correo de bienvenida usa mail() local y puede llegar aunque SMTP AUTH falle (535). '
                . 'Revisa SMTP_PASS o pide a Neubox desbloquear AUTH SMTP / crear un buzón nuevo.'
            );
        }
    }

    private function documentosHtml(string $reglamentoUrl, string $comprobanteUrl, string $workbookUrl = ''): string
    {
        if ($reglamentoUrl === '' && $comprobanteUrl === '' && $workbookUrl === '') {
            return '';
        }
        $html = '<p><strong>Documentos:</strong></p><ul>';
        if ($reglamentoUrl !== '') {
            $html .= '<li><a href="' . htmlspecialchars($reglamentoUrl) . '">Reglamento firmado</a></li>';
        }
        if ($comprobanteUrl !== '') {
            $html .= '<li><a href="' . htmlspecialchars($comprobanteUrl) . '">Comprobante de pago al proveedor</a></li>';
        }
        if ($workbookUrl !== '') {
            $html .= '<li><a href="' . htmlspecialchars($workbookUrl) . '">Plantilla Excel</a></li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private function findSignedReglamentoDocument(int $trackingId, int $purchaseId, array $product): ?array
    {
        $cfg = CheckoutRequirements::config($product);
        $docCode = '';
        if (is_array($cfg['reglamento'] ?? null)) {
            $docCode = trim((string) ($cfg['reglamento']['doc_code'] ?? ''));
        }
        $types = array_values(array_filter(array_unique([
            $docCode,
            'reglamento_firmado',
            'reglamento',
        ])));

        foreach ($types as $docType) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM documents WHERE tracking_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$trackingId, $docType]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        foreach ($types as $docType) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM documents WHERE purchase_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$purchaseId, $docType]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tracking
     * @return array<string, mixed>
     */
    private function productRowForTracking(array $tracking): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pr.*, pg.config_json AS group_config_json, pg.code AS product_group_code
             FROM products pr
             LEFT JOIN product_groups pg ON pg.id = pr.product_group_id
             WHERE pr.id = ?
             LIMIT 1'
        );
        $stmt->execute([(int) ($tracking['product_id'] ?? 0)]);
        $row = $stmt->fetch();
        if (!$row) {
            return [
                'id' => (int) ($tracking['product_id'] ?? 0),
                'name' => (string) ($tracking['product_name'] ?? ''),
                'code' => '',
                'config_json' => $tracking['config_json'] ?? null,
                'group_config_json' => $tracking['group_config_json'] ?? null,
            ];
        }

        return $row;
    }

    /** @param array<string, mixed> $tracking @param array<string, mixed> $config */
    private function markRequired(int $trackingId, array $tracking, array $config): void
    {
        $extra = $this->decodeExtra($tracking['extra_json'] ?? null);
        $extra[self::EXTRA_KEY] = array_merge(
            is_array($extra[self::EXTRA_KEY] ?? null) ? $extra[self::EXTRA_KEY] : [],
            [
                'required' => true,
                'step_code' => $config['step_code'],
                'sent_at' => null,
                'last_error' => null,
            ]
        );
        $this->saveExtra($trackingId, $extra);
    }

    private function markSent(int $trackingId, string $to, ?int $actorUserId): void
    {
        $tracking = $this->tracking->find($trackingId);
        $extra = $this->decodeExtra($tracking['extra_json'] ?? null);
        $prev = is_array($extra[self::EXTRA_KEY] ?? null) ? $extra[self::EXTRA_KEY] : [];
        $extra[self::EXTRA_KEY] = array_merge($prev, [
            'required' => true,
            'sent_at' => date('c'),
            'sent_to' => $to,
            'sent_by' => $actorUserId,
            'last_error' => null,
        ]);
        $this->saveExtra($trackingId, $extra);
    }

    private function storeSendError(int $trackingId, string $message): void
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return;
        }
        $extra = $this->decodeExtra($tracking['extra_json'] ?? null);
        $prev = is_array($extra[self::EXTRA_KEY] ?? null) ? $extra[self::EXTRA_KEY] : [];
        $extra[self::EXTRA_KEY] = array_merge($prev, [
            'required' => true,
            'last_error' => mb_substr($message, 0, 500),
        ]);
        $this->saveExtra($trackingId, $extra);
    }

    /** @return array<string, mixed> */
    private function decodeExtra(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $extra */
    private function saveExtra(int $trackingId, array $extra): void
    {
        $this->pdo->prepare('UPDATE trackings SET extra_json = ?, updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);
    }

    /** @return array<string, mixed>|null */
    public function findAdminPaymentProof(int $trackingId): ?array
    {
        if ($trackingId < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM documents WHERE tracking_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$trackingId, self::ADMIN_PROOF_DOC_TYPE]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Sube el comprobante de pago DOCEO → proveedor. Si auto_send_on_admin_proof, envía la solicitud.
     *
     * @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file
     * @return array{document_id:int,sent:bool}
     */
    public function uploadAdminPaymentProof(int $trackingId, array $file, int $adminUserId): array
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        $product = $this->productRowForTracking($tracking);
        $config = self::configForProduct($product);
        // Permitir subir comprobante aunque el grupo aún no tenga provider_request
        // (p. ej. desde el popup al enviar otro correo al proveedor).
        $stepCode = is_array($config)
            ? (string) ($config['step_code'] ?? 'solicitud_proveedor')
            : (string) ($tracking['current_step_code'] ?? 'solicitud_proveedor');
        if ($stepCode === '') {
            $stepCode = 'solicitud_proveedor';
        }

        $stored = $this->documents->storeUploaded($file, 'provider_payment_proofs', '.pdf,.jpg,.jpeg,.png,.webp');
        $studentUserId = (int) ($tracking['student_user_id'] ?? $tracking['purchase_student_id'] ?? 0);
        if ($studentUserId < 1) {
            throw new \RuntimeException('No se pudo determinar el alumno del seguimiento.');
        }

        $this->pdo->prepare(
            'INSERT INTO documents (tracking_id, purchase_id, student_user_id, doc_type, original_name, storage_path, status, uploaded_by)
             VALUES (?,?,?,?,?,?,\'approved\',?)'
        )->execute([
            $trackingId,
            (int) ($tracking['purchase_id'] ?? 0),
            $studentUserId,
            self::ADMIN_PROOF_DOC_TYPE,
            $stored['original_name'],
            $stored['path'],
            $adminUserId,
        ]);
        $docId = (int) $this->pdo->lastInsertId();

        $extra = $this->decodeExtra($tracking['extra_json'] ?? null);
        $prev = is_array($extra[self::EXTRA_KEY] ?? null) ? $extra[self::EXTRA_KEY] : [];
        $extra[self::EXTRA_KEY] = array_merge($prev, [
            'required' => true,
            'admin_proof_document_id' => $docId,
            'admin_proof_path' => $stored['path'],
            'admin_proof_uploaded_at' => date('c'),
            'admin_proof_uploaded_by' => $adminUserId,
        ]);
        $this->saveExtra($trackingId, $extra);

        $this->tracking->addLog(
            $trackingId,
            $stepCode,
            'Comprobante de pago al proveedor subido por admin',
            $adminUserId
        );

        // El envío al proveedor se hace desde Operación (o auto al llegar al paso).
        return ['document_id' => $docId, 'sent' => false];
    }


}
