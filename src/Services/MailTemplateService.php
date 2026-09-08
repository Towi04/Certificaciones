<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Integrations\Mailer;
use App\Mail\MailBranding;
use App\Repositories\MailTemplateRepository;
use App\Support\Settings;

final class MailTemplateService
{
    public const UKS_SOLICITUD = 'uks_solicitud';

    /** @deprecated alias migrado a uks_solicitud */
    public const UKS_SOLICITUD_LEGACY = 'uks_elet_solicitud';

    /** @var array<string, array<string, string>> */
    private const PLACEHOLDER_OPTIONS = [
        'Alumno' => [
            'name' => 'Nombre corto',
            'full_name' => 'Nombre completo',
            'first_name' => 'Nombre(s)',
            'last_name_p' => 'Apellido paterno',
            'last_name_m' => 'Apellido materno',
            'student_email' => 'Correo del alumno',
            'student_phone' => 'Teléfono del alumno',
        ],
        'Caso / compra' => [
            'matricula' => 'Matrícula / caso',
            'product_name' => 'Producto',
            'certificacion' => 'Certificación',
            'amount' => 'Monto',
            'login_url' => 'URL de login',
        ],
        'Pago y cuenta' => [
            'pay_instructions_html' => 'Instrucciones de pago (HTML)',
            'password_block_html' => 'Bloque usuario/contraseña (HTML)',
        ],
        'Examen ELeT' => [
            'exam_url' => 'URL del examen',
            'exam_date' => 'Fecha de examen',
            'exam_time' => 'Hora de examen',
            'folio' => 'Folio UKS / examen',
            'access_key' => 'Clave del día',
        ],
        'Documentos (enlaces)' => [
            'reglamento_url' => 'URL reglamento firmado',
            'pago_proveedor' => 'URL comprobante DOCEO → proveedor',
            'comprobante_url' => 'URL comprobante (alias de pago_proveedor)',
            'workbook_url' => 'URL plantilla Excel rellenada',
            'documentos_html' => 'Lista HTML de enlaces a documentos',
            'attachment_note' => 'Nota: documentos por enlace (sin adjuntos)',
            'workbook_note' => 'Nota breve del Excel por enlace',
        ],
        'Resultados / CENNI' => [
            'results_level' => 'Nivel alcanzado',
            'results_score' => 'Puntaje',
            'results_url' => 'URL certificado',
            'cenni_folio' => 'Folio CENNI',
            'sep_consulta_url' => 'URL consulta SEP',
        ],
        'Partner' => [
            'partner_name' => 'Nombre del partner',
            'partner_code' => 'Código del partner',
            'partner_email' => 'Correo del partner',
        ],
    ];

    private MailTemplateRepository $repo;

    public function __construct()
    {
        $this->repo = new MailTemplateRepository();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->repo->all();
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        return $this->repo->findByCode($code);
    }

    /** Código efectivo de plantilla UKS (nueva o legada). */
    public function uksSolicitudCode(): string
    {
        if ($this->repo->findByCode(self::UKS_SOLICITUD) !== null) {
            return self::UKS_SOLICITUD;
        }

        return self::UKS_SOLICITUD_LEGACY;
    }

