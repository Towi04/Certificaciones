<?php

declare(strict_types=1);

namespace App\Config;

final class Env
{
    private static bool $loaded = false;

    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path)) {
            throw new \RuntimeException(
                'No se encontró el archivo .env. Copia .env.example a .env y completa los valores.'
            );
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('No se pudo leer el archivo .env.');
        }

        // Quitar BOM UTF-8 si existe
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            $value = self::parseValue($value);

            self::$values[$name] = $value;
            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }

        self::$loaded = true;
    }

    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote) && strlen($value) >= 2) {
            $inner = substr($value, 1, -1);
            if ($quote === '"') {
                $inner = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $inner);
            }

            return $inner;
        }

        // Sin comillas: cortar comentario inline solo si hay espacio antes de #
        if (preg_match('/^([^#]*?)\s+#.*$/', $value, $m)) {
            return rtrim($m[1]);
        }

        return $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $fromEnv = $_ENV[$key] ?? getenv($key);
        if ($fromEnv === false || $fromEnv === null) {
            return $default;
        }

        return (string) $fromEnv;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Falta la variable de entorno: {$key}");
        }

        return $value;
    }

    public static function isFilled(string $key): bool
    {
        $value = self::get($key);
        return $value !== null && $value !== '';
    }
}
