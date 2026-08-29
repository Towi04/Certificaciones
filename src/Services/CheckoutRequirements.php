<?php

declare(strict_types=1);

namespace App\Services;

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
        'nationality' => ['label' => 'Nacionalidad', 'required' => false, 'type' => 'text'],
    ];

    /** Contacto mínimo para cualquier compra. */
    private const DEFAULT_FIELDS = [
        'email',
        'first_name',
        'last_name_p',
        'last_name_m',
        'phone',
    ];

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
        $codes = self::DEFAULT_FIELDS;
        if (array_key_exists('checkout_fields', $cfg) && is_array($cfg['checkout_fields'])) {
            $codes = [];
            foreach ($cfg['checkout_fields'] as $code) {
                if (is_string($code) && isset(self::FIELD_META[$code])) {
                    $codes[] = $code;
                }
            }
            // Siempre exigir identificación mínima de la persona + teléfono de soporte
            foreach (['email', 'first_name', 'last_name_p', 'phone'] as $must) {
                if (!in_array($must, $codes, true)) {
                    array_unshift($codes, $must);
                }
            }
        }

        $out = [];
        foreach ($codes as $code) {
            $meta = self::FIELD_META[$code];
            $out[] = [
                'code' => $code,
                'label' => $meta['label'],
                'required' => (bool) $meta['required'],
                'type' => $meta['type'],
            ];
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
            'required_before_checkout' => (bool) ($reg['required_before_checkout'] ?? true),
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

    /** Paso inicial del pipeline al crear tracking (override en config_json.initial_step_code). */
    public static function initialStepCode(array $product, string $productType, array $requiredDocs): string
    {
        $cfg = self::config($product);
        $override = $cfg['initial_step_code'] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
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
