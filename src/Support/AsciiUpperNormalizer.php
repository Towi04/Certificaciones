<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Transcribe texto para plantillas que no aceptan acentos/ñ (p. ej. TOEFL).
 * Reglas: sin acentos, Ñ→N, todo en mayúsculas.
 */
final class AsciiUpperNormalizer
{
    /** Quita acentos y convierte Ñ→N (sin forzar mayúsculas). */
    public static function stripAccents(string $value): string
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
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n',
            'ç' => 'c',
        ];
        $value = strtr($value, $map);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        return $value;
    }

    public static function normalize(string $value): string
    {
        $value = self::stripAccents($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9 @._\\-\\/:,]/', '', $value) ?? $value;

        return function_exists('mb_strtoupper')
            ? \mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }
}
