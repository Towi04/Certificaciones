<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Connection;
use App\Repositories\PartnerRepository;
use PDO;

/**
 * Envía la plantilla configurada en un paso de Operación / progreso.
 * Destinatario según audiencia de la plantilla (alumno, partner o proveedor fijo).
 * La solicitud inicial UKS (reglamento/pago/workbook) usa ProviderRequestService.
 */
final class StepMailService
{
    private PDO $pdo;
    private TrackingService $tracking;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->tracking = new TrackingService();
    }

    /**
     * @return array{to:string,template:string,audience:string}
     */
    public function sendForStep(int $trackingId, string $stepCode, ?int $actorUserId = null): array
    {
        $stepCode = trim($stepCode);
        if ($stepCode === '') {
            throw new \InvalidArgumentException('Indica el paso a enviar.');
        }

        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        $product = [
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
            'id' => $tracking['product_id'] ?? 0,
            'name' => $tracking['product_name'] ?? '',
            'code' => $tracking['product_code'] ?? '',
        ];
        $cfg = CheckoutRequirements::config($product);
        $defs = GroupStepConfig::defsFromConfig($cfg);
        $def = is_array($defs[$stepCode] ?? null) ? $defs[$stepCode] : null;
        if ($def === null) {
            throw new \InvalidArgumentException('El paso «' . $stepCode . '» no está en el grupo.');
        }

        $emailCfg = is_array($def['email'] ?? null) ? $def['email'] : [];
        $tplCode = trim((string) ($emailCfg['template_code'] ?? ''));
        $delivery = ResultsDeliveryService::fromConfig($cfg);
        $state = ResultsDeliveryService::stateFromTracking($tracking);
        $requiresResults = ResultsDeliveryService::stepRequiresResults($def, $delivery, $tplCode);
        $attachments = [];

        if ($requiresResults) {
            if ($state['cancelled']) {
                $tplCode = trim((string) ($delivery['cancel_template'] ?? ''));
                if ($tplCode === '') {
                    throw new \InvalidArgumentException(
                        'Configura la plantilla de cancelación en el grupo (pestaña Resultados).'
                    );
                }
            } elseif (!ResultsDeliveryService::isReady($tracking, $delivery)) {
                throw new \InvalidArgumentException(ResultsDeliveryService::blockedReason($tracking, $delivery));
            }
        }

        if ($tplCode === '') {
            throw new \InvalidArgumentException('El paso no tiene plantilla de correo configurada.');
        }
        if (empty($emailCfg['enabled']) && !$requiresResults) {
            throw new \InvalidArgumentException('El paso no tiene «Enviar correo» activado.');
        }

        if (MailTemplateService::isUksSolicitudCode($tplCode)) {
            throw new \InvalidArgumentException(
                'La plantilla de solicitud UKS se envía con el botón de solicitud a proveedor.'
            );
        }

        $attachments = ResultsDeliveryService::pdfAttachments($tracking, $delivery);

        $audience = MailTemplateService::audienceForTemplate($tplCode);
        $vars = array_merge(
            $this->buildVars($tracking),
            ResultsDeliveryService::mailVars($tracking, $delivery)
        );
        $mail = new MailTemplateService();
        $to = $this->resolveRecipient($tracking, $audience, $tplCode, $mail);
        if ($mail->render($tplCode, $vars) === null) {
            throw new \RuntimeException('Plantilla no encontrada o desactivada: ' . $tplCode);
        }
        $options = [];
        if ($attachments !== []) {
            $options['attachments'] = $attachments;
        }
        $mail->send($tplCode, $to, $vars, $options);

        $this->markSent($trackingId, $stepCode, $actorUserId, $to, $tplCode, $audience);
        $this->tracking->markStepDone(
            $trackingId,
            $stepCode,
            $actorUserId,
            'Correo «' . $tplCode . '» enviado a ' . $to . ' (' . $audience . ')'
        );

        return [
            'to' => $to,
            'template' => $tplCode,
            'audience' => $audience,
        ];
    }

    /**
     * @param array<string, mixed> $tracking
     * @return array<string, string>
     */
    public function buildVars(array $tracking): array
    {
        $name = trim((string) (($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? '')));
        $fullName = trim($name . ' ' . (string) ($tracking['last_name_m'] ?? ''));
        $partner = $this->partnerRowForTracking($tracking);
        $loginUrl = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/') . '/login';
        $moodleUrl = trim((string) (Env::get('MOODLE_URL', '') ?? ''));
        $examUrl = trim((string) (Env::get('ELET_EXAM_URL', '') ?? ''));
        $product = [
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
            'id' => $tracking['product_id'] ?? 0,
            'name' => $tracking['product_name'] ?? '',
            'code' => $tracking['product_code'] ?? '',
        ];
        $cfg = CheckoutRequirements::config($product);
        $examCfg = is_array($cfg['exam'] ?? null) ? $cfg['exam'] : [];
        $cfgExamUrl = trim((string) ($examCfg['url'] ?? ''));
        if ($cfgExamUrl !== '') {
            $examUrl = $cfgExamUrl;
        }

        $delivery = ResultsDeliveryService::fromConfig($cfg);
        $mailResultVars = ResultsDeliveryService::mailVars($tracking, $delivery);
        $extraFields = GroupExtraFields::fromGroupConfig(is_array($cfg) ? $cfg : []);
        $extraValues = GroupExtraFields::valuesFromTracking($tracking);
        $extraMailVars = GroupExtraFields::mailVars($extraFields, $extraValues);

        $vars = [
            'name' => $name,
            'full_name' => $fullName !== '' ? $fullName : $name,
            'first_name' => (string) ($tracking['first_name'] ?? ''),
            'last_name_p' => (string) ($tracking['last_name_p'] ?? ''),
            'last_name_m' => (string) ($tracking['last_name_m'] ?? ''),
            'student_email' => (string) ($tracking['student_email'] ?? ''),
            'student_phone' => (string) ($tracking['student_phone'] ?? ''),
            'matricula' => (string) ($tracking['matricula'] ?? ''),
            'product_name' => (string) ($tracking['product_name'] ?? ''),
            'certificacion' => (string) ($tracking['product_name'] ?? ''),
            'exam_date' => (string) ($tracking['exam_date'] ?? ''),
            'exam_time' => !empty($tracking['exam_time'])
                ? substr((string) $tracking['exam_time'], 0, 5)
                : '',
            'folio' => (string) ($tracking['folio'] ?? ''),
            'access_key' => (string) ($tracking['access_key'] ?? ''),
            'zoom_url' => (string) ($tracking['zoom_url'] ?? ''),
            'zoom' => (string) ($tracking['zoom_url'] ?? ''),
            'extra' => (string) ($tracking['zoom_url'] ?? ''),
            'extra_label' => AdminOpsBoardService::extraFieldLabelFromConfig(
                is_array($cfg) ? $cfg : []
            ),
            'zoom_label' => AdminOpsBoardService::extraFieldLabelFromConfig(
                is_array($cfg) ? $cfg : []
            ),
            'exam_url' => $examUrl,
            'login_url' => $loginUrl,
            'moodle_url' => $moodleUrl,
            'moodle_username' => (string) ($tracking['moodle_username'] ?? ''),
            'moodle_password' => (string) ($tracking['moodle_password'] ?? ''),
            'moodle_access_starts_at' => (string) ($tracking['moodle_access_starts_at'] ?? ''),
            'moodle_access_ends_at' => (string) ($tracking['moodle_access_ends_at'] ?? ''),
            'cenni_folio' => (string) ($tracking['cenni_folio'] ?? ''),
            'results_level' => (string) ($tracking['results_level'] ?? ''),
            'results_score' => (string) ($tracking['results_score'] ?? ''),
            'results_url' => (string) ($tracking['results_url'] ?? ''),
            'score_report_url' => '',
            'results_pdf_url' => '',
            'cancel_reason' => '',
            'partner_email' => (string) ($partner['email'] ?? ''),
            'partner_name' => (string) ($partner['display_name'] ?? ''),
            'partner_code' => (string) ($partner['code'] ?? ''),
            'amount' => isset($tracking['charged_amount']) && $tracking['charged_amount'] !== '' && $tracking['charged_amount'] !== null
                ? money($tracking['charged_amount'])
                : '',
            'pay_instructions_html' => '',
            'password_block_html' => '',
            'temp_password' => '',
        ];

        $vars = array_merge($vars, $extraMailVars, $mailResultVars);

        return array_merge($vars, ExamInstructionAssets::mailVars($cfg));
    }

    /**
     * @param array<string, mixed> $tracking
     */
    public function resolveRecipient(
        array $tracking,
        string $audience,
        string $templateCode = '',
        ?MailTemplateService $mail = null
    ): string {
        $audience = MailTemplateService::normalizeAudience($audience);
        if ($audience === 'provider') {
            $mail ??= new MailTemplateService();
            $to = trim($mail->routing($templateCode)['to'] ?? '');
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException(
                    'Configura el correo del proveedor en Admin → Correos → plantilla «'
                    . $templateCode . '» (campo Para).'
                );
            }

            return $to;
        }
        if ($audience === 'partner') {
            $partner = $this->partnerRowForTracking($tracking);
            $email = trim((string) ($partner['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException(
                    'Este caso no tiene partner con correo. '
                    . 'El alumno debió inscribirse con código de partner o ser registrado por un partner.'
                );
            }

            return $email;
        }

        $email = trim((string) ($tracking['student_email'] ?? $tracking['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('El alumno no tiene un correo válido.');
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $tracking
     * @return array<string, mixed>|null
     */
    public function partnerRowForTracking(array $tracking): ?array
    {
        $partnerId = (int) ($tracking['partner_id'] ?? 0);
        if ($partnerId < 1) {
            $purchaseId = (int) ($tracking['purchase_id'] ?? 0);
            if ($purchaseId > 0) {
                $stmt = $this->pdo->prepare('SELECT partner_id FROM purchases WHERE id = ? LIMIT 1');
                $stmt->execute([$purchaseId]);
                $partnerId = (int) $stmt->fetchColumn();
            }
        }
        if ($partnerId < 1) {
            return null;
        }

        return (new PartnerRepository())->find($partnerId);
    }

    private function markSent(
        int $trackingId,
        string $stepCode,
        ?int $actorUserId,
        string $to,
        string $templateCode,
        string $audience
    ): void {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return;
        }
        $extra = [];
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        }
        $sentMap = is_array($extra['step_mail_sent'] ?? null) ? $extra['step_mail_sent'] : [];
        $sentMap[$stepCode] = [
            'at' => date('c'),
            'by' => $actorUserId,
            'to' => $to,
            'template' => $templateCode,
            'audience' => $audience,
        ];
        $extra['step_mail_sent'] = $sentMap;
        $this->pdo->prepare('UPDATE trackings SET extra_json = ? WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);
    }
}
