<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Evalúa un DSL pequeño estilo Excel para mapear celdas de plantillas.
 *
 * Funciones (ES + EN):
 *   MAYUSC/UPPER, MINUSC/LOWER, SUSTITUIR/SUBSTITUTE, SINACENTOS, ASCIIMAYUSC,
 *   TEXTO/TEXT, CONCATENAR/CONCAT, RECORTAR/TRIM,
 *   SI/IF, AÑO/YEAR, MES/MONTH, DIA/DAY, IZQUIERDA/LEFT, DERECHA/RIGHT,
 *   Y/AND, O/OR, NO/NOT
 * También: & concatenación, comparaciones (= <> != < > <= >=), ; o , como separador.
 *
 * Placeholders: {{campo}} o {{campo|FALLBACK}}.
 * Alias (p. ej. apellido_paterno → last_name_p) se resuelven antes de evaluar.
 */
final class CellFormulaEvaluator
{
    /** @var array<string, string> alias en minúsculas → clave canónica */
    private const FIELD_ALIASES = [
        'apellido_paterno' => 'last_name_p',
        'apellido_materno' => 'last_name_m',
        'apellidos' => 'last_names',
        'name' => 'first_name',
        'nombre' => 'first_name',
        'nombres' => 'first_name',
        'nombre_completo' => 'full_name',
        'fecha_nacimiento' => 'birth_date',
        'fecha_nac' => 'birth_date',
        'sexo' => 'sex',
        'genero' => 'sex',
        'género' => 'sex',
        'nacionalidad' => 'nationality',
        'fecha_examen' => 'exam_date',
        'hora_examen' => 'exam_time',
        'fecha_hora_examen' => 'exam_datetime',
        'correo' => 'email',
        'telefono' => 'phone',
        'teléfono' => 'phone',
    ];

    /**
     * @param array<string, scalar|null> $fields
     */
    public static function evaluate(?string $formula, array $fields): string
    {
        $raw = trim((string) $formula);
        if ($raw === '') {
            return '';
        }

        $bag = self::enrichFields($fields);

        if ($raw[0] !== '=') {
            return self::interpolate($raw, $bag);
        }

        $expr = ltrim(substr($raw, 1));
        if ($expr === '') {
            return '';
        }

        try {
            $tokens = self::tokenize($expr);
            $parser = new FormulaParser($tokens, $bag);
            $value = $parser->parseExpression();
            $parser->expectEof();

            return self::stringify($value);
        } catch (\Throwable) {
            return self::interpolate($raw, $bag);
        }
    }

