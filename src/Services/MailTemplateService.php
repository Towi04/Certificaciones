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
        'Documentos UKS' => [
            'reglamento_url' => 'URL reglamento firmado',
            'comprobante_url' => 'URL comprobante de pago',
            'documentos_html' => 'Lista de documentos (HTML)',
            'attachment_note' => 'Nota de adjuntos/enlaces',
        ],
        'Resultados / CENNI' => [
            'results_level' => 'Nivel alcanzado',
            'results_score' => 'Puntaje',
            'results_url' => 'URL certificado',
            'cenni_folio' => 'Folio CENNI',
            'sep_consulta_url' => 'URL consulta SEP',
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
            'exam_date', 'exam_time', 'reglamento_url', 'comprobante_url', 'documentos_html', 'attachment_note',
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
            $key = is_string($value) ? trim($value) : '';
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

        $placeholders = self::sanitizePlaceholders($placeholders);
        $this->assertOnlySelectedPlaceholders($subject, $bodyHtml, $placeholders);
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
            $placeholders = self::sanitizePlaceholders($placeholders);
            $this->assertOnlySelectedPlaceholders($subject, $bodyHtml, $placeholders);
        }

        $this->repo->update($code, $subject, $bodyHtml, $isActive, $placeholders);
    }

    /**
     * @param list<string> $allowed
     */
    private function assertOnlySelectedPlaceholders(string $subject, string $bodyHtml, array $allowed): void
    {
        $used = self::extractPlaceholders($subject . "\n" . $bodyHtml);
        if ($allowed === []) {
            if ($used !== []) {
                throw new \InvalidArgumentException(
                    'Selecciona estos placeholders antes de usarlos: {{' . implode('}}, {{', $used) . '}}'
                );
            }
            return;
        }

        $invalid = array_values(array_diff($used, $allowed));
        if ($invalid !== []) {
            throw new \InvalidArgumentException(
                'Selecciona estos placeholders antes de usarlos: {{' . implode('}}, {{', $invalid) . '}}'
            );
        }
    }

    /** @return list<string> */
    private static function extractPlaceholders(string $text): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);
        $out = [];
        foreach ($matches[1] ?? [] as $key) {
            if (!in_array($key, $out, true)) {
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
