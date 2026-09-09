<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;

/**
 * Requisitos de checkout por producto (mínimos por defecto).
 *
 * La configuración efectiva se resuelve así:
 *   product_groups.config_json  (proceso compartido del proveedor/grupo)
 *   + products.config_json      (overrides del producto: descripción no aplica aquí)
 *
 * Así todas las certificaciones del mismo grupo heredan pagos, MSI, fechas, reglamento, etc.
 * y cada producto solo personaliza contenido (nombre, imágenes, descripción).
 *
 * En config_json (grupo y/o producto):
 * {
 *   "checkout_fields": ["email","first_name","last_name_p","last_name_m","phone","curp","birth_date","sex"],
 *   "required_docs": [
 *     {"code":"ine","label":"INE (PDF)","required":true,"accept":".pdf"}
 *   ],
 *   "card_msi": {"enabled":true,"months":[1,3,6,9,12],"min_amount":0},
 *   "payments": {"default_method":"transfer_proof","order":["transfer_proof","openpay_store","openpay_card"]}
 * }
 *
 * - Si checkout_fields / required_docs están presentes (aunque vacíos), se respetan.
 * - Sin config: solo contacto básico y sin documentos (reglamento/firma van en pasos posteriores).
 *
 * Campos personalizados globales: settings.checkout_custom_fields (vía allFieldMeta()).
 */
final class CheckoutRequirements
{
    /** Campos siempre disponibles en el formulario cuando se piden. */
    public const FIELD_META = [
        'email' => ['label' => 'Correo', 'required' => true, 'type' => 'email'],
        'phone' => ['label' => 'Teléfono', 'required' => true, 'type' => 'tel'],
        'first_name' => ['label' => 'Nombre(s)', 'required' => true, 'type' => 'text'],
        'last_name_p' => ['label' => 'Apellido paterno', 'required' => true, 'type' => 'text'],
        'last_name_m' => ['label' => 'Apellido materno', 'required' => false, 'type' => 'text'],
        'curp' => ['label' => 'CURP', 'required' => true, 'type' => 'text'],
        'birth_date' => ['label' => 'Fecha de nacimiento', 'required' => true, 'type' => 'date'],
        'sex' => ['label' => 'Sexo', 'required' => false, 'type' => 'select'],
        // Si se pide nacionalidad, debe capturarse (p. ej. trámites SEP / CENNI).
        'nationality' => ['label' => 'Nacionalidad', 'required' => true, 'type' => 'text'],
    ];

    /**
     * Opciones del campo Sexo en checkout.
     * El alumno ve la etiqueta; en BD / proveedor se guarda value (M|F).
     *
     * @var list<array{value:string,label:string}>
     */
    public const SEX_OPTIONS = [
        ['value' => 'F', 'label' => 'Femenino'],
        ['value' => 'M', 'label' => 'Masculino'],
    ];

    /** Campos siempre pedidos y siempre obligatorios. */
    public const LOCKED_FIELDS = ['email', 'first_name', 'last_name_p', 'phone'];

    /** Contacto mínimo para cualquier compra. */
    private const DEFAULT_FIELDS = [
        'email',
        'first_name',
        'last_name_p',
        'last_name_m',
        'phone',
    ];

    public const CUSTOM_FIELDS_SETTING = 'checkout_custom_fields';

    /** @var list<string> */
    public const CUSTOM_FIELD_TYPES = ['text', 'email', 'tel', 'date', 'number', 'select'];

    /** @var array<string, array{label:string,required:bool,type:string,custom?:bool,options?:list<array{value:string,label:string}>}>|null */
    private static ?array $metaCache = null;

    /**
     * Catálogo completo: built-ins + campos personalizados globales (settings).
     *
     * @return array<string, array{label:string,required:bool,type:string,custom?:bool}>
     */
    public static function allFieldMeta(): array
    {
        if (self::$metaCache !== null) {
            return self::$metaCache;
        }

        $meta = [];
        foreach (self::FIELD_META as $code => $row) {
            $meta[$code] = $row + ['custom' => false];
            if ($code === 'sex' && empty($meta[$code]['options'])) {
                $meta[$code]['options'] = self::SEX_OPTIONS;
            }
        }
        foreach (self::customFieldDefinitions() as $code => $def) {
            if (isset($meta[$code])) {
                continue;
            }
            $entry = [
                'label' => (string) $def['label'],
                'required' => (bool) ($def['required'] ?? false),
                'type' => (string) ($def['type'] ?? 'text'),
                'custom' => true,
            ];
            if (($entry['type'] ?? '') === 'select') {
                $entry['options'] = self::normalizeSelectOptions($def['options'] ?? []);
            }
            $meta[$code] = $entry;
        }
        self::$metaCache = $meta;

        return $meta;
    }