    /**
     * @param array<string, scalar|null> $fields
     * @return array<string, string>
     */
    private static function enrichFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            $key = strtolower(trim((string) $k));
            if ($key === '') {
                continue;
            }
            $out[$key] = trim((string) ($v ?? ''));
        }

        $canon = [];
        foreach ($out as $k => $v) {
            $canon[self::canonicalizeFieldKey($k)] = $v;
        }
        $out = array_merge($out, $canon);

        $lp = trim((string) ($out['last_name_p'] ?? ''));
        $lm = trim((string) ($out['last_name_m'] ?? ''));
        $out['last_names'] = trim(preg_replace('/\s+/u', ' ', trim($lp . ' ' . $lm)) ?? '');

        $sex = strtoupper(self::stripAccents(trim((string) ($out['sex'] ?? ''))));
        if (in_array($sex, ['M', 'MASCULINO', 'HOMBRE', 'H'], true)) {
            $out['sex'] = 'M';
            $out['sex_label'] = 'Masculino';
            $out['sex_code'] = 'M';
        } elseif (in_array($sex, ['F', 'FEMENINO', 'MUJER'], true)) {
            $out['sex'] = 'F';
            $out['sex_label'] = 'Femenino';
            $out['sex_code'] = 'F';
        } else {
            $out['sex_label'] = (string) ($out['sex'] ?? '');
            $out['sex_code'] = (string) ($out['sex'] ?? '');
        }

        $nat = trim((string) ($out['nationality'] ?? ''));
        $natNorm = AsciiUpperNormalizer::normalize($nat);
        if ($natNorm === 'MEXICO' || $natNorm === 'MEX' || $natNorm === 'MX') {
            $out['nationality_code'] = 'MEX';
        } elseif ($nat !== '') {
            $out['nationality_code'] = strtoupper(substr($natNorm, 0, 3));
        } else {
            $out['nationality_code'] = '';
        }

        return $out;
    }

    public static function canonicalizeFieldKey(string $key): string
    {
        $k = strtolower(trim($key));
        $k = str_replace([' ', '-'], '_', $k);
        $k = preg_replace('/_+/', '_', $k) ?? $k;
        $k = trim($k, '_');

        return self::FIELD_ALIASES[$k] ?? $k;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function interpolate(string $text, array $fields): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_ \-]+)\s*(?:\|\s*([^}]*))?\s*\}\}/u',
            static function (array $m) use ($fields): string {
                $key = self::canonicalizeFieldKey($m[1]);
                $fallback = array_key_exists(2, $m) ? trim((string) $m[2]) : '';
                $val = $fields[$key] ?? '';
                if ($val === '' && $fallback !== '') {
                    return $fallback;
                }

                return $val;
            },
            $text
        );
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function tokenize(string $expr): array
    {
        $tokens = [];
        $len = strlen($expr);
        $i = 0;
        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $i++;
                $buf = '';
                while ($i < $len) {
                    $c = $expr[$i];
                    if ($c === $quote) {
                        $i++;
                        break;
                    }
                    if ($c === '\\' && $i + 1 < $len) {
                        $buf .= $expr[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $buf .= $c;
                    $i++;
                }
                $tokens[] = ['STR', $buf];
                continue;
            }
            if ($ch === '{' && $i + 1 < $len && $expr[$i + 1] === '{') {
                $end = strpos($expr, '}}', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException('placeholder sin cierre');
                }
                $inner = substr($expr, $i + 2, $end - ($i + 2));
                $tokens[] = ['PH', $inner];
                $i = $end + 2;
                continue;
            }
            if (preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ_][A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9_]*/u', substr($expr, $i), $m) === 1) {
                $tokens[] = ['ID', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/^\d+(?:\.\d+)?/', substr($expr, $i), $m) === 1) {
                $tokens[] = ['NUM', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (in_array($ch, ['&', '(', ')', ',', ';', '+', '-', '*', '/'], true)) {
                $tokens[] = ['SYM', $ch];
                $i++;
                continue;
            }
            if ($ch === '<' || $ch === '>' || $ch === '=' || $ch === '!') {
                $two = substr($expr, $i, 2);
                if (in_array($two, ['<=', '>=', '<>', '!='], true)) {
                    $tokens[] = ['OP', $two];
                    $i += 2;
                    continue;
                }
                if ($ch === '=' || $ch === '<' || $ch === '>') {
                    $tokens[] = ['OP', $ch];
                    $i++;
                    continue;
                }
            }
            throw new \RuntimeException('token inválido cerca de: ' . substr($expr, $i, 12));
        }
        $tokens[] = ['EOF', ''];

        return $tokens;
    }

    public static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_float($value)) {
            if (abs($value - round($value)) < 1e-9) {
                return (string) (int) round($value);
            }

            return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return (string) $value;
    }

    public static function stripAccents(string $s): string
    {
        return AsciiUpperNormalizer::stripAccents($s);
    }

    public static function utf8Upper(string $s): string
    {
        if (function_exists('mb_strtoupper')) {
            return \mb_strtoupper($s, 'UTF-8');
        }
        $map = [
            'á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ü' => 'Ü', 'ñ' => 'Ñ',
            'à' => 'À', 'è' => 'È', 'ì' => 'Ì', 'ò' => 'Ò', 'ù' => 'Ù',
        ];

        return strtoupper(strtr($s, $map));
    }

    public static function utf8Lower(string $s): string
    {
        if (function_exists('mb_strtolower')) {
            return \mb_strtolower($s, 'UTF-8');
        }
        $map = [
            'Á' => 'á', 'É' => 'é', 'Í' => 'í', 'Ó' => 'ó', 'Ú' => 'ú', 'Ü' => 'ü', 'Ñ' => 'ñ',
            'À' => 'à', 'È' => 'è', 'Ì' => 'ì', 'Ò' => 'ò', 'Ù' => 'ù',
        ];

        return strtolower(strtr($s, $map));
    }

    public static function utf8Len(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return \mb_strlen($s, 'UTF-8');
        }

        return count(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public static function utf8Substr(string $s, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? \mb_substr($s, $start, null, 'UTF-8')
                : \mb_substr($s, $start, $length, 'UTF-8');
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);

        return implode('', $slice);
    }
}

