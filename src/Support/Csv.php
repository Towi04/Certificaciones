<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Utilidades CSV (Excel MX/ES suele usar ; y montos con coma decimal).
 */
final class Csv
{
    /**
     * Lee el encabezado detectando sep=, y el delimitador (, ; o tab).
     *
     * @param resource $handle
     * @return array{0: list<string>, 1: string} [header, delimiter]
     */
    public static function readHeader($handle): array
    {
        $start = ftell($handle);
        if ($start === false) {
            $start = 0;
        }
        $rawFirst = fgets($handle);
        if ($rawFirst === false) {
            throw new \InvalidArgumentException('El CSV no tiene encabezados.');
        }
        $trimFirst = trim(preg_replace('/^\xEF\xBB\xBF/', '', $rawFirst) ?? $rawFirst);
        $trimFirst = self::cellToUtf8($trimFirst);
        if (preg_match('/^sep=(.)\s*$/i', $trimFirst, $m)) {
            $delimiter = $m[1];
        } else {
            $delimiter = self::detectDelimiter(self::cellToUtf8($rawFirst));
            fseek($handle, $start);
        }
        $header = csv_get($handle, null, $delimiter);
        if ($header === false) {
            throw new \InvalidArgumentException('El CSV no tiene encabezados.');
        }
        $header = array_map(static fn ($h) => self::cellToUtf8($h), $header);

        return [$header, $delimiter];
    }

    public static function detectDelimiter(string $line): string
    {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        $line = rtrim($line, "\r\n");
        if (preg_match('/^sep=(.)\s*$/i', trim($line), $m)) {
            return $m[1];
        }
        $comma = substr_count($line, ',');
        $semi = substr_count($line, ';');
        $tab = substr_count($line, "\t");
        if ($semi > $comma && $semi >= $tab) {
            return ';';
        }
        if ($tab > $comma && $tab >= $semi) {
            return "\t";
        }

        return ',';
    }

    /**
     * Separador nativo de Excel en MX/ES. Con UTF-8 BOM (y sin línea sep=)
     * los acentos se ven bien al abrir con doble clic.
     */
    public static function excelDelimiter(): string
    {
        return ';';
    }

    /**
     * @deprecated Preferir excelDelimiter() + UTF-8 BOM sin pista sep=.
     * La línea sep=, hace que Excel ignore el BOM y rompa acentos.
     *
     * @param resource $stream
     */
    public static function writeExcelSepHint($stream, string $separator = ','): void
    {
        // No-op a propósito: mantener firma por si queda alguna llamada vieja.
        unset($stream, $separator);
    }

    /**
     * Normaliza texto de celdas CSV a UTF-8 (Excel a veces guarda Windows-1252).
     */
    public static function cellToUtf8(mixed $value): string
    {
        $s = (string) $value;
        if ($s === '') {
            return '';
        }
        // Quitar BOM suelto en celdas.
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $converted = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);

        return is_string($converted) ? $converted : $s;
    }

    /**
     * Parsea un monto de CSV/formulario. Vacío → null.
     * Acepta 1500 | 1500.50 | 1,500.50 | 1.500,50 | $1,500.50
     */
    public static function parseMoney(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            if (is_nan((float) $value) || is_infinite((float) $value)) {
                return null;
            }

            return round(max(0, (float) $value), 2);
        }

        $s = trim(str_replace("\xc2\xa0", ' ', (string) $value));
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        // Quitar moneda y texto; conservar dígitos, signo, punto y coma.
        $s = preg_replace('/[^\d,.\-]/u', '', $s) ?? '';
        $s = trim($s);
        if ($s === '' || $s === '-' || $s === '.' || $s === ',') {
            return null;
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        if ($hasComma && $hasDot) {
            // El último separador es el decimal.
            if (strrpos($s, ',') > strrpos($s, '.')) {
                // 1.234,56
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                // 1,234.56
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            // Solo comas: decimal si hay 1-2 dígitos finales, si no miles.
            if (preg_match('/^-?\d+,\d{1,2}$/', $s)) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        }

        if (!is_numeric($s)) {
            throw new \InvalidArgumentException('Monto inválido: ' . (string) $value);
        }

        return round(max(0, (float) $s), 2);
    }

    /**
     * Vacío → fallback; valor presente → parseado (lanza si es inválido).
     */
    public static function parseMoneyOrFallback(mixed $value, float $fallback): float
    {
        $parsed = self::parseMoney($value);

        return $parsed === null ? round(max(0, $fallback), 2) : $parsed;
    }
}
