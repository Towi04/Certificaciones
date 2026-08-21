<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Documentos requeridos antes del pago.
 * Se pueden sobreescribir por producto en config_json.required_docs:
 * [{"code":"ine","label":"INE / Identificación","required":true}, ...]
 */
final class RequiredDocuments
{
    /**
     * @param array<string, mixed> $product
     * @return list<array{code:string,label:string,required:bool,accept:string}>
     */
    public static function forProduct(array $product): array
    {
        if (!empty($product['config_json'])) {
            $decoded = json_decode((string) $product['config_json'], true);
            if (is_array($decoded) && isset($decoded['required_docs']) && is_array($decoded['required_docs'])) {
                $out = [];
                foreach ($decoded['required_docs'] as $row) {
                    if (!is_array($row) || empty($row['code'])) {
                        continue;
                    }
                    $out[] = [
                        'code' => (string) $row['code'],
                        'label' => (string) ($row['label'] ?? $row['code']),
                        'required' => (bool) ($row['required'] ?? true),
                        'accept' => (string) ($row['accept'] ?? '.pdf,.jpg,.jpeg,.png'),
                    ];
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }

        $type = (string) ($product['type'] ?? 'certification');
        $audience = (string) ($product['audience'] ?? 'any');

        return match ($type) {
            'course' => [
                ['code' => 'ine', 'label' => 'INE u otra identificación oficial', 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png'],
            ],
            'procedure' => [
                ['code' => 'ine', 'label' => 'INE / identificación', 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png'],
                ['code' => 'birth_certificate', 'label' => 'Acta de nacimiento', 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png'],
                ['code' => 'photo', 'label' => 'Fotografía tamaño infantil', 'required' => true, 'accept' => '.jpg,.jpeg,.png'],
            ],
            default => array_values(array_filter([
                ['code' => 'ine', 'label' => 'INE / identificación oficial', 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png'],
                ['code' => 'photo', 'label' => 'Fotografía reciente', 'required' => true, 'accept' => '.jpg,.jpeg,.png'],
                $audience === 'kids'
                    ? ['code' => 'birth_certificate', 'label' => 'Acta de nacimiento', 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png']
                    : null,
            ])),
        };
    }
}