/**
 * @internal
 */
final class FormulaParser
{
    /** @var list<array{0:string,1:string}> */
    private array $tokens;
    private int $pos = 0;
    /** @var array<string, string> */
    private array $fields;

    /**
     * @param list<array{0:string,1:string}> $tokens
     * @param array<string, string> $fields
     */
    public function __construct(array $tokens, array $fields)
    {
        $this->tokens = $tokens;
        $this->fields = $fields;
    }

    public function parseExpression(): mixed
    {
        return $this->parseComparison();
    }

    public function expectEof(): void
    {
        if ($this->peekType() !== 'EOF') {
            throw new \RuntimeException('tokens sobrantes');
        }
    }

    private function parseComparison(): mixed
    {
        $left = $this->parseConcat();
        $op = $this->peek();
        if ($op[0] === 'OP') {
            $this->next();
            $right = $this->parseConcat();

            return $this->compare($left, $op[1], $right);
        }

        return $left;
    }

    private function parseConcat(): mixed
    {
        $left = $this->parseAdd();
        while ($this->peekType() === 'SYM' && $this->peekValue() === '&') {
            $this->next();
            $right = $this->parseAdd();
            $left = CellFormulaEvaluator::stringify($left) . CellFormulaEvaluator::stringify($right);
        }

        return $left;
    }

    private function parseAdd(): mixed
    {
        $left = $this->parseMul();
        while ($this->peekType() === 'SYM' && ($this->peekValue() === '+' || $this->peekValue() === '-')) {
            $op = $this->next()[1];
            $right = $this->parseMul();
            $a = $this->asNumber($left);
            $b = $this->asNumber($right);
            $left = $op === '+' ? ($a + $b) : ($a - $b);
        }

        return $left;
    }

    private function parseMul(): mixed
    {
        $left = $this->parseUnary();
        while ($this->peekType() === 'SYM' && ($this->peekValue() === '*' || $this->peekValue() === '/')) {
            $op = $this->next()[1];
            $right = $this->parseUnary();
            $a = $this->asNumber($left);
            $b = $this->asNumber($right);
            $left = $op === '*' ? ($a * $b) : ($b == 0.0 ? 0.0 : ($a / $b));
        }

        return $left;
    }

