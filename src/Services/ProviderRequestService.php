<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Integrations\Mailer;
use App\Repositories\PurchaseRepository;
use App\Support\AsciiUpperNormalizer;
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
        $raw = $cfg['provider_request'] ?? null;
        if (!is_array($raw) || empty($raw['enabled'])) {
            return null;
        }

        $delivery = (string) ($raw['delivery'] ?? 'links');
        if (!in_array($delivery, ['links', 'attachments', 'both'], true)) {
            $delivery = 'links';
        }

        $workbook = is_array($raw['workbook'] ?? null) ? $raw['workbook'] : [];
        $cellMap = [];
        foreach (is_array($workbook['cell_map'] ?? null) ? $workbook['cell_map'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cell = strtoupper(trim((string) ($item['cell'] ?? '')));
            $field = trim((string) ($item['field'] ?? ''));
            if ($cell === '' || $field === '') {
                continue;
            }
            $cellMap[] = ['cell' => $cell, 'field' => $field];
        }

        $step = strtolower(trim((string) ($raw['step_code'] ?? 'solicitud_proveedor')));
        $step = preg_replace('/[^a-z0-9_]+/', '_', $step) ?? 'solicitud_proveedor';
        $step = trim($step, '_') ?: 'solicitud_proveedor';

        $includeReglamento = array_key_exists('include_reglamento', $raw)
            ? (bool) $raw['include_reglamento']
            : true;

        return [
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
                'attach' => array_key_exists('attach', $workbook) ? (bool) $workbook['attach'] : true,
                'sheet' => trim((string) ($workbook['sheet'] ?? '')),
                'normalize' => in_array((string) ($workbook['normalize'] ?? 'none'), ['none', 'toefl'], true)
                    ? (string) ($workbook['normalize'] ?? 'none')
                    : 'none',
                'cell_map' => $cellMap,
            ],
        ];
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

        try {
            $this->send($trackingId, $purchaseId, $adminUserId, false);
        } catch (\Throwable $e) {
            error_log('[Doceo] ProviderRequest auto-send: ' . $e->getMessage());
            $this->storeSendError($trackingId, $e->getMessage());
            $this->tracking->setStep(
                $trackingId,
                (string) $config['step_code'],
                $adminUserId,
                'Pago confirmado (solicitud al proveedor falló: ' . $e->getMessage() . ')',
                'waiting_admin'
            );
            $this->tracking->addLog(
                $trackingId,
                (string) $config['step_code'],
                'Error al enviar solicitud: ' . $e->getMessage(),
                $adminUserId
            );
        }
    }

    public function send(
        int $trackingId,
        int $purchaseId,
        ?int $actorUserId = null,
        bool $forceIncludePaymentProof = false
    ): void {
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
            throw new \RuntimeException('Este producto no tiene solicitud a proveedor habilitada.');
        }

        $to = $this->resolveRecipient($config);
        if ($to === '') {
            throw new \RuntimeException(
                'Configura el correo destino en Grupos → Solicitud a proveedor (campo Para), '
                . 'o en Admin → Correos si usas la plantilla UKS.'
            );
        }

        if (!empty($config['require_admin_payment_proof']) && $this->findAdminPaymentProof($trackingId) === null) {
            throw new \RuntimeException(
                'Debes subir el comprobante de pago (DOCEO → proveedor) antes de enviar la solicitud.'
            );
        }

        $vars = $this->buildMailVars($tracking, $purchase, $product, $config, $forceIncludePaymentProof);
        $attachments = [];
        $tmpFiles = [];

        try {
            if (in_array((string) $config['delivery'], ['attachments', 'both'], true)) {
                foreach ($this->buildFileAttachments($tracking, $purchase, $product, $config, $forceIncludePaymentProof) as $att) {
                    $attachments[] = $att;
                }
            }

            $workbookAtt = $this->buildWorkbookAttachment($tracking, $purchase, $product, $config, $tmpFiles);
            if ($workbookAtt !== null) {
                $attachments[] = $workbookAtt;
                $vars['workbook_note'] = 'Se adjunta plantilla Excel con los datos del alumno.';
            }

            $this->dispatchMail(
                $to,
                (string) $config['cc'],
                (string) $config['mail_template_code'],
                $vars,
                $attachments
            );

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
            $note = 'Solicitud enviada a ' . $to;
            if ($attachments !== []) {
                $note .= ' · ' . count($attachments) . ' adjunto(s)';
            }
            $this->tracking->addLog($trackingId, $step, $note, $actorUserId);
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
    private function buildMailVars(
        array $tracking,
        array $purchase,
        array $product,
        array $config,
        bool $forceIncludePaymentProof
    ): array {
        $fields = $this->fieldValues($tracking, $purchase, $product);
        $includeProof = $forceIncludePaymentProof || !empty($config['include_payment_proof']);
        $includeReglamento = !empty($config['include_reglamento']);
        $requireReglamento = !empty($config['require_reglamento']);

        $reglamentoUrl = '';
        $comprobanteUrl = '';
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
            $proofPath = (string) ($purchase['payment_proof_path'] ?? $tracking['payment_proof_path'] ?? '');
            if ($proofPath !== '') {
                $abs = $this->documents->absolutePath($proofPath);
                if (is_file($abs)) {
                    $comprobanteUrl = $fileLinks->purchaseProofLink((int) $purchase['id']);
                }
            }
        }

        $hasWorkbook = !empty($config['workbook']['enabled']);

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
            'documentos_html' => $this->documentosHtml($reglamentoUrl, $comprobanteUrl, $hasWorkbook),
            'attachment_note' => in_array((string) $config['delivery'], ['attachments', 'both'], true)
                ? 'Documentos adjuntos y/o enlaces según configuración del grupo.'
                : 'Documentos disponibles por enlace seguro.',
            'workbook_note' => '',
            'first_name' => $fields['first_name'],
            'last_name_p' => $fields['last_name_p'],
            'last_name_m' => $fields['last_name_m'],
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
     * @return array{path:string,name:string,mime:string}|null
     */
    private function buildWorkbookAttachment(
        array $tracking,
        array $purchase,
        array $product,
        array $config,
        array &$tmpFiles
    ): ?array {
        $wb = is_array($config['workbook'] ?? null) ? $config['workbook'] : [];
        if (empty($wb['enabled']) || empty($wb['attach'])) {
            return null;
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
            $cell = (string) ($map['cell'] ?? '');
            $field = (string) ($map['field'] ?? '');
            if ($cell === '' || $field === '') {
                continue;
            }
            $value = (string) ($fields[$field] ?? '');
            if ($normalize === 'toefl') {
                $value = AsciiUpperNormalizer::normalize($value);
            }
            $cellValues[$cell] = $value;
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

        return [
            'path' => $filled,
            'name' => 'solicitud_' . $matricula . '.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * @param array<string, string> $vars
     * @param list<array{path:string,name?:string,mime?:string}> $attachments
     */
    private function dispatchMail(
        string $to,
        string $cc,
        string $templateCode,
        array $vars,
        array $attachments
    ): void {
        $options = [];
        if ($cc !== '') {
            $options['cc'] = $cc;
        }
        if ($attachments !== []) {
            $options['attachments'] = $attachments;
            $options['prefer_smtp'] = true;
        }

        $mailTpl = new MailTemplateService();
        if ($templateCode !== '') {
            if (
                $templateCode === MailTemplateService::UKS_SOLICITUD
                || $templateCode === MailTemplateService::UKS_SOLICITUD_LEGACY
            ) {
                $mailTpl->sendUksSolicitud($to, $vars, $options);

                return;
            }
            if ($mailTpl->render($templateCode, $vars) !== null) {
                $mailTpl->send($templateCode, $to, $vars, $options);

                return;
            }
        }

        $subject = 'Solicitud ' . $vars['product_name'] . ' · ' . $vars['full_name'] . ' · ' . $vars['matricula'];
        $text = "Solicitud de registro examen {$vars['product_name']} — Instituto DOCEO\n\n"
            . "Certificación: {$vars['product_name']}\n"
            . "Alumno: {$vars['full_name']}\n"
            . "Matrícula: {$vars['matricula']}\n"
            . "Correo: {$vars['student_email']}\n"
            . "Teléfono: {$vars['student_phone']}\n"
            . "Fecha examen: {$vars['exam_date']}\n"
            . "Hora examen: {$vars['exam_time']}\n\n"
            . ($vars['reglamento_url'] !== '' ? "Reglamento: {$vars['reglamento_url']}\n" : '')
            . ($vars['comprobante_url'] !== '' ? "Comprobante: {$vars['comprobante_url']}\n" : '')
            . ($vars['workbook_note'] !== '' ? $vars['workbook_note'] . "\n" : '')
            . "\n— Instituto DOCEO\n";

        $html = '<p>Solicitud de registro examen <strong>' . htmlspecialchars($vars['product_name']) . '</strong></p><ul>'
            . '<li><strong>Alumno:</strong> ' . htmlspecialchars($vars['full_name']) . '</li>'
            . '<li><strong>Matrícula:</strong> ' . htmlspecialchars($vars['matricula']) . '</li>'
            . '<li><strong>Correo:</strong> ' . htmlspecialchars($vars['student_email']) . '</li>'
            . '<li><strong>Teléfono:</strong> ' . htmlspecialchars($vars['student_phone']) . '</li>'
            . '<li><strong>Fecha:</strong> ' . htmlspecialchars($vars['exam_date']) . '</li>'
            . '<li><strong>Hora:</strong> ' . htmlspecialchars($vars['exam_time']) . '</li>'
            . '</ul>' . $vars['documentos_html'];
        if ($vars['workbook_note'] !== '') {
            $html .= '<p>' . htmlspecialchars($vars['workbook_note']) . '</p>';
        }

        (new Mailer())->send($to, $subject, $text, array_merge($options, [
            'html' => true,
            'body_html' => $html,
        ]));
    }

    private function documentosHtml(string $reglamentoUrl, string $comprobanteUrl, bool $hasWorkbook): string
    {
        if ($reglamentoUrl === '' && $comprobanteUrl === '' && !$hasWorkbook) {
            return '';
        }
        $html = '<p><strong>Documentos:</strong></p><ul>';
        if ($reglamentoUrl !== '') {
            $html .= '<li><a href="' . htmlspecialchars($reglamentoUrl) . '">Reglamento firmado</a></li>';
        }
        if ($comprobanteUrl !== '') {
            $html .= '<li><a href="' . htmlspecialchars($comprobanteUrl) . '">Comprobante de pago</a></li>';
        }
        if ($hasWorkbook) {
            $html .= '<li>Plantilla Excel adjunta (si el envío incluye adjuntos)</li>';
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

    /** @param array<string, mixed> $extra *
    private function saveExtra(int $trackingId, array $extra): void
    {
        $this->pdo->prepare('UPDATE trackings SET extra_json = ?, updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);
    }

    public const ADMIN_PROOF_DOC_TYPE = 'provider_payment_proof';

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
        if ($config === null) {
            throw new \RuntimeException('Este producto no tiene solicitud a proveedor habilitada.');
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
            (string) $config['step_code'],
            'Comprobante de pago al proveedor subido por admin',
            $adminUserId
        );

        $sent = false;
        if (!empty($config['auto_send_on_admin_proof'])) {
            $this->send($trackingId, (int) $tracking['purchase_id'], $adminUserId, true);
            $sent = true;
        }

        return ['document_id' => $docId, 'sent' => $sent];
    }


}
