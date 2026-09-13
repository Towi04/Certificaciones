<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Entrega de resultados configurable por grupo (sin modos fijos).
 *
 * config_json.results_delivery:
 * {
 *   "fields": [
 *     {"code":"cert_url","label":"Certificado","type":"url","placeholder":"results_url","required":true},
 *     {"code":"score_pdf","label":"Score PDF","type":"pdf","placeholder":"results_pdf_url","required":false}
 *   ],
 *   "cancel_template": "student_exam_cancelled"
 * }
 *
 * trackings.extra_json.results_delivery:
 * {
 *   "values": {"cert_url":"https://…","score_pdf":{"path":"…","name":"…"}},
 *   "cancelled": false,
 *   "cancel_reason": ""
 * }
 */
final class ResultsDeliveryService
{
    public const TYPE_URL = 'url';
    public const TYPE_PDF = 'pdf';
    public const TYPE_TEXT = 'text';

    public const FIELD_TYPES = [
        self::TYPE_URL => 'URL / enlace',
        self::TYPE_PDF => 'Archivo PDF',
        self::TYPE_TEXT => 'Texto',
    ];

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   fields:list<array{code:string,label:string,type:string,placeholder:string,required:bool}>,
     *   enabled:bool,
     *   cancel_template:string
     * }
     */
    public static function fromConfig(array $config): array
    {
        $raw = is_array($config['results_delivery'] ?? null) ? $config['results_delivery'] : [];
        $fields = self::normalizeFields($raw['fields'] ?? null);

        // Migración lazy desde modos fijos previos.
        if ($fields === [] && isset($raw['mode'])) {
            $fields = self::fieldsFromLegacyMode((string) $raw['mode']);
        }

        return [
            'fields' => $fields,
            'enabled' => $fields !== [],
            'cancel_template' => trim((string) ($raw['cancel_template'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function applyFromGroupInput(array $input, array $config): array
    {
        $labels = is_array($input['results_field_label'] ?? null) ? $input['results_field_label'] : [];
        $types = is_array($input['results_field_type'] ?? null) ? $input['results_field_type'] : [];
        $placeholders = is_array($input['results_field_placeholder'] ?? null) ? $input['results_field_placeholder'] : [];
        $required = is_array($input['results_field_required'] ?? null) ? $input['results_field_required'] : [];
        $codes = is_array($input['results_field_code'] ?? null) ? $input['results_field_code'] : [];

        $fields = [];
        $n = max(count($labels), count($types), count($placeholders), count($codes));
        $usedCodes = [];
        for ($i = 0; $i < $n; $i++) {
            $label = trim((string) ($labels[$i] ?? ''));
            $type = strtolower(trim((string) ($types[$i] ?? self::TYPE_URL)));
            $placeholder = self::normalizeCode((string) ($placeholders[$i] ?? ''));
            $code = self::normalizeCode((string) ($codes[$i] ?? ''));
            if ($label === '' && $placeholder === '' && $code === '') {
                continue;
            }
            if ($label === '') {
                throw new \InvalidArgumentException('Cada campo de resultados necesita una etiqueta.');
            }
            if (!isset(self::FIELD_TYPES[$type])) {
                $type = self::TYPE_URL;
            }
            if ($placeholder === '') {
                $placeholder = self::normalizeCode($label) ?: ('campo_' . ($i + 1));
            }
            if ($code === '') {
                $code = $placeholder;
            }
            $base = $code;
            $suffix = 2;
            while (isset($usedCodes[$code])) {
                $code = $base . '_' . $suffix;
                $suffix++;
            }
            $usedCodes[$code] = true;
            $fields[] = [
                'code' => $code,
                'label' => mb_substr($label, 0, 120),
                'type' => $type,
                'placeholder' => mb_substr($placeholder, 0, 80),
                'required' => !empty($required[$i]),
            ];
        }

        $cancelTpl = trim((string) ($input['results_cancel_template'] ?? ''));
        if ($fields === [] && $cancelTpl === '') {
            unset($config['results_delivery']);

            return $config;
        }

        $config['results_delivery'] = [
            'fields' => $fields,
            'cancel_template' => $cancelTpl,
        ];

        return $config;
    }

    /**
     * @param array<string, mixed> $tracking
     * @return array{
     *   values:array<string,mixed>,
     *   cancelled:bool,
     *   cancel_reason:string,
     *   updated_at:?string
     * }
     */
    public static function stateFromTracking(array $tracking): array
    {
        $extra = self::decodeExtra($tracking);
        $bag = is_array($extra['results_delivery'] ?? null) ? $extra['results_delivery'] : [];
        $values = is_array($bag['values'] ?? null) ? $bag['values'] : [];

        // Compat: modos previos guardaban claves sueltas.
        if ($values === []) {
            if (!empty($bag['score_report_url'])) {
                $values['score_report_url'] = (string) $bag['score_report_url'];
            }
            if (!empty($bag['pdf_path'])) {
                $values['results_pdf'] = [
                    'path' => (string) $bag['pdf_path'],
                    'name' => (string) ($bag['pdf_name'] ?? ''),
                ];
            }
            $url = trim((string) ($tracking['results_url'] ?? ''));
            if ($url !== '') {
                $values['results_url'] = $url;
            }
            $level = trim((string) ($tracking['results_level'] ?? ''));
            if ($level !== '') {
                $values['results_level'] = $level;
            }
            $score = $tracking['results_score'] ?? null;
            if ($score !== null && $score !== '') {
                $values['results_score'] = (string) $score;
            }
        }

        return [
            'values' => $values,
            'cancelled' => !empty($bag['cancelled']),
            'cancel_reason' => trim((string) ($bag['cancel_reason'] ?? '')),
            'updated_at' => isset($bag['updated_at']) ? (string) $bag['updated_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $tracking
     * @param array{fields?:list<array<string,mixed>>,enabled?:bool,cancel_template?:string} $delivery
     */
    public static function isReady(array $tracking, array $delivery): bool
    {
        $state = self::stateFromTracking($tracking);
        if ($state['cancelled']) {
            return $state['cancel_reason'] !== '';
        }

        $fields = is_array($delivery['fields'] ?? null) ? $delivery['fields'] : [];
        if ($fields === []) {
            return true;
        }

        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['required'])) {
                continue;
            }
            if (!self::fieldHasValue($field, $state['values'], $tracking)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $tracking
     * @param array{fields?:list<array<string,mixed>>,cancel_template?:string} $delivery
     */
    public static function blockedReason(array $tracking, array $delivery): string
    {
        $state = self::stateFromTracking($tracking);
        if ($state['cancelled']) {
            return $state['cancel_reason'] === ''
                ? 'Indica el motivo de cancelación en el detalle del caso.'
                : '';
        }
        $missing = [];
        foreach (is_array($delivery['fields'] ?? null) ? $delivery['fields'] : [] as $field) {
            if (!is_array($field) || empty($field['required'])) {
                continue;
            }
            if (!self::fieldHasValue($field, $state['values'], $tracking)) {
                $missing[] = (string) ($field['label'] ?? $field['code'] ?? 'campo');
            }
        }
        if ($missing === []) {
            return '';
        }

        return 'Completa en el detalle: ' . implode(', ', $missing) . '.';
    }

    /**
     * Campos PDF configurados en la entrega de resultados del grupo.
     *
     * @param array{fields?:list<array<string,mixed>>} $delivery
     * @return list<array{code:string,label:string,type:string,placeholder:string,required:bool}>
     */
    public static function pdfFields(array $delivery): array
    {
        $out = [];
        foreach (is_array($delivery['fields'] ?? null) ? $delivery['fields'] : [] as $field) {
            if (!is_array($field) || ($field['type'] ?? '') !== self::TYPE_PDF) {
                continue;
            }
            $code = trim((string) ($field['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[] = $field;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tracking
     * @param array{fields?:list<array<string,mixed>>} $delivery
     * @return list<array{code:string,label:string,required:bool,has_file:bool}>
     */
    public static function pdfFieldsForOps(array $tracking, array $delivery): array
    {
        $state = self::stateFromTracking($tracking);
        $values = is_array($state['values'] ?? null) ? $state['values'] : [];
        $out = [];
        foreach (self::pdfFields($delivery) as $field) {
            $code = (string) ($field['code'] ?? '');
            $out[] = [
                'code' => $code,
                'label' => (string) ($field['label'] ?? $code),
                'required' => !empty($field['required']),
                'has_file' => self::pdfPath($field, $values) !== '',
            ];
        }

        return $out;
    }

    /**
     * ¿Solo faltan PDFs requeridos? (el popup de Ops puede completarlos).
     *
     * @param array<string, mixed> $tracking
     * @param array{fields?:list<array<string,mixed>>,enabled?:bool} $delivery
     */
    public static function onlyPdfRequiredMissing(array $tracking, array $delivery): bool
    {
        if (self::isReady($tracking, $delivery)) {
            return true;
        }
        $state = self::stateFromTracking($tracking);
        if ($state['cancelled']) {
            return false;
        }
        $fields = is_array($delivery['fields'] ?? null) ? $delivery['fields'] : [];
        $missingPdf = false;
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['required'])) {
                continue;
            }
            if (self::fieldHasValue($field, $state['values'], $tracking)) {
                continue;
            }
            if (($field['type'] ?? '') !== self::TYPE_PDF) {
                return false;
            }
            $missingPdf = true;
        }

        return $missingPdf;
    }

    /**
     * ¿Este envío de correo debe exigir datos de resultados?
     *
     * @param array<string, mixed> $stepDef
     * @param array{fields?:list<array<string,mixed>>,cancel_template?:string} $delivery
     */
    public static function stepRequiresResults(array $stepDef, array $delivery, string $templateCode = ''): bool
    {
        if (!empty($stepDef['requires_results'])) {
            return true;
        }
        $fields = is_array($delivery['fields'] ?? null) ? $delivery['fields'] : [];
        if ($fields === [] && trim((string) ($delivery['cancel_template'] ?? '')) === '') {
            return false;
        }
        $tpl = $templateCode !== ''
            ? $templateCode
            : trim((string) (($stepDef['email']['template_code'] ?? '')));
        if ($tpl === '') {
            return false;
        }

        $placeholders = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $ph = trim((string) ($field['placeholder'] ?? ''));
            if ($ph !== '') {
                $placeholders[$ph] = true;
            }
        }
        $cancelTpl = trim((string) ($delivery['cancel_template'] ?? ''));
        if ($cancelTpl !== '' && $tpl === $cancelTpl) {
            return true;
        }

        // Heurística: plantilla cuyo código sugiere resultados.
        $tplLower = mb_strtolower($tpl);
        if (str_contains($tplLower, 'result') || str_contains($tplLower, 'cenni') || str_contains($tplLower, 'score')) {
            return $fields !== [];
        }

        try {
            $mail = new MailTemplateService();
            $row = $mail->find($tpl);
            if ($row === null) {
                return false;
            }
            $required = [];
            $raw = $row['required_fields_json'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $required = $decoded;
                }
            }
            $blob = strtolower(
                (string) ($row['subject'] ?? '') . ' ' . (string) ($row['body_html'] ?? '')
                . ' ' . implode(' ', array_map('strval', $required))
            );
            foreach (array_keys($placeholders) as $ph) {
                if ($ph !== '' && str_contains($blob, '{{' . strtolower($ph) . '}}')) {
                    return true;
                }
                if ($ph !== '' && in_array($ph, $required, true)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Variables de correo desde los campos del grupo.
     *
     * @param array<string, mixed> $tracking
     * @param array{fields?:list<array<string,mixed>>} $delivery
     * @return array<string, string>
     */
    public static function mailVars(array $tracking, array $delivery): array
    {
        $state = self::stateFromTracking($tracking);
        $vars = [
            'cancel_reason' => $state['cancel_reason'],
        ];
        foreach (is_array($delivery['fields'] ?? null) ? $delivery['fields'] : [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $ph = trim((string) ($field['placeholder'] ?? ''));
            if ($ph === '') {
                continue;
            }
            $vars[$ph] = self::displayValue($field, $state['values'], $tracking);
        }

        return $vars;
    }

    /**
     * Adjuntos PDF listos para MailTemplateService::send.
     *
     * @param array<string, mixed> $tracking
     * @param array{fields?:list<array<string,mixed>>} $delivery
     * @return list<array{path:string,name?:string,mime?:string}>
     */
    public static function pdfAttachments(array $tracking, array $delivery): array
    {
        $state = self::stateFromTracking($tracking);
        $out = [];
        $docs = new DocumentService();
        foreach (is_array($delivery['fields'] ?? null) ? $delivery['fields'] : [] as $field) {
            if (!is_array($field) || ($field['type'] ?? '') !== self::TYPE_PDF) {
                continue;
            }
            $code = (string) ($field['code'] ?? '');
            $raw = $state['values'][$code] ?? null;
            $path = '';
            $name = '';
            if (is_array($raw)) {
                $path = trim((string) ($raw['path'] ?? ''));
                $name = trim((string) ($raw['name'] ?? ''));
            } elseif (is_string($raw)) {
                $path = trim($raw);
            }
            if ($path === '') {
                continue;
            }
            $abs = $docs->absolutePath($path);
            if (!is_file($abs)) {
                continue;
            }
            $out[] = [
                'path' => $abs,
                'name' => $name !== '' ? $name : basename($path),
                'mime' => 'application/pdf',
            ];
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<array{code:string,label:string,type:string,placeholder:string,required:bool}>
     */
    public static function normalizeFields(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $used = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $type = strtolower(trim((string) ($row['type'] ?? self::TYPE_URL)));
            if (!isset(self::FIELD_TYPES[$type])) {
                $type = self::TYPE_URL;
            }
            $placeholder = self::normalizeCode((string) ($row['placeholder'] ?? ''));
            $code = self::normalizeCode((string) ($row['code'] ?? ''));
            if ($label === '') {
                continue;
            }
            if ($placeholder === '') {
                $placeholder = self::normalizeCode($label) ?: 'campo';
            }
            if ($code === '') {
                $code = $placeholder;
            }
            $base = $code;
            $n = 2;
            while (isset($used[$code])) {
                $code = $base . '_' . $n;
                $n++;
            }
            $used[$code] = true;
            $out[] = [
                'code' => $code,
                'label' => mb_substr($label, 0, 120),
                'type' => $type,
                'placeholder' => mb_substr($placeholder, 0, 80),
                'required' => !empty($row['required']),
            ];
        }

        return $out;
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';

        return trim($code, '_');
    }

    /**
     * @return list<array{code:string,label:string,type:string,placeholder:string,required:bool}>
     */
    private static function fieldsFromLegacyMode(string $mode): array
    {
        return match ($mode) {
            'certificate_link' => [[
                'code' => 'results_url',
                'label' => 'Enlace al certificado',
                'type' => self::TYPE_URL,
                'placeholder' => 'results_url',
                'required' => true,
            ]],
            'certificate_score' => [
                [
                    'code' => 'results_url',
                    'label' => 'Enlace al certificado',
                    'type' => self::TYPE_URL,
                    'placeholder' => 'results_url',
                    'required' => true,
                ],
                [
                    'code' => 'score_report_url',
                    'label' => 'Score report',
                    'type' => self::TYPE_URL,
                    'placeholder' => 'score_report_url',
                    'required' => true,
                ],
            ],
            'pdf' => [[
                'code' => 'results_pdf',
                'label' => 'PDF de resultados',
                'type' => self::TYPE_PDF,
                'placeholder' => 'results_pdf_url',
                'required' => true,
            ]],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $values
     * @param array<string, mixed> $tracking
     */
    private static function fieldHasValue(array $field, array $values, array $tracking): bool
    {
        return self::displayValue($field, $values, $tracking) !== ''
            || (($field['type'] ?? '') === self::TYPE_PDF && self::pdfPath($field, $values) !== '');
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $values
     */
    private static function pdfPath(array $field, array $values): string
    {
        $code = (string) ($field['code'] ?? '');
        $raw = $values[$code] ?? null;
        if (is_array($raw)) {
            return trim((string) ($raw['path'] ?? ''));
        }

        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $values
     * @param array<string, mixed> $tracking
     */
    public static function displayValue(array $field, array $values, array $tracking): string
    {
        $code = (string) ($field['code'] ?? '');
        $type = (string) ($field['type'] ?? self::TYPE_TEXT);
        $raw = $values[$code] ?? null;

        if ($type === self::TYPE_PDF) {
            if (is_array($raw) && trim((string) ($raw['path'] ?? '')) !== '') {
                $tid = (int) ($tracking['id'] ?? 0);

                return $tid > 0
                    ? rtrim((string) (\App\Config\Env::get('APP_URL', '') ?? ''), '/')
                        . '/admin/seguimientos/' . $tid . '/resultados-pdf?field=' . rawurlencode($code)
                    : (string) ($raw['name'] ?? 'PDF');
            }

            return '';
        }

        if (is_string($raw) || is_numeric($raw)) {
            return trim((string) $raw);
        }

        // Compat columnas tipadas
        if ($code === 'results_url') {
            return trim((string) ($tracking['results_url'] ?? ''));
        }
        if ($code === 'results_level') {
            return trim((string) ($tracking['results_level'] ?? ''));
        }
        if ($code === 'results_score') {
            return trim((string) ($tracking['results_score'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $tracking
     * @return array<string, mixed>
     */
    private static function decodeExtra(array $tracking): array
    {
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($tracking['extra_json'] ?? null)) {
            return $tracking['extra_json'];
        }

        return [];
    }
}