    private function parseUnary(): mixed
    {
        if ($this->peekType() === 'SYM' && $this->peekValue() === '-') {
            $this->next();

            return -1 * $this->asNumber($this->parseUnary());
        }
        if ($this->peekType() === 'SYM' && $this->peekValue() === '+') {
            $this->next();

            return $this->parseUnary();
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): mixed
    {
        $t = $this->peek();
        if ($t[0] === 'STR') {
            $this->next();

            return $t[1];
        }
        if ($t[0] === 'NUM') {
            $this->next();

            return str_contains($t[1], '.') ? (float) $t[1] : (int) $t[1];
        }
        if ($t[0] === 'PH') {
            $this->next();

            return $this->resolvePlaceholder($t[1]);
        }
        if ($t[0] === 'ID') {
            $name = $t[1];
            $this->next();
            if ($this->peekType() === 'SYM' && $this->peekValue() === '(') {
                $this->next();
                $args = $this->parseArgList();
                $this->expectSym(')');

                return $this->callFunction($name, $args);
            }

            return $this->resolvePlaceholder($name);
        }
        if ($t[0] === 'SYM' && $t[1] === '(') {
            $this->next();
            $inner = $this->parseExpression();
            $this->expectSym(')');

            return $inner;
        }
        throw new \RuntimeException('expresión inválida');
    }

    /** @return list<mixed> */
    private function parseArgList(): array
    {
        if ($this->peekType() === 'SYM' && $this->peekValue() === ')') {
            return [];
        }
        $args = [$this->parseExpression()];
        while ($this->peekType() === 'SYM' && ($this->peekValue() === ',' || $this->peekValue() === ';')) {
            $this->next();
            $args[] = $this->parseExpression();
        }

        return $args;
    }

    private function resolvePlaceholder(string $inner): string
    {
        $inner = trim($inner);
        $fallback = '';
        if (str_contains($inner, '|')) {
            [$inner, $fb] = array_map('trim', explode('|', $inner, 2));
            $fallback = $fb;
        }
        $key = CellFormulaEvaluator::canonicalizeFieldKey($inner);
        $val = $this->fields[$key] ?? '';
        if ($val === '' && $fallback !== '') {
            return $fallback;
        }

        return $val;
    }

    /** @param list<mixed> $args */
    private function callFunction(string $name, array $args): mixed
    {
        $fn = $this->normalizeFn($name);

        return match ($fn) {
            'UPPER' => CellFormulaEvaluator::utf8Upper($this->argString($args, 0)),
            'LOWER' => CellFormulaEvaluator::utf8Lower($this->argString($args, 0)),
            'TRIM' => trim(preg_replace('/\s+/u', ' ', $this->argString($args, 0)) ?? ''),
            'SUBSTITUTE' => str_replace($this->argString($args, 1), $this->argString($args, 2), $this->argString($args, 0)),
            'UNACCENT' => CellFormulaEvaluator::stripAccents($this->argString($args, 0)),
            'ASCIIUPPER' => AsciiUpperNormalizer::normalize($this->argString($args, 0)),
            'CONCAT' => implode('', array_map(static fn ($a) => CellFormulaEvaluator::stringify($a), $args)),
            'TEXT' => $this->formatText($this->argString($args, 0), $this->argString($args, 1)),
            'IF' => $this->truthy($args[0] ?? false) ? ($args[1] ?? '') : ($args[2] ?? ''),
            'YEAR' => $this->datePart($this->argString($args, 0), 'Y'),
            'MONTH' => $this->datePart($this->argString($args, 0), 'n'),
            'DAY' => $this->datePart($this->argString($args, 0), 'j'),
            'LEFT' => CellFormulaEvaluator::utf8Substr(
                $this->argString($args, 0),
                0,
                max(0, (int) $this->asNumber($args[1] ?? 0))
            ),
            'RIGHT' => (static function (string $s, int $n): string {
                if ($n <= 0) {
                    return '';
                }
                $len = CellFormulaEvaluator::utf8Len($s);

                return CellFormulaEvaluator::utf8Substr($s, max(0, $len - $n));
            })($this->argString($args, 0), (int) $this->asNumber($args[1] ?? 0)),
            'AND' => array_reduce($args, fn ($c, $a) => $c && $this->truthy($a), true),
            'OR' => array_reduce($args, fn ($c, $a) => $c || $this->truthy($a), false),
            'NOT' => !$this->truthy($args[0] ?? false),
            default => throw new \RuntimeException('función no soportada: ' . $name),
        };
    }

    private function normalizeFn(string $name): string
    {
        $n = CellFormulaEvaluator::utf8Upper(CellFormulaEvaluator::stripAccents($name));

        return match ($n) {
            'MAYUSC', 'MAYUSCULAS', 'UPPER' => 'UPPER',
            'MINUSC', 'MINUSCULAS', 'LOWER' => 'LOWER',
            'RECORTAR', 'TRIM' => 'TRIM',
            'SUSTITUIR', 'SUBSTITUTE' => 'SUBSTITUTE',
            'SINACENTOS', 'UNACCENT' => 'UNACCENT',
            'ASCIIMAYUSC', 'ASCIIUPPER' => 'ASCIIUPPER',
            'CONCATENAR', 'CONCAT' => 'CONCAT',
            'TEXTO', 'TEXT' => 'TEXT',
            'SI', 'IF' => 'IF',
            'ANO', 'AÑO', 'YEAR' => 'YEAR',
            'MES', 'MONTH' => 'MONTH',
            'DIA', 'DÍA', 'DAY' => 'DAY',
            'IZQUIERDA', 'LEFT' => 'LEFT',
            'DERECHA', 'RIGHT' => 'RIGHT',
            'Y', 'AND' => 'AND',
            'O', 'OR' => 'OR',
            'NO', 'NOT' => 'NOT',
            default => $n,
        };
    }

    private function formatText(string $value, string $pattern): string
    {
        $value = trim($value);
        $pattern = trim($pattern);
        if ($value === '' || $pattern === '') {
            return $value;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }
        $p = CellFormulaEvaluator::utf8Lower($pattern);

        $monthsShortEs = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $monthsLongEs = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        if ($p === 'mmm') {
            return CellFormulaEvaluator::utf8Upper($monthsShortEs[(int) date('n', $ts) - 1]);
        }
        if ($p === 'mmmm') {
            return CellFormulaEvaluator::utf8Upper($monthsLongEs[(int) date('n', $ts) - 1]);
        }

        $map = [
            'aaaa' => 'Y',
            'yyyy' => 'Y',
            'aa' => 'y',
            'yy' => 'y',
            'mm' => 'm',
            'dd' => 'd',
            'hh' => 'H',
            'h' => 'G',
            'ii' => 'i',
            'ss' => 's',
        ];
        uksort($map, static fn ($a, $b) => strlen($b) <=> strlen($a));
        $php = '';
        $i = 0;
        $len = strlen($p);
        while ($i < $len) {
            $matched = false;
            foreach ($map as $token => $phpTok) {
                $tlen = strlen($token);
                if (substr($p, $i, $tlen) === $token) {
                    $php .= $phpTok;
                    $i += $tlen;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $ch = $p[$i];
                $php .= preg_match('/[A-Za-z]/', $ch) === 1 ? '\\' . $ch : $ch;
                $i++;
            }
        }

        return date($php, $ts);
    }

    private function datePart(string $value, string $phpFormat): int
    {
        $ts = strtotime(trim($value));
        if ($ts === false) {
            return 0;
        }

        return (int) date($phpFormat, $ts);
    }

    /** @param list<mixed> $args */
    private function argString(array $args, int $idx): string
    {
        return CellFormulaEvaluator::stringify($args[$idx] ?? '');
    }

    private function asNumber(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) {
            return 0.0;
        }

        return (float) $s;
    }

    private function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        $s = strtoupper(trim((string) $v));
        if ($s === '' || $s === '0' || $s === 'FALSE' || $s === 'NO' || $s === 'FALSO') {
            return false;
        }

        return true;
    }