    /**
     * @param array<string, string> $vars
     * @return array{subject: string, body_html: string, body_text: string}|null
     */
    public function render(string $code, array $vars): ?array
    {
        $tpl = $this->repo->findByCode($code);
        if ($tpl === null || !(int) ($tpl['is_active'] ?? 0)) {
            return null;
        }

        $subject = self::interpolate((string) $tpl['subject'], $vars);
        $bodyHtml = self::interpolate((string) $tpl['body_html'], $vars);

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => self::textFromHtml($bodyHtml),
        ];
    }

    /**
     * @param array<string, string> $vars
     * @return array{subject: string, body_html: string, body_text: string}|null
     */
    public function renderUksSolicitud(array $vars): ?array
    {
        $rendered = $this->render(self::UKS_SOLICITUD, $vars);
        if ($rendered !== null) {
            return $rendered;
        }

        return $this->render(self::UKS_SOLICITUD_LEGACY, $vars);
    }

    /**
     * @param array<string, string> $vars
     * @param array{
     *   cc?: string|null,
     *   attachments?: list<array{path: string, name?: string, mime?: string}>,
     *   prefer_smtp?: bool
     * } $options
     */
    public function send(string $code, string $to, array $vars, array $options = []): void
    {
        $rendered = $this->render($code, $vars);
        if ($rendered === null) {
            throw new \RuntimeException('Plantilla de correo no encontrada o desactivada: ' . $code);
        }

        $routing = $this->routing($code);
        if (self::isProviderTemplate($code) && trim($to) === '' && $routing['to'] !== '') {
            $to = $routing['to'];
        }

        $ccSource = trim((string) ($options['cc'] ?? ''));
        if ($ccSource === '') {
            $ccSource = $routing['cc'];
        }
        $resolvedCc = self::resolveAddressList($ccSource, $vars);
        if ($resolvedCc !== '') {
            $options['cc'] = $resolvedCc;
        } else {
            unset($options['cc']);
        }

        $this->deliver($to, $rendered, $options);
    }

    /**
     * @param array<string, string> $vars
     * @param array<string, mixed> $options
     */
    public function sendUksSolicitud(string $to, array $vars, array $options = []): void
    {
        $rendered = $this->renderUksSolicitud($vars);
        if ($rendered === null) {
            throw new \RuntimeException('Plantilla UKS solicitud no encontrada o desactivada.');
        }

        $routing = $this->routing($this->uksSolicitudCode());
        if ($routing['to'] !== '') {
            $to = $routing['to'];
        }
        $ccSource = trim((string) ($options['cc'] ?? ''));
        if ($ccSource === '') {
            $ccSource = $routing['cc'];
        }
        $resolvedCc = self::resolveAddressList($ccSource, $vars);
        if ($resolvedCc !== '') {
            $options['cc'] = $resolvedCc;
        } else {
            unset($options['cc']);
        }

        $this->deliver($to, $rendered, $options);
    }

    /** Destinatario UKS configurado en la plantilla (producción). */
    public function uksSolicitudRecipient(): string
    {
        return $this->routing($this->uksSolicitudCode())['to'];
    }

    /** @return array{to: string, cc: string} */
    public function routing(string $code): array
    {
        $to = trim(Settings::get('mail_tpl_' . $code . '_to', '') ?? '');
        if ($to === '' && in_array($code, [self::UKS_SOLICITUD, self::UKS_SOLICITUD_LEGACY], true)) {
            $to = trim(Settings::get('uks_elet_request_email', '') ?? '');
        }

        return [
            'to' => $to,
            'cc' => trim(Settings::get('mail_tpl_' . $code . '_cc', '') ?? ''),
        ];
    }

    public function saveRouting(string $code, string $to, string $cc = ''): void
    {
        Settings::set('mail_tpl_' . $code . '_to', $to);
        Settings::set('mail_tpl_' . $code . '_cc', $cc);

        if (in_array($code, [self::UKS_SOLICITUD, self::UKS_SOLICITUD_LEGACY], true)) {
            Settings::set('uks_elet_request_email', $to);
        }
    }

    /** Destinatario configurado: student | provider | partner. */
    public function audience(string $code): string
    {
        return self::audienceForTemplate($code);
    }

    public function saveAudience(string $code, string $audience): void
    {
        Settings::set('mail_tpl_' . $code . '_audience', self::normalizeAudience($audience));
    }

    public static function normalizeAudience(string $audience): string
    {
        return match (strtolower(trim($audience))) {
            'provider' => 'provider',
            'partner' => 'partner',
            default => 'student',
        };
    }

    /**
     * Config Excel asociada a una plantilla (Settings JSON).
     *
     * @return array{enabled:bool,template_path:string,sheet:string,normalize:string,cell_map:list<array{cell:string,field:string}>}
     */
    public static function workbookConfig(string $code): array
    {
        $raw = Settings::get('mail_tpl_' . $code . '_workbook', '') ?? '';
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($decoded) ? $decoded : [];
        $cellMap = [];
        foreach (is_array($data['cell_map'] ?? null) ? $data['cell_map'] : [] as $item) {
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

        return [
            'enabled' => !empty($data['enabled']),
            'template_path' => trim((string) ($data['template_path'] ?? '')),
            'sheet' => trim((string) ($data['sheet'] ?? '')),
            'normalize' => in_array((string) ($data['normalize'] ?? 'none'), ['none', 'toefl'], true)
                ? (string) ($data['normalize'] ?? 'none')
                : 'none',
            'cell_map' => $cellMap,
        ];
    }

    /**
     * @param array{enabled?:bool,template_path?:string,sheet?:string,normalize?:string,cell_map?:list<array{cell?:string,field?:string}>} $workbook
     */
    public static function saveWorkbookConfig(string $code, array $workbook): void
    {
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
        $normalized = [
            'enabled' => !empty($workbook['enabled']),
            'template_path' => trim((string) ($workbook['template_path'] ?? '')),
            'sheet' => trim((string) ($workbook['sheet'] ?? '')),
            'normalize' => in_array((string) ($workbook['normalize'] ?? 'none'), ['none', 'toefl'], true)
                ? (string) ($workbook['normalize'] ?? 'none')
                : 'none',
            'cell_map' => $cellMap,
        ];
        Settings::set('mail_tpl_' . $code . '_workbook', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    public function requiresFixedRecipient(string $code): bool
    {
        return self::isProviderTemplate($code);
    }

    /** Plantillas con destinatario fijo (proveedor / UKS), no al alumno. */
    public static function isProviderTemplate(string $code): bool
    {
        return self::audienceForTemplate($code) === 'provider';
    }

    /**
     * audience: preferencia guardada en settings; si no hay, heurística por código.
     */
    public static function audienceForTemplate(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return 'student';
        }
        $saved = strtolower(trim(Settings::get('mail_tpl_' . $code . '_audience', '') ?? ''));
        if (in_array($saved, ['provider', 'student', 'partner'], true)) {
            return $saved;
        }
        if (self::partnerTemplateHeuristic($code)) {
            return 'partner';
        }

        return self::providerTemplateHeuristic($code) ? 'provider' : 'student';
    }

    /** Heurística legacy por código (uks_*, *_provider*, *proveedor*). */
    public static function providerTemplateHeuristic(string $code): bool
    {
        $code = trim($code);

        return in_array($code, [self::UKS_SOLICITUD, self::UKS_SOLICITUD_LEGACY], true)
            || str_starts_with($code, 'uks_')
            || str_contains($code, '_provider')
            || str_contains($code, 'proveedor');
    }

    /** Heurística por código (*partner*). */
    public static function partnerTemplateHeuristic(string $code): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        return str_starts_with($code, 'partner_')
            || str_contains($code, '_partner')
            || str_ends_with($code, '_partner')
            || $code === 'partner';
    }

    /** @return array<string, array<string, string>> */
    public static function availablePlaceholderOptions(): array
    {
        return self::PLACEHOLDER_OPTIONS;
    }

    /** @return list<string> */
    public static function defaultPlaceholdersForCode(string $code): array
    {
        $uks = [
            'certificacion', 'product_name', 'full_name', 'matricula', 'student_email',
            'exam_date', 'exam_time', 'reglamento_url', 'pago_proveedor', 'comprobante_url',
            'workbook_url', 'documentos_html', 'attachment_note', 'workbook_note',
        ];

        return match ($code) {
            self::UKS_SOLICITUD, self::UKS_SOLICITUD_LEGACY => $uks,
            'student_elet_exam_access' => [
                'name', 'matricula', 'exam_url', 'exam_date', 'exam_time', 'folio', 'access_key',
            ],
            'student_registration' => [
                'full_name', 'matricula', 'product_name', 'amount', 'pay_instructions_html',
                'password_block_html', 'login_url',
            ],
            'student_payment_confirmed' => ['name', 'matricula', 'product_name'],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $template
     * @return list<string>
     */
    public static function placeholdersForTemplate(array $template): array
    {
        $raw = $template['required_fields_json'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::sanitizePlaceholders($decoded);
            }
        } elseif (is_array($raw)) {
            return self::sanitizePlaceholders($raw);
        }

        return self::defaultPlaceholdersForCode((string) ($template['code'] ?? ''));
    }

    /**
     * @param list<mixed> $raw
     * @return list<string>
     */
    public static function sanitizePlaceholders(array $raw): array
    {
        $allowed = self::availablePlaceholderKeys();
        $out = [];
        foreach ($raw as $value) {
            $key = is_string($value) ? self::normalizePlaceholderKey($value) : '';
            if ($key !== '' && in_array($key, $allowed, true) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function availablePlaceholderKeys(): array
    {
        $keys = [];
        foreach (self::PLACEHOLDER_OPTIONS as $group) {
            foreach ($group as $key => $_label) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param array{subject: string, body_html: string, body_text: string} $rendered
     * @param array<string, mixed> $options
     */
    private function deliver(string $to, array $rendered, array $options = []): void
    {
        $bodyHtml = (string) $rendered['body_html'];
        if (empty($options['raw_html'])) {
            $bodyHtml = MailBranding::wrapIfNeeded($bodyHtml);
        }

        (new Mailer())->send(
            $to,
            $rendered['subject'],
            $rendered['body_text'],
            array_merge($options, [
                'html' => true,
                'body_html' => $bodyHtml,
            ])
        );
    }

    /**
     * Prueba: SMTP primero (auto) y HTML sin doble envoltura.
     *
     * @return array{subject: string, log_path: ?string}
     */
    public function sendUksSolicitudTest(string $to): array
    {
        $rendered = $this->renderUksSolicitud(self::uksSolicitudSampleVars());
        if ($rendered === null) {
            throw new \RuntimeException(
                'Plantilla UKS no encontrada o desactivada. Actívala en esta página y guarda.'
            );
        }

        return $this->sendTestRendered(
            $to,
            $rendered,
            'Correo de prueba DOCEO. En producción los documentos van como enlaces seguros, no adjuntos.'
        );
    }

    /**
     * @return array{subject: string, log_path: ?string}
     */
    public function sendTemplateTest(string $code, string $to): array
    {
        $tpl = $this->repo->findByCode($code);
        $vars = self::sampleVarsForCode($code);
        if ($tpl !== null) {
            $vars = array_merge($vars, self::sampleVarsForPlaceholders(self::placeholdersForTemplate($tpl)));
        }
        $rendered = $this->render($code, $vars);
        if ($rendered === null) {
            throw new \RuntimeException('Plantilla no encontrada, desactivada o sin datos de prueba.');
        }

        return $this->sendTestRendered(
            $to,
            $rendered,
            'Correo de prueba DOCEO con datos de ejemplo.'
        );
    }

    /**
     * @param array{subject: string, body_html: string, body_text: string} $rendered
     * @return array{subject: string, log_path: ?string}
     */
    private function sendTestRendered(string $to, array $rendered, string $note): array
    {
        $subject = '[PRUEBA] ' . $rendered['subject'];
        $noteBlock = '<p style="margin-top:1.25rem;padding:.75rem;background:#fffbeb;border-radius:8px;font-size:.85rem;color:#92400e">'
            . htmlspecialchars($note, ENT_QUOTES, 'UTF-8')
            . '</p>';
        $inner = MailBranding::appendBlock((string) $rendered['body_html'], $noteBlock);
        $html = MailBranding::wrapIfNeeded($inner);
        $text = $rendered['body_text'] . "\n\n[" . $note . ']';

        (new Mailer())->send($to, $subject, $text, [
            'html' => true,
            'body_html' => $html,
            'prefer_smtp' => true,
            'force_smtp' => true,
            'smtp_only' => true,
        ]);

        $logPath = $this->logOutboundMail($to, $subject, $text, $html);

        return ['subject' => $subject, 'log_path' => $logPath];
    }

    private function logOutboundMail(string $to, string $subject, string $text, string $html): ?string
    {
        try {
            $dir = BASE_PATH . '/storage/logs/mail';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return null;
            }
            $path = $dir . '/test-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
            $json = json_encode([
                'to' => $to,
                'subject' => $subject,
                'body_text' => $text,
                'body_html' => $html,
                'transport' => Mailer::lastEndpoint(),
                'smtp_transport_env' => Env::get('SMTP_TRANSPORT', 'auto'),
                'delivery_errors' => Mailer::lastErrors(),
                'created_at' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false && file_put_contents($path, $json) !== false) {
                return $path;
            }
        } catch (\Throwable) {
            // no bloquear envío
        }

        return null;
    }

    /** @return array<string, string> */
    public static function sampleVarsForCode(string $code): array
    {
        if (in_array($code, [self::UKS_SOLICITUD, self::UKS_SOLICITUD_LEGACY], true)) {
            return self::uksSolicitudSampleVars();
        }

        return match ($code) {
            'student_elet_exam_access' => [
                'name' => 'María Ejemplo',
                'matricula' => '9999',
                'exam_url' => 'https://exam.elet.com.mx/',
                'exam_date' => date('Y-m-d', strtotime('+7 days')),
                'exam_time' => '10:00',
                'folio' => 'FOLIO-12345',
                'access_key' => 'CLAVE-DIA',
            ],
            'student_registration' => [
                'full_name' => 'María Ejemplo',
                'matricula' => '9999',
                'product_name' => 'ELeT',
                'amount' => '$1,350.00',
                'pay_instructions_html' => 'Recibimos tu comprobante. Validaremos el pago y te avisaremos.',
                'password_block_html' => '<p><strong>Usuario:</strong> alumno@ejemplo.com<br><strong>Contraseña temporal:</strong> Doceo*1234</p>',
                'login_url' => rtrim((string) (\App\Config\Env::get('APP_URL', '') ?? ''), '/') . '/login',
            ],
            'student_payment_confirmed' => [
                'name' => 'María Ejemplo',
                'matricula' => '9999',
                'product_name' => 'ELeT',
            ],
            default => [],
        };
    }

    /**
     * @param list<string> $placeholders
     * @return array<string, string>
     */
    public static function sampleVarsForPlaceholders(array $placeholders): array
    {
        $samples = array_merge(self::uksSolicitudSampleVars(), [
            'name' => 'María Ejemplo',
            'first_name' => 'María',
            'last_name_p' => 'Ejemplo',
            'last_name_m' => 'Demo',
            'student_phone' => '555-010-0000',
            'amount' => '$1,350.00',
            'pay_instructions_html' => 'Completa tu pago SPEI con la CLABE de tu caso.',
            'password_block_html' => '<p><strong>Usuario:</strong> alumno@ejemplo.com<br><strong>Contraseña temporal:</strong> Doceo*1234</p>',
            'login_url' => rtrim((string) (Env::get('APP_URL', '') ?? 'https://pdv.institutodoceo.com'), '/') . '/login',
            'exam_url' => 'https://exam.elet.com.mx/',
            'folio' => 'FOLIO-12345',
            'access_key' => 'CLAVE-DIA',
            'results_level' => 'B2',
            'results_score' => '82',
            'results_url' => 'https://certificados.example/elet/9999',
            'cenni_folio' => 'CENNI-ABC-123',
            'sep_consulta_url' => 'https://cennisistema.sep.gob.mx/cenni/consulta/consultaEstatus.jsp',
            'partner_name' => 'Partner Ejemplo',
            'partner_code' => 'PARTNER01',
            'partner_email' => 'partner@ejemplo.com',
        ]);

        $out = [];
        foreach ($placeholders as $key) {
            $out[$key] = $samples[$key] ?? ('Ejemplo ' . $key);
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function uksSolicitudSampleVars(): array
    {
        return [
            'certificacion' => 'ELeT',
            'product_name' => 'ELeT',
            'full_name' => 'María Ejemplo',
            'matricula' => '9999',
            'student_email' => 'alumno@ejemplo.com',
            'exam_date' => date('Y-m-d', strtotime('+7 days')),
            'exam_time' => '10:00',
            'reglamento_url' => rtrim((string) (Env::get('APP_URL', '') ?? 'https://pdv.institutodoceo.com'), '/') . '/archivo/ejemplo-prueba',
            'pago_proveedor' => rtrim((string) (Env::get('APP_URL', '') ?? 'https://pdv.institutodoceo.com'), '/') . '/archivo/ejemplo-comprobante',
            'comprobante_url' => rtrim((string) (Env::get('APP_URL', '') ?? 'https://pdv.institutodoceo.com'), '/') . '/archivo/ejemplo-comprobante',
            'workbook_url' => rtrim((string) (Env::get('APP_URL', '') ?? 'https://pdv.institutodoceo.com'), '/') . '/archivo/ejemplo-excel',
            'documentos_html' => '<p><strong>Documentos:</strong></p><ul>'
                . '<li><a href="#">Reglamento firmado</a></li>'
                . '<li><a href="#">Comprobante pago al proveedor</a></li>'
                . '<li><a href="#">Plantilla Excel</a></li>'
                . '</ul>',
            'attachment_note' => 'Documentos por enlace (sin adjuntos en el correo).',
            'workbook_note' => 'Plantilla Excel disponible por enlace seguro.',
        ];
    }

    /** @param array<string, string> $vars */
    public static function interpolate(string $template, array $vars): string
    {
        $lookup = [];
        foreach ($vars as $key => $value) {
            $norm = self::normalizePlaceholderKey((string) $key);
            if ($norm === '') {
                continue;
            }
            $lookup[$norm] = (string) $value;
        }

        // Alias comunes: name ↔ full_name, pago_proveedor ↔ comprobante_url
        if (!isset($lookup['full_name']) && isset($lookup['name'])) {
            $lookup['full_name'] = $lookup['name'];
        }
        if (!isset($lookup['name']) && isset($lookup['full_name'])) {
            $lookup['name'] = $lookup['full_name'];
        }
        if (!isset($lookup['pago_proveedor']) && isset($lookup['comprobante_url'])) {
            $lookup['pago_proveedor'] = $lookup['comprobante_url'];
        }
        if (!isset($lookup['comprobante_url']) && isset($lookup['pago_proveedor'])) {
            $lookup['comprobante_url'] = $lookup['pago_proveedor'];
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_\- ]+?)\s*\}\}/u',
            static function (array $m) use ($lookup): string {
                $key = self::normalizePlaceholderKey($m[1]);
                if ($key !== '' && array_key_exists($key, $lookup)) {
                    return $lookup[$key];
                }

                return $m[0];
            },
            $template
        );
    }

    /**
     * Resuelve una lista de correos (CC) con placeholders.
     * Si {{partner_email}} queda vacío (sin partner), no se agrega a nadie.
     *
     * @param array<string, string> $vars
     */
    public static function resolveAddressList(string $raw, array $vars): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $interpolated = self::interpolate($raw, $vars);
        // Quitar placeholders no resueltos (p. ej. {{partner_email}} sin partner).
        $interpolated = (string) preg_replace('/\{\{\s*[^}]+\s*\}\}/u', '', $interpolated);
        $parts = preg_split('/\s*,\s*/', $interpolated) ?: [];
        $valid = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !filter_var($part, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!in_array($part, $valid, true)) {
                $valid[] = $part;
            }
        }

        return implode(', ', $valid);
    }

    /** Normaliza etiquetas: "full name", "Full-Name" → "full_name". */
    public static function normalizePlaceholderKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[\s\-]+/', '_', $key) ?? $key;
        $key = preg_replace('/_+/', '_', $key) ?? $key;

        return trim($key, '_');
    }

    public static function textFromHtml(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param list<string> $placeholders
     */
    public function create(
        string $code,
        string $name,
        string $subject,
        string $bodyHtml,
        bool $isActive,
        array $placeholders,
        string $triggerMode = 'manual'
    ): void {
        $code = trim($code);
        $name = trim($name);
        $subject = trim($subject);
        if (!preg_match('/^[a-z0-9_]{3,60}$/', $code)) {
            throw new \InvalidArgumentException('El código debe usar minúsculas, números y guion bajo (3-60 caracteres).');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('Indica el nombre de la plantilla.');
        }
        if ($subject === '') {
            throw new \InvalidArgumentException('Indica el asunto de la plantilla.');
        }
        if (trim($bodyHtml) === '') {
            throw new \InvalidArgumentException('Indica el contenido HTML de la plantilla.');
        }
        if ($this->repo->findByCode($code) !== null) {
            throw new \InvalidArgumentException('Ya existe una plantilla con ese código.');
        }

        $placeholders = self::mergeUsedPlaceholders($subject, $bodyHtml, $placeholders);
        $triggerMode = in_array($triggerMode, ['automatic', 'manual'], true) ? $triggerMode : 'manual';

        $this->repo->create($code, $name, $subject, $bodyHtml, $triggerMode, $isActive, $placeholders);
    }

    /**
     * @param list<string>|null $placeholders
     */
    public function update(string $code, string $subject, string $bodyHtml, bool $isActive, ?array $placeholders = null): void
    {
        if ($this->repo->findByCode($code) === null) {
            throw new \InvalidArgumentException('Plantilla no encontrada.');
        }
        $subject = trim($subject);
        if ($subject === '') {
            throw new \InvalidArgumentException('Indica el asunto de la plantilla.');
        }
        if (trim($bodyHtml) === '') {
            throw new \InvalidArgumentException('Indica el contenido HTML de la plantilla.');
        }
        if ($placeholders !== null) {
            $placeholders = self::mergeUsedPlaceholders($subject, $bodyHtml, $placeholders);
        }

        $this->repo->update($code, $subject, $bodyHtml, $isActive, $placeholders);
    }

    /**
     * Une la lista del combobox con las etiquetas ya usadas en asunto/HTML.
     * Ya no se exige «seleccionar» antes de escribir {{etiqueta}}.
     *
     * @param list<string> $selected
     * @return list<string>
     */
    public static function mergeUsedPlaceholders(string $subject, string $bodyHtml, array $selected): array
    {
        $selected = self::sanitizePlaceholders($selected);
        $used = self::sanitizePlaceholders(self::extractPlaceholders($subject . "\n" . $bodyHtml));
        foreach ($used as $key) {
            if (!in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    /** @return list<string> */
    private static function extractPlaceholders(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_\- ]+?)\s*\}\}/u', $text, $matches);
        $out = [];
        foreach ($matches[1] ?? [] as $raw) {
            $key = self::normalizePlaceholderKey((string) $raw);
            if ($key !== '' && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Plantillas por defecto + migración UKS genérica. */
    public function ensureDefaults(): void
    {
        $this->migrateUksSolicitudTemplate();

        if ($this->repo->all() !== []) {
            return;
        }

        $this->seedDefaultTemplates();
    }

    public function migrateUksSolicitudTemplate(): void
    {
        $legacy = $this->repo->findByCode(self::UKS_SOLICITUD_LEGACY);
        $current = $this->repo->findByCode(self::UKS_SOLICITUD);

        if ($legacy !== null && $current === null) {
            $this->repo->renameCode(
                self::UKS_SOLICITUD_LEGACY,
                self::UKS_SOLICITUD,
                'UKS · Solicitud de examen (certificaciones)'
            );
        }

        if ($this->repo->findByCode(self::UKS_SOLICITUD) === null) {
            $this->repo->upsert(
                self::UKS_SOLICITUD,
                'UKS · Solicitud de examen (certificaciones)',
                'Solicitud {{certificacion}} · {{full_name}} · {{matricula}}',
                '<p>Solicitud de registro examen <strong>{{certificacion}}</strong> — Instituto DOCEO</p>'
                . '<ul>'
                . '<li><strong>Certificación:</strong> {{certificacion}}</li>'
                . '<li><strong>Alumno:</strong> {{full_name}}</li>'
                . '<li><strong>Matrícula DOCEO:</strong> {{matricula}}</li>'
                . '<li><strong>Correo:</strong> {{student_email}}</li>'
                . '<li><strong>Fecha examen:</strong> {{exam_date}}</li>'
                . '<li><strong>Hora examen:</strong> {{exam_time}}</li>'
                . '</ul>'
                . '{{documentos_html}}'
                . '<p>Enlaces: <a href="{{reglamento_url}}">Reglamento</a> · '
                . '<a href="{{pago_proveedor}}">Comprobante pago proveedor</a> · '
                . '<a href="{{workbook_url}}">Excel</a></p>'
                . '<p>— Instituto DOCEO</p>',
                'automatic'
            );
        }
    }

    private function seedDefaultTemplates(): void
    {
        $defaults = [
            [
                'code' => self::UKS_SOLICITUD,
                'name' => 'UKS · Solicitud de examen (certificaciones)',
                'subject' => 'Solicitud {{certificacion}} · {{full_name}} · {{matricula}}',
                'body' => '<p>Solicitud de registro examen <strong>{{certificacion}}</strong> — Instituto DOCEO</p>'
                    . '<ul>'
                    . '<li><strong>Certificación:</strong> {{certificacion}}</li>'
                    . '<li><strong>Alumno:</strong> {{full_name}}</li>'
                    . '<li><strong>Matrícula DOCEO:</strong> {{matricula}}</li>'
                    . '<li><strong>Correo:</strong> {{student_email}}</li>'
                    . '<li><strong>Fecha examen:</strong> {{exam_date}}</li>'
                    . '<li><strong>Hora examen:</strong> {{exam_time}}</li>'
                    . '</ul>'
                    . '{{documentos_html}}'
                    . '<p>— Instituto DOCEO</p>',
            ],
            [
                'code' => 'student_elet_exam_access',
                'name' => 'Alumno · Accesos examen ELeT',
                'subject' => 'Accesos a tu examen ELeT · {{matricula}}',
                'body' => '<p>Hola {{name}},</p>'
                    . '<p>Tu examen <strong>ELeT</strong> está programado para {{exam_date}} {{exam_time}}.</p>'
                    . '<p><strong>Acceso al examen:</strong><br>'
                    . '<a href="{{exam_url}}">{{exam_url}}</a></p>'
                    . '<ul>'
                    . '<li><strong>Folio (único):</strong> {{folio}}</li>'
                    . '<li><strong>Clave del día:</strong> {{access_key}}</li>'
                    . '</ul>'
                    . '<p>Matrícula DOCEO: {{matricula}}</p>'
                    . '<p>— Instituto DOCEO</p>',
            ],
            [
                'code' => 'student_registration',
                'name' => 'Alumno · Registro / bienvenida',
                'subject' => 'Tu caso {{matricula}} — Instituto DOCEO',
                'body' => '<p>Hola {{full_name}},</p>'
                    . '<p>Registramos tu adquisición de <strong>{{product_name}}</strong>.</p>'
                    . '<p><strong>Matrícula:</strong> {{matricula}}<br>'
                    . '<strong>Monto:</strong> {{amount}} MXN</p>'
                    . '<p>{{pay_instructions_html}}</p>'
                    . '{{password_block_html}}'
                    . '<p><a href="{{login_url}}">Iniciar sesión</a></p>'
                    . '<p>— Instituto DOCEO</p>',
            ],
            [
                'code' => 'student_payment_confirmed',
                'name' => 'Alumno · Pago confirmado',
                'subject' => 'Pago confirmado — caso {{matricula}}',
                'body' => '<p>Hola {{name}},</p>'
                    . '<p>Confirmamos el pago de tu caso <strong>{{matricula}}</strong> ({{product_name}}).</p>'
                    . '<p>Ya puedes dar seguimiento desde tu portal.</p>'
                    . '<p>— Instituto DOCEO</p>',
            ],
        ];

        foreach ($defaults as $tpl) {
            if ($this->repo->findByCode($tpl['code']) === null) {
                $this->repo->upsert($tpl['code'], $tpl['name'], $tpl['subject'], $tpl['body'], 'automatic');
            }
        }
    }
}
