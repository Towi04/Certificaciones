<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Evalúa fórmulas estilo Excel (español/inglés) con placeholders {{campo}}.
 * El resultado es un valor de texto listo para escribir en la celda (sin fórmula).
 *
 * Ejemplos:
 *   =MAYUSC({{full_name}})
 *   =ASCIIMAYUSC({{full_name}})
 *   =TEXTO({{exam_date}};"dd/mm/aaaa")
 *   =TEXTO({{exam_time}};"hh:mm")
 *   =SUSTITUIR(MAYUSC({{first_name}});"Ñ";"N")
 */
final class CellFormulaEvaluator
{
    /** @param array<string, string> $fields */
    public static function evaluate(string $expression, array $fields): string
    {
        $expression = trim($expression);
        if ($expression === '') {
            return '';
        }

        // Plantilla simple sin fórmula: "Hola {{first_name}}"
        if (!str_starts_with($expression, '=') && !preg_match('/^[A-Za-zÁÉÍÓÚÜÑ_]+\s*\(/u', $expression)) {
            return self::replacePlaceholders($expression, $fields);
        }

        if (str_starts_with($expression, '=')) {
            $expression = substr($expression, 1);
        }
        $expression = trim($expression);
        if ($expression === '') {
            return '';
        }

        $parser = new self($expression, $fields);

        return $parser->parseExpression();
    }