    private function compare(mixed $left, string $op, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            $a = (float) $left;
            $b = (float) $right;
        } elseif (is_numeric($left) && is_numeric($right) && !is_string($left) && !is_string($right)) {
            $a = (float) $left;
            $b = (float) $right;
        } else {
            $a = CellFormulaEvaluator::utf8Upper(trim(CellFormulaEvaluator::stringify($left)));
            $b = CellFormulaEvaluator::utf8Upper(trim(CellFormulaEvaluator::stringify($right)));
            $sexMap = ['MASCULINO' => 'M', 'HOMBRE' => 'M', 'FEMENINO' => 'F', 'MUJER' => 'F'];
            $a = $sexMap[$a] ?? $a;
            $b = $sexMap[$b] ?? $b;
            $aNorm = CellFormulaEvaluator::stripAccents($a);
            $bNorm = CellFormulaEvaluator::stripAccents($b);
            $natMap = ['MEXICO' => 'MEXICO', 'MEX' => 'MEXICO', 'MX' => 'MEXICO'];
            $a = $natMap[strtoupper($aNorm)] ?? strtoupper($aNorm);
            $b = $natMap[strtoupper($bNorm)] ?? strtoupper($bNorm);
        }

        return match ($op) {
            '=' => $a == $b,
            '<>', '!=' => $a != $b,
            '<' => $a < $b,
            '>' => $a > $b,
            '<=' => $a <= $b,
            '>=' => $a >= $b,
            default => false,
        };
    }

    /** @return array{0:string,1:string} */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    private function peekType(): string
    {
        return $this->tokens[$this->pos][0];
    }

    private function peekValue(): string
    {
        return $this->tokens[$this->pos][1];
    }

    /** @return array{0:string,1:string} */
    private function next(): array
    {
        $t = $this->tokens[$this->pos];
        $this->pos++;

        return $t;
    }

    private function expectSym(string $sym): void
    {
        $t = $this->next();
        if ($t[0] !== 'SYM' || $t[1] !== $sym) {
            throw new \RuntimeException('se esperaba ' . $sym);
        }
    }
}