    public static function clearFieldMetaCache(): void
    {
        self::$metaCache = null;
    }

    public static function isBuiltinField(string $code): bool
    {
        return isset(self::FIELD_META[$code]);
    }

    /**
     * @return array<string, array{label:string,required:bool,type:string,options?:list<array{value:string,label:string}>}>
     */
    public static function customFieldDefinitions(): array
    {
        $raw = Settings::get(self::CUSTOM_FIELDS_SETTING, '{}') ?? '{}';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $code => $row) {
            if (!is_string($code) || !is_array($row)) {
                continue;
            }
            $code = self::normalizeFieldCode($code);
            if ($code === '' || isset(self::FIELD_META[$code])) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = (string) ($row['type'] ?? 'text');
            if (!in_array($type, self::CUSTOM_FIELD_TYPES, true)) {
                $type = 'text';
            }
            $entry = [
                'label' => $label,
                'required' => (bool) ($row['required'] ?? false),
                'type' => $type,
            ];
            if ($type === 'select') {
                $entry['options'] = self::normalizeSelectOptions($row['options'] ?? []);
            }
            $out[$code] = $entry;
        }

        return $out;
    }

    /**
     * Normaliza opciones de un campo select (lista de value/label).
     *
     * @param mixed $raw
     * @return list<array{value:string,label:string}>
     */
    public static function normalizeSelectOptions(mixed $raw): array
    {
        if (is_string($raw)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $raw = [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $raw[] = $line;
            }
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (is_string($item) || is_numeric($item)) {
                $label = trim((string) $item);
                if ($label === '') {
                    continue;
                }
                $value = self::normalizeFieldCode($label);
                if ($value === '') {
                    $value = 'opcion_' . (count($out) + 1);
                }
            } elseif (is_array($item)) {
                $label = trim((string) ($item['label'] ?? $item['text'] ?? $item['name'] ?? ''));
                $value = trim((string) ($item['value'] ?? $item['code'] ?? ''));
                if ($label === '' && $value === '') {
                    continue;
                }
                if ($label === '') {
                    $label = $value;
                }
                if ($value === '') {
                    $value = self::normalizeFieldCode($label);
                }
                if ($value === '') {
                    $value = 'opcion_' . (count($out) + 1);
                }
            } else {
                continue;
            }
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /**
     * @param array<string, array{label:string,required:bool,type:string,options?:list<array{value:string,label:string}>}> $defs
     */
    public static function saveCustomFieldDefinitions(array $defs): void
    {
        $clean = [];
        foreach ($defs as $code => $row) {
            if (!is_string($code) || !is_array($row)) {
                continue;
            }
            $code = self::normalizeFieldCode($code);
            if ($code === '' || isset(self::FIELD_META[$code])) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = (string) ($row['type'] ?? 'text');
            if (!in_array($type, self::CUSTOM_FIELD_TYPES, true)) {
                $type = 'text';
            }
            $entry = [
                'label' => $label,
                'required' => (bool) ($row['required'] ?? false),
                'type' => $type,
            ];
            if ($type === 'select') {
                $options = self::normalizeSelectOptions($row['options'] ?? []);
                if ($options === []) {
                    continue;
                }
                $entry['options'] = $options;
            }
            $clean[$code] = $entry;
        }
        Settings::set(
            self::CUSTOM_FIELDS_SETTING,
            (string) json_encode($clean, JSON_UNESCAPED_UNICODE)
        );
        self::clearFieldMetaCache();
    }

    /**
     * @param list<array{value:string,label:string}>|string|null $options
     * @return array{code:string,label:string,required:bool,type:string,custom:bool,options?:list<array{value:string,label:string}>}
     */
    public static function addCustomField(
        string $label,
        string $type = 'text',
        bool $required = false,
        ?string $codeHint = null,
        mixed $options = null
    ): array {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Indica el nombre del campo.');
        }
        if (!in_array($type, self::CUSTOM_FIELD_TYPES, true)) {
            throw new \InvalidArgumentException('Tipo de campo no válido.');
        }

        $normalizedOptions = [];
        if ($type === 'select') {
            $normalizedOptions = self::normalizeSelectOptions($options);
            if ($normalizedOptions === []) {
                throw new \InvalidArgumentException(
                    'Para un campo de opción múltiple indica al menos una opción (una por línea).'
                );
            }
        }

        $base = $codeHint !== null && trim($codeHint) !== ''
            ? self::normalizeFieldCode($codeHint)
            : self::normalizeFieldCode($label);
        if ($base === '') {
            $base = 'campo';
        }
        if (!str_starts_with($base, 'custom_')) {
            $base = 'custom_' . $base;
        }

        $defs = self::customFieldDefinitions();
        $code = $base;
        $n = 2;
        while (isset(self::FIELD_META[$code]) || isset($defs[$code])) {
            $code = $base . '_' . $n;
            $n++;
            if ($n > 50) {
                throw new \InvalidArgumentException('No se pudo generar un código único para el campo.');
            }
        }

        $defs[$code] = [
            'label' => $label,
            'required' => $required,
            'type' => $type,
        ];
        if ($type === 'select') {
            $defs[$code]['options'] = $normalizedOptions;
        }
        self::saveCustomFieldDefinitions($defs);

        $out = [
            'code' => $code,
            'label' => $label,
            'required' => $required,
            'type' => $type,
            'custom' => true,
        ];
        if ($type === 'select') {
            $out['options'] = $normalizedOptions;
        }

        return $out;
    }

    public static function removeCustomField(string $code): void
    {
        $code = self::normalizeFieldCode($code);
        if ($code === '' || isset(self::FIELD_META[$code])) {
            throw new \InvalidArgumentException('Solo se pueden eliminar campos personalizados.');
        }
        $defs = self::customFieldDefinitions();
        if (!isset($defs[$code])) {
            throw new \InvalidArgumentException('Ese campo personalizado no existe.');
        }
        unset($defs[$code]);
        self::saveCustomFieldDefinitions($defs);
    }

    /**
     * Actualiza un campo personalizado del catálogo global.
     *
     * @param list<array{value:string,label:string}>|string|null $options
     * @return array{code:string,label:string,required:bool,type:string,custom:bool,options?:list<array{value:string,label:string}>}
     */
    public static function updateCustomField(
        string $code,
        string $label,
        string $type = 'text',
        bool $required = false,
        mixed $options = null
    ): array {
        $code = self::normalizeFieldCode($code);
        if ($code === '' || isset(self::FIELD_META[$code])) {
            throw new \InvalidArgumentException('Solo se pueden editar campos personalizados.');
        }
        $defs = self::customFieldDefinitions();
        if (!isset($defs[$code])) {
            throw new \InvalidArgumentException('Ese campo personalizado no existe.');
        }
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Indica el nombre del campo.');
        }
        if (!in_array($type, self::CUSTOM_FIELD_TYPES, true)) {
            throw new \InvalidArgumentException('Tipo de campo no válido.');
        }

        $normalizedOptions = [];
        if ($type === 'select') {
            $normalizedOptions = self::normalizeSelectOptions(
                $options !== null ? $options : ($defs[$code]['options'] ?? [])
            );
            if ($normalizedOptions === []) {
                throw new \InvalidArgumentException(
                    'Para un campo de opción múltiple indica al menos una opción (una por línea).'
                );
            }
        }

        $defs[$code] = [
            'label' => $label,
            'required' => $required,
            'type' => $type,
        ];
        if ($type === 'select') {
            $defs[$code]['options'] = $normalizedOptions;
        }
        self::saveCustomFieldDefinitions($defs);

        $out = [
            'code' => $code,
            'label' => $label,
            'required' => $required,
            'type' => $type,
            'custom' => true,
        ];
        if ($type === 'select') {
            $out['options'] = $normalizedOptions;
        }

        return $out;
    }

    /**
     * Valores permitidos para un campo select (vacío si no es select).
     *
     * @param array{type?:string,options?:list<array{value:string,label:string}>,code?:string} $field
     * @return list<string>
     */
    public static function allowedSelectValues(array $field): array
    {
        if (($field['type'] ?? '') !== 'select') {
            return [];
        }
        $options = self::normalizeSelectOptions($field['options'] ?? []);
        if ($options === [] && ($field['code'] ?? '') === 'sex') {
            $options = self::SEX_OPTIONS;
        }

        return array_values(array_map(
            static fn (array $opt): string => (string) $opt['value'],
            $options
        ));
    }

    public static function normalizeFieldCode(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('/[^a-z0-9_]+/', '_', $s) ?? '';
        $s = trim($s, '_');
        if (strlen($s) > 60) {
            $s = substr($s, 0, 60);
            $s = rtrim($s, '_');
        }

        return $s;
    }

    /**
     * Normaliza sexo a código de BD (F|M). Acepta etiquetas o códigos.
     * Vacío si no se reconoce (para no guardar «hombre»/«mujer» literales).
     */
    public static function normalizeSexValue(string $raw): string
    {
        $v = mb_strtolower(trim($raw), 'UTF-8');
        if ($v === '') {
            return '';
        }

        return match ($v) {
            'f', 'femenino', 'femenina', 'mujer', 'female' => 'F',
            'm', 'masculino', 'masculina', 'hombre', 'male' => 'M',
            default => in_array(strtoupper($raw), ['F', 'M'], true) ? strtoupper($raw) : '',
        };
    }

    /** @return list<string> */
    public static function allowedSexValues(): array
    {
        return array_values(array_map(
            static fn (array $opt): string => (string) $opt['value'],
            self::SEX_OPTIONS
        ));
    }


    /** @return array<string, mixed> */
    public static function config(array $product): array
    {
        $group = self::decodeJson($product['group_config_json'] ?? null);
        $own = self::decodeJson($product['config_json'] ?? null);

        if ($group === [] && $own === []) {
            return [];
        }

        return self::deepMerge($group, $own);
    }

    /** @return array<string, mixed> */
    private static function decodeJson(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Merge profundo: el override gana. Listas indexadas se reemplazan completas.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && self::isAssoc($value)
                && self::isAssoc($base[$key])
            ) {
                $base[$key] = self::deepMerge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /** @param array<mixed> $arr */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * @param array<string, mixed> $product
     * @return list<array{code:string,label:string,required:bool,type:string}>
     */
    public static function fieldsForProduct(array $product): array
    {
        $cfg = self::config($product);
        $all = self::allFieldMeta();
        $codes = self::DEFAULT_FIELDS;
        if (array_key_exists('checkout_fields', $cfg) && is_array($cfg['checkout_fields'])) {
            $codes = [];
            foreach ($cfg['checkout_fields'] as $code) {
                if (is_string($code) && isset($all[$code])) {
                    $codes[] = $code;
                }
            }
            // Siempre exigir identificación mínima de la persona + teléfono de soporte
            foreach (self::LOCKED_FIELDS as $must) {
                if (!in_array($must, $codes, true)) {
                    array_unshift($codes, $must);
                }
            }
        }

        /** @var array<string, bool> $requiredOverrides */
        $requiredOverrides = [];
        if (isset($cfg['checkout_field_required']) && is_array($cfg['checkout_field_required'])) {
            foreach ($cfg['checkout_field_required'] as $code => $flag) {
                if (is_string($code)) {
                    $requiredOverrides[$code] = (bool) $flag;
                }
            }
        }

        $out = [];
        foreach ($codes as $code) {
            $meta = $all[$code];
            $required = in_array($code, self::LOCKED_FIELDS, true)
                ? true
                : (array_key_exists($code, $requiredOverrides)
                    ? $requiredOverrides[$code]
                    : (bool) $meta['required']);
            $row = [
                'code' => $code,
                'label' => $meta['label'],
                'required' => $required,
                'type' => $meta['type'],
                'custom' => !empty($meta['custom']),
            ];
            if (($meta['type'] ?? '') === 'select') {
                $opts = self::normalizeSelectOptions($meta['options'] ?? []);
                if ($opts === [] && $code === 'sex') {
                    $opts = self::SEX_OPTIONS;
                }
                $row['options'] = $opts;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Reglamento con firma digital en checkout (PDF plantilla + firma en última página).
     *
     * @param array<string, mixed> $product
     * @return array{
     *   template_path:string,
     *   template_url:string,
     *   doc_code:string,
     *   required_before_checkout:bool
     * }|null
     */
    public static function reglamentoForProduct(array $product): ?array
    {
        $cfg = self::config($product);
        $reg = $cfg['reglamento'] ?? null;
        if (!is_array($reg)) {
            return null;
        }

        $path = trim((string) ($reg['template_path'] ?? ''));
        $sourceUrl = trim((string) ($reg['source_url'] ?? ''));
        if ($path === '' && $sourceUrl === '') {
            return null;
        }

        if ($path !== '' && str_starts_with($path, 'http')) {
            $url = $path;
        } elseif ($path !== '') {
            $url = asset($path);
        } else {
            $url = $sourceUrl;
        }

        return [
            'template_path' => $path !== '' ? $path : $sourceUrl,
            'template_url' => $url,
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
            'doc_code' => (string) ($reg['doc_code'] ?? 'reglamento_firmado'),
            // Si hay reglamento configurado, siempre es obligatorio antes de pagar.
            'required_before_checkout' => true,
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return list<array{code:string,label:string,required:bool,accept:string}>
     */
    public static function docsForProduct(array $product): array
    {
        $cfg = self::config($product);
        if (array_key_exists('required_docs', $cfg) && is_array($cfg['required_docs'])) {
            $docs = self::normalizeDocs($cfg['required_docs']);
            if (self::reglamentoForProduct($product) !== null) {
                $digitalCode = self::reglamentoForProduct($product)['doc_code'];
                $docs = array_values(array_filter(
                    $docs,
                    static fn (array $d): bool => ($d['code'] ?? '') !== $digitalCode
                ));
            }

            return $docs;
        }

        // Sin config: no pedir documentos en el checkout.
        // Reglamento, firma, INE, actas, etc. se solicitan en el pipeline cuando aplique.
        return [];
    }

    /** Paso inicial del pipeline al crear tracking: primero de la plantilla del grupo. */
    public static function initialStepCode(array $product, string $productType, array $requiredDocs): string
    {
        $cfg = self::config($product);
        $pipelineCode = strtolower(trim((string) ($cfg['pipeline_code'] ?? '')));
        if ($pipelineCode !== '') {
            try {
                $repo = new \App\Repositories\PipelineRepository();
                $tpl = $repo->findByCode($pipelineCode);
                if ($tpl !== null) {
                    $steps = $repo->stepsForTemplate((int) $tpl['id']);
                    if ($steps !== []) {
                        $first = trim((string) ($steps[0]['code'] ?? ''));
                        if ($first !== '') {
                            return $first;
                        }
                    }
                }
            } catch (\Throwable) {
                // Fallback abajo.
            }
        }

        $hasDocs = $requiredDocs !== [] || self::reglamentoForProduct($product) !== null;

        return match ($productType) {
            'course' => 'pago',
            'procedure' => 'docs',
            default => $hasDocs ? 'docs' : 'pago',
        };
    }

    /**
     * Documentos del expediente (después del checkout), p.ej. reglamento firmado.
     * En config_json:
     * "registration_docs": [{"code":"reglamento","label":"...","required":true,"accept":".pdf"}]
     *
     * Defaults:
     * - certification / procedure → reglamento + firma
     * - course → ninguno
     *
     * @param array<string, mixed> $product
     * @return list<array{code:string,label:string,required:bool,accept:string}>
     */
    public static function registrationDocsForProduct(array $product): array
    {
        $cfg = self::config($product);
        if (array_key_exists('registration_docs', $cfg) && is_array($cfg['registration_docs'])) {
            return self::normalizeDocs($cfg['registration_docs']);
        }

        $type = (string) ($product['type'] ?? $product['product_type'] ?? '');
        if (in_array($type, ['certification', 'procedure'], true)) {
            return self::normalizeDocs([
                [
                    'code' => 'reglamento',
                    'label' => 'Reglamento firmado (PDF)',
                    'required' => true,
                    'accept' => '.pdf',
                ],
                [
                    'code' => 'signature',
                    'label' => 'Firma (imagen)',
                    'required' => true,
                    'accept' => '.jpg,.jpeg,.png',
                ],
            ]);
        }

        return [];
    }

    /** Resuelve pipeline por config_json.pipeline_code o null si no hay override. */
    public static function pipelineCode(array $product): ?string
    {
        $cfg = self::config($product);
        $code = $cfg['pipeline_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array{code:string,label:string,required:bool,accept:string}>
     */
    private static function normalizeDocs(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['code'])) {
                continue;
            }
            $code = (string) $row['code'];
            $defaultAccept = self::defaultAcceptFor($code);
            $out[] = [
                'code' => $code,
                'label' => (string) ($row['label'] ?? self::defaultLabelFor($code)),
                'required' => (bool) ($row['required'] ?? true),
                'accept' => (string) ($row['accept'] ?? $defaultAccept),
            ];
        }

        return $out;
    }

    private static function defaultAcceptFor(string $code): string
    {
        return match ($code) {
            'ine', 'reglamento', 'reglamento_firmado' => '.pdf',
            'photo', 'signature' => '.jpg,.jpeg,.png',
            default => '.pdf,.jpg,.jpeg,.png',
        };
    }

    private static function defaultLabelFor(string $code): string
    {
        return match ($code) {
            'ine' => 'INE / identificación (PDF)',
            'photo' => 'Fotografía',
            'birth_certificate' => 'Acta de nacimiento',
            'reglamento', 'reglamento_firmado' => 'Reglamento firmado (PDF)',
            'signature' => 'Firma',
            default => $code,
        };
    }
}
