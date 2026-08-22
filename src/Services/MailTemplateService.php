<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Mailer;
use App\Repositories\MailTemplateRepository;

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

        $this->deliver($to, $rendered, $options);
    }

    /**
     * @param array{subject: string, body_html: string, body_text: string} $rendered
     * @param array<string, mixed> $options
     */
    private function deliver(string $to, array $rendered, array $options = []): void
    {
        (new Mailer())->send(
            $to,
            $rendered['subject'],
            $rendered['body_text'],
            array_merge($options, [
                'html' => true,
                'body_html' => $rendered['body_html'],
            ])
        );
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
        $sampleLink = url('/archivo/ejemplo-vista-previa');

        return [
            'certificacion' => 'ELeT',
            'product_name' => 'ELeT',
            'full_name' => 'Alumno de prueba DOCEO',
            'matricula' => '9999',
            'student_email' => 'alumno@ejemplo.com',
            'exam_date' => date('Y-m-d', strtotime('+7 days')),
            'exam_time' => '10:00',
            'reglamento_url' => $sampleLink,
            'comprobante_url' => '',
            'documentos_html' => '<p><strong>Documentos:</strong></p><ul>'
                . '<li><a href="' . htmlspecialchars($sampleLink) . '">Reglamento firmado</a></li>'
                . '</ul>'
                . '<p style="font-size:.85rem;color:#64748b">Enlaces seguros DOCEO (ejemplo en correo de prueba).</p>',
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
                    . '<p>{{attachment_note}}</p>'
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
