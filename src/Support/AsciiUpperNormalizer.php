<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Transcribe texto para plantillas que no aceptan acentos/ñ (p. ej. TOEFL).
 * Reglas: sin acentos, Ñ→N, todo en mayúsculas.
 */
final class AsciiUpperNormalizer
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $map = [
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'Ý' => 'Y', 'Ÿ' => 'Y',
            'Ñ' => 'N',
            'Ç' => 'C',
            'á' => 'A', 'à' => 'A', 'ä' => 'A', 'â' => 'A', 'ã' => 'A', 'å' => 'A',
            'é' => 'E', 'è' => 'E', 'ë' => 'E', 'ê' => 'E',
            'í' => 'I', 'ì' => 'I', 'ï' => 'I', 'î' => 'I',
            'ó' => 'O', 'ò' => 'O', 'ö' => 'O', 'ô' => 'O', 'õ' => 'O',
            'ú' => 'U', 'ù' => 'U', 'ü' => 'U', 'û' => 'U',
            'ý' => 'Y', 'ÿ' => 'Y',
            'ñ' => 'N',
            'ç' => 'C',
        ];
        $value = strtr($value, $map);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        $value = preg_replace('/[^A-Za-z0-9 @._\\-\\/:,]/', '', $value) ?? $value;

        return mb_strtoupper($value, 'UTF-8');
    }
}
