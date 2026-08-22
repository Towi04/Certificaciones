<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Mailer;
use App\Repositories\MailTemplateRepository;

final class MailTemplateService
{
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
     * @param array{
     *   cc?: string|null,
     *   attachments?: list<array{path: string, name?: string, mime?: string}>
     * } $options
     */
    public function send(string $code, string $to, array $vars, array $options = []): void
    {
        $rendered = $this->render($code, $vars);
        if ($rendered === null) {
            throw new \RuntimeException('Plantilla de correo no encontrada o desactivada: ' . $code);
        }

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

    /** Crea plantillas por defecto si la tabla está vacía (instalaciones existentes). */
    public function ensureDefaults(): void
    {
        if ($this->repo->all() !== []) {
            return;
        }

        $defaults = [
            [
                'code' => 'uks_elet_solicitud',
                'name' => 'UKS · Solicitud examen ELeT',
                'subject' => 'Solicitud examen ELeT · {{full_name}} · {{matricula}}',
                'body' => '<p>Solicitud de registro examen <strong>ELeT</strong> — Instituto DOCEO</p>'
                    . '<ul>'
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
            $this->repo->upsert($tpl['code'], $tpl['name'], $tpl['subject'], $tpl['body'], 'automatic');
        }
    }
}