    /** @param array<string, string> $fields */
    public static function replacePlaceholders(string $template, array $fields): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $m) use ($fields): string {
                $key = $m[1];

                return (string) ($fields[$key] ?? '');
            },
            $template
        );
    }

    private string $src;
    private int $pos = 0;
    /** @var array<string, string> */
    private array $fields;

    /** @param array<string, string> $fields */
    private function __construct(string $src, array $fields)
    {
        $this->src = $src;
        $this->fields = $fields;
    }

    private function parseExpression(): string
    {
        $left = $this->parsePrimary();
        $this->skipWs();
        while ($this->peek() === '&') {
            $this->pos++;
            $right = $this->parsePrimary();
            $left .= $right;
            $this->skipWs();
        }
        $this->skipWs();
        if ($this->pos < strlen($this->src)) {
            throw new \InvalidArgumentException(
                'Fórmula no válida cerca de: ' . substr($this->src, $this->pos, 24)
            );
        }

        return $left;
    }

    private function parsePrimary(): string
    {
        $this->skipWs();
        $ch = $this->peek();
        if ($ch === '') {
            return '';
        }

        // String literal
        if ($ch === '"' || $ch === "'") {
            return $this->parseStringLiteral();
        }

        // Placeholder {{field}}
        if ($ch === '{' && ($this->src[$this->pos + 1] ?? '') === '{') {
            return $this->parsePlaceholder();
        }

        // Number
        if (ctype_digit($ch) || ($ch === '.' && isset($this->src[$this->pos + 1]) && ctype_digit($this->src[$this->pos + 1]))) {
            return $this->parseNumber();
        }

        // Function or bare identifier
        if (preg_match('/[A-Za-zÁÉÍÓÚÜÑ_]/u', $ch)) {
            $name = $this->parseIdentifier();
            $this->skipWs();
            if ($this->peek() === '(') {
                return $this->parseFunctionCall($name);
            }
            // Identificador suelto: tratarlo como campo si existe
            $key = strtolower($name);
            if (array_key_exists($key, $this->fields)) {
                return (string) $this->fields[$key];
            }
            throw new \InvalidArgumentException('Identificador desconocido en fórmula: ' . $name);
        }

        if ($ch === '(') {
            $this->pos++;
            $start = $this->pos;
            $depth = 0;
            $inStr = null;
            $len = strlen($this->src);
            while ($this->pos < $len) {
                $c = $this->src[$this->pos];
                if ($inStr !== null) {
                    if ($c === $inStr) {
                        $inStr = null;
                    }
                    $this->pos++;
                    continue;
                }
                if ($c === '"' || $c === "'") {
                    $inStr = $c;
                    $this->pos++;
                    continue;
                }
                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    if ($depth === 0) {
                        $inner = trim(substr($this->src, $start, $this->pos - $start));
                        $this->pos++;
                        if ($inner === '') {
                            return '';
                        }
                        $sub = new self($inner, $this->fields);

                        return $sub->parseExpression();
                    }
                    $depth--;
                }
                $this->pos++;
            }
            throw new \InvalidArgumentException('Falta ) en la fórmula.');
        }

        throw new \InvalidArgumentException('Token inesperado en fórmula: ' . $ch);
    }

    private function parseFunctionCall(string $name): string
    {
        if ($this->peek() !== '(') {
            throw new \InvalidArgumentException('Se esperaba ( tras ' . $name);
        }
        $this->pos++; // (
        $args = [];
        $this->skipWs();
        if ($this->peek() !== ')') {
            while (true) {
                $args[] = $this->parseArg();
                $this->skipWs();
                $sep = $this->peek();
                if ($sep === ',' || $sep === ';') {
                    $this->pos++;
                    $this->skipWs();
                    continue;
                }
                break;
            }
        }
        if ($this->peek() !== ')') {
            throw new \InvalidArgumentException('Falta ) al cerrar ' . $name);
        }
        $this->pos++;

        return $this->callFunction($name, $args);
    }

    private function parseArg(): string
    {
        $this->skipWs();
        $start = $this->pos;
        $depth = 0;
        $inStr = null;
        $len = strlen($this->src);
        while ($this->pos < $len) {
            $c = $this->src[$this->pos];
            if ($inStr !== null) {
                if ($c === '\\') {
                    $this->pos += 2;
                    continue;
                }
                if ($c === $inStr) {
                    $inStr = null;
                }
                $this->pos++;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inStr = $c;
                $this->pos++;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif (($c === ',' || $c === ';') && $depth === 0) {
                break;
            }
            $this->pos++;
        }
        $chunk = trim(substr($this->src, $start, $this->pos - $start));
        if ($chunk === '') {
            return '';
        }
        $sub = new self($chunk, $this->fields);

        return $sub->parseExpression();
    }

    /** @param list<string> $args */
    private function callFunction(string $name, array $args): string
    {
        $fn = $this->normalizeFunctionName($name);
        return match ($fn) {
            'MAYUSC', 'UPPER' => self::upper((string) ($args[0] ?? '')),
            'MINUSC', 'LOWER' => self::lower((string) ($args[0] ?? '')),
            'SUSTITUIR', 'SUBSTITUTE' => str_replace(
                (string) ($args[1] ?? ''),
                (string) ($args[2] ?? ''),
                (string) ($args[0] ?? '')
            ),
            'SINACENTOS' => AsciiUpperNormalizer::stripAccents((string) ($args[0] ?? '')),
            'ASCIIMAYUSC', 'TOEFL' => AsciiUpperNormalizer::normalize((string) ($args[0] ?? '')),
            'TEXTO', 'TEXT' => self::formatText((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'CONCATENAR', 'CONCAT', 'CONCATENATE' => implode('', $args),
            'RECORTAR', 'TRIM' => trim((string) ($args[0] ?? '')),
            default => throw new \InvalidArgumentException('Función no soportada: ' . $name),
        };
    }

    private function normalizeFunctionName(string $name): string
    {
        $name = self::upper(trim($name));
        $map = [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ];

        return strtr($name, $map);
    }

    private static function upper(string $value): string
    {
        if (function_exists('mb_strtoupper')) {
            return \mb_strtoupper($value, 'UTF-8');
        }
        // Fallback sin mbstring (mapa UTF-8 por secuencias hex).
        $map = [
            "\xC3\xA1" => "\xC3\x81", "\xC3\xA0" => "\xC3\x80", "\xC3\xA4" => "\xC3\x84", "\xC3\xA2" => "\xC3\x82", "\xC3\xA3" => "\xC3\x83",
            "\xC3\xA9" => "\xC3\x89", "\xC3\xA8" => "\xC3\x88", "\xC3\xAB" => "\xC3\x8B", "\xC3\xAA" => "\xC3\x8A",
            "\xC3\xAD" => "\xC3\x8D", "\xC3\xAC" => "\xC3\x8C", "\xC3\xAF" => "\xC3\x8F", "\xC3\xAE" => "\xC3\x8E",
            "\xC3\xB3" => "\xC3\x93", "\xC3\xB2" => "\xC3\x92", "\xC3\xB6" => "\xC3\x96", "\xC3\xB4" => "\xC3\x94", "\xC3\xB5" => "\xC3\x95",
            "\xC3\xBA" => "\xC3\x9A", "\xC3\xB9" => "\xC3\x99", "\xC3\xBC" => "\xC3\x9C", "\xC3\xBB" => "\xC3\x9B",
            "\xC3\xB1" => "\xC3\x91", "\xC3\xA7" => "\xC3\x87",
        ];

        return strtoupper(strtr($value, $map));
    }

    private static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return \mb_strtolower($value, 'UTF-8');
        }
        $map = [
            "\xC3\x81" => "\xC3\xA1", "\xC3\x80" => "\xC3\xA0", "\xC3\x84" => "\xC3\xA4", "\xC3\x82" => "\xC3\xA2", "\xC3\x83" => "\xC3\xA3",
            "\xC3\x89" => "\xC3\xA9", "\xC3\x88" => "\xC3\xA8", "\xC3\x8B" => "\xC3\xAB", "\xC3\x8A" => "\xC3\xAA",
            "\xC3\x8D" => "\xC3\xAD", "\xC3\x8C" => "\xC3\xAC", "\xC3\x8F" => "\xC3\xAF", "\xC3\x8E" => "\xC3\xAE",
            "\xC3\x93" => "\xC3\xB3", "\xC3\x92" => "\xC3\xB2", "\xC3\x96" => "\xC3\xB6", "\xC3\x94" => "\xC3\xB4", "\xC3\x95" => "\xC3\xB5",
            "\xC3\x9A" => "\xC3\xBA", "\xC3\x99" => "\xC3\xB9", "\xC3\x9C" => "\xC3\xBC", "\xC3\x9B" => "\xC3\xBB",
            "\xC3\x91" => "\xC3\xB1", "\xC3\x87" => "\xC3\xA7",
        ];

        return strtolower(strtr($value, $map));
    }

    private static function formatText(string $value, string $format): string
    {
        $value = trim($value);
        $format = trim($format);
        if ($value === '' || $format === '') {
            return $value;
        }

        $dt = self::parseDateTime($value);
        if ($dt === null) {
            return $value;
        }

        $key = self::lower($format);
        $presets = [
            'dd/mm/aaaa' => 'd/m/Y',
            'dd-mm-aaaa' => 'd-m-Y',
            'dd/mm/yyyy' => 'd/m/Y',
            'dd-mm-yyyy' => 'd-m-Y',
            'aaaa-mm-dd' => 'Y-m-d',
            'aaaa/mm/dd' => 'Y/m/d',
            'yyyy-mm-dd' => 'Y-m-d',
            'yyyy/mm/dd' => 'Y/m/d',
            'hh:mm' => 'H:i',
            'hh:mm:ss' => 'H:i:s',
            'h:mm' => 'G:i',
        ];
        if (isset($presets[$key])) {
            return $dt->format($presets[$key]);
        }

        // Reemplazo genérico de tokens (aaaa/yyyy, aa/yy, dd, mm, hh).
        $php = $format;
        $php = str_ireplace(['aaaa', 'yyyy'], 'Y', $php);
        $php = str_ireplace(['aa', 'yy'], 'y', $php);
        $php = str_ireplace('dd', 'd', $php);
        // hh → H (24h); mm → m (mes) salvo si parece hora
        if (preg_match('/h/i', $format)) {
            $php = str_ireplace('hh', 'H', $php);
            $php = preg_replace('/(?<=H:|H)m{1,2}/i', 'i', $php) ?? $php;
            $php = str_ireplace('mm', 'i', $php);
        } else {
            $php = str_ireplace('mm', 'm', $php);
        }

        try {
            return $dt->format($php);
        } catch (\Throwable) {
            return $value;
        }
    }

    private static function parseDateTime(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i',
            'd/m/Y',
            'd-m-Y',
            'H:i:s',
            'H:i',
        ];
        foreach ($formats as $f) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $f, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }
        $ts = strtotime($value);
        if ($ts !== false) {
            return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }

        return null;
    }

    private function parsePlaceholder(): string
    {
        if (!preg_match('/\G\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $this->src, $m, 0, $this->pos)) {
            throw new \InvalidArgumentException('Placeholder {{campo}} inválido.');
        }
        $this->pos += strlen($m[0]);
        $key = $m[1];

        return (string) ($this->fields[$key] ?? '');
    }

    private function parseStringLiteral(): string
    {
        $quote = $this->src[$this->pos];
        $this->pos++;
        $out = '';
        $len = strlen($this->src);
        while ($this->pos < $len) {
            $c = $this->src[$this->pos];
            if ($c === $quote) {
                // Excel doubles quotes for escape
                if (($this->src[$this->pos + 1] ?? '') === $quote) {
                    $out .= $quote;
                    $this->pos += 2;
                    continue;
                }
                $this->pos++;
                return $out;
            }
            if ($c === '\\') {
                $out .= $this->src[$this->pos + 1] ?? '';
                $this->pos += 2;
                continue;
            }
            $out .= $c;
            $this->pos++;
        }
        throw new \InvalidArgumentException('Cadena sin cerrar en la fórmula.');
    }

    private function parseNumber(): string
    {
        $start = $this->pos;
        while ($this->pos < strlen($this->src) && (ctype_digit($this->src[$this->pos]) || $this->src[$this->pos] === '.')) {
            $this->pos++;
        }

        return substr($this->src, $start, $this->pos - $start);
    }

    private function parseIdentifier(): string
    {
        $start = $this->pos;
        $len = strlen($this->src);
        while ($this->pos < $len && preg_match('/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9_]/u', $this->src[$this->pos])) {
            $this->pos++;
        }

        return substr($this->src, $start, $this->pos - $start);
    }

    private function peek(): string
    {
        return $this->src[$this->pos] ?? '';
    }

    private function skipWs(): void
    {
        $len = strlen($this->src);
        while ($this->pos < $len && ctype_space($this->src[$this->pos])) {
            $this->pos++;
        }
    }
}
