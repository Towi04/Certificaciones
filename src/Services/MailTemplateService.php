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
        if ($routing['cc'] !== '') {
            $options['cc'] = $routing['cc'];
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

    public function requiresFixedRecipient(string $code): bool
    {
        return in_array($code, [self::UKS_SOLICITUD, self::UKS_SOLICITUD_LEGACY], true);
    }

    /**
     * @param array{subject: string, body_html: string, body_text: string} $rendered
     * @param array<string, mixed> $options
     */
    private function deliver(string $to, array $rendered, array $options = []): void
    {
        $bodyHtml = (string) $rendered['body_html'];
        if (empty($options['raw_html']) && !str_contains(strtolower($bodyHtml), '<!doctype')) {
            $bodyHtml = MailBranding::wrap($bodyHtml);
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
     * Prueba UKS: mismo canal que /admin/salud (MailBranding + mail() local).
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
        $vars = self::sampleVarsForCode($code);
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
        $inner = $rendered['body_html']
            . '<p style="margin-top:1.25rem;padding:.75rem;background:#fffbeb;border-radius:8px;font-size:.85rem;color:#92400e">'
            . htmlspecialchars($note, ENT_QUOTES, 'UTF-8')
            . '</p>';
        $html = MailBranding::wrap($inner);
        $text = $rendered['body_text'] . "\n\n[" . $note . ']';

        (new Mailer())->send($to, $subject, $text, [
            'html' => true,
            'body_html' => $html,
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
            'reglamento_url' => '(enlace en producción)',
            'comprobante_url' => '',
            'documentos_html' => '<p><strong>Documentos:</strong></p><ul>'
                . '<li>Reglamento firmado (enlace seguro en el correo real)</li>'
                . '</ul>',
            'attachment_note' => 'Documentos por enlace (sin adjuntos en el correo).',
        ];
    }

    /** @param array<string, string> $vars */
    public static function interpolate(string $template, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static function (array $m) use ($vars): string {
                $key = $m[1];

                return $vars[$key] ?? $m[0];
            },
            $template
        );
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

    public function update(string $code, string $subject, string $bodyHtml, bool $isActive): void
    {
        if ($this->repo->findByCode($code) === null) {
            throw new \InvalidArgumentException('Plantilla no encontrada.');
        }
        $this->repo->update($code, $subject, $bodyHtml, $isActive);
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
