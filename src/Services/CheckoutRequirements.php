<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Requisitos de checkout por producto (mínimos por defecto).
 *
 * En products.config_json:
 * {
 *   "checkout_fields": ["email","first_name","last_name_p","last_name_m","phone","curp","birth_date","sex"],
 *   "required_docs": [
 *     {"code":"ine","label":"INE (PDF)","required":true,"accept":".pdf"}
 *   ]
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
        if (empty($product['config_json'])) {
            return [];
        }
        $decoded = json_decode((string) $product['config_json'], true);

        return is_array($decoded) ? $decoded : [];
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
     * @param array<string, mixed> $product
     * @return list<array{code:string,label:string,required:bool,accept:string}>
     */
    public static function docsForProduct(array $product): array
    {
        $cfg = self::config($product);
        if (array_key_exists('required_docs', $cfg) && is_array($cfg['required_docs'])) {
            return self::normalizeDocs($cfg['required_docs']);
        }

        // Sin config: no pedir documentos en el checkout.
        // Reglamento, firma, INE, actas, etc. se solicitan en el pipeline cuando aplique.
        return [];
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
            'ine', 'reglamento' => '.pdf',
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
            'reglamento' => 'Reglamento firmado (PDF)',
            'signature' => 'Firma',
            default => $code,
        };
    }
}
