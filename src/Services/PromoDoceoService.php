<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;
use App\Database\Connection;
use PDO;

/**
 * Códigos promocionales DOCEO por mes del año.
 * Se configuran los 12 códigos de una vez; solo el del mes vigente es aceptado.
 */
final class PromoDoceoService
{
    public const SETTINGS_KEY = 'doceo_promo_calendar';
    public const LEGACY_CODE_KEY = 'doceo_promo_code';

    /** @return array<int, string> mes 1..12 => nombre */
    public static function monthLabels(): array
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }

    /**
     * @return array<string, array<int, string>> year => (mes => código)
     */
    private static function loadAllYears(): array
    {
        $raw = Settings::get(self::SETTINGS_KEY, '') ?? '';
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        // Formato nuevo: { "2026": { "1": "X", ... }, "2027": {...} }
        $out = [];
        $looksLikeYears = false;
        foreach ($decoded as $k => $v) {
            if (is_array($v) && preg_match('/^\d{4}$/', (string) $k)) {
                $looksLikeYears = true;
                break;
            }
        }

        if ($looksLikeYears) {
            foreach ($decoded as $y => $months) {
                if (!is_array($months)) {
                    continue;
                }
                $year = (int) $y;
                $codes = self::emptyCodes();
                foreach ($months as $m => $code) {
                    $month = (int) $m;
                    if ($month >= 1 && $month <= 12) {
                        $codes[$month] = strtoupper(trim((string) $code));
                    }
                }
                $out[(string) $year] = $codes;
            }

            return $out;
        }

        // Formato legacy de una sola pasada: { "year": 2026, "codes": {...} }
        if (isset($decoded['year'], $decoded['codes']) && is_array($decoded['codes'])) {
            $year = (int) $decoded['year'];
            $codes = self::emptyCodes();
            foreach ($decoded['codes'] as $m => $code) {
                $month = (int) $m;
                if ($month >= 1 && $month <= 12) {
                    $codes[$month] = strtoupper(trim((string) $code));
                }
            }
            $out[(string) $year] = $codes;
        }

        return $out;
    }

    /** @return array<int, string> */
    private static function emptyCodes(): array
    {
        $codes = [];
        for ($m = 1; $m <= 12; $m++) {
            $codes[$m] = '';
        }

        return $codes;
    }

    /**
     * @return array{year:int, codes:array<int, string>}
     */
    public static function getCalendar(?int $year = null): array
    {
        $year = $year ?? (int) date('Y');
        $all = self::loadAllYears();
        $key = (string) $year;

        if (isset($all[$key])) {
            return ['year' => $year, 'codes' => $all[$key]];
        }

        $codes = self::emptyCodes();
        if ($year === (int) date('Y') && $all === []) {
            // Migración: un solo código legacy rellena el mes actual.
            $legacy = strtoupper(trim((string) (Settings::get(self::LEGACY_CODE_KEY, '') ?? '')));
            if ($legacy !== '') {
                $codes[(int) date('n')] = $legacy;
            }
        }

        return ['year' => $year, 'codes' => $codes];
    }

    public static function currentCode(?\DateTimeInterface $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $cal = self::getCalendar((int) $now->format('Y'));
        $month = (int) $now->format('n');

        return $cal['codes'][$month] ?? '';
    }

    /**
     * Guarda el calendario anual y sincroniza filas en discount_codes con vigencia mensual.
     *
     * @param array<int|string, string> $codesByMonth mes => código
     */
    public static function saveYear(int $year, array $codesByMonth, ?string $whatsapp = null): void
    {
        if ($year < 2020 || $year > 2100) {
            throw new \InvalidArgumentException('Año inválido.');
        }

        $normalized = self::emptyCodes();
        $seen = [];
        for ($m = 1; $m <= 12; $m++) {
            $raw = strtoupper(trim((string) ($codesByMonth[$m] ?? $codesByMonth[(string) $m] ?? '')));
            if ($raw === '') {
                continue;
            }
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $raw)) {
                $label = self::monthLabels()[$m] ?? (string) $m;
                throw new \InvalidArgumentException(
                    "Código de {$label} inválido: usa 3–40 caracteres (letras, números, guión o guión bajo)."
                );
            }
            if (isset($seen[$raw])) {
                $other = self::monthLabels()[$seen[$raw]] ?? (string) $seen[$raw];
                $label = self::monthLabels()[$m] ?? (string) $m;
                throw new \InvalidArgumentException(
                    "El código {$raw} está repetido en {$other} y {$label}. Cada mes debe tener un código distinto."
                );
            }
            $seen[$raw] = $m;
            $normalized[$m] = $raw;
        }

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $all = self::loadAllYears();
            $all[(string) $year] = $normalized;
            ksort($all);
            Settings::set(self::SETTINGS_KEY, json_encode($all, JSON_UNESCAPED_UNICODE));

            if ($year === (int) date('Y')) {
                $currentMonthCode = $normalized[(int) date('n')] ?? '';
                if ($currentMonthCode !== '') {
                    Settings::set(self::LEGACY_CODE_KEY, $currentMonthCode);
                } else {
                    $fallback = '';
                    foreach ($normalized as $c) {
                        if ($c !== '') {
                            $fallback = $c;
                            break;
                        }
                    }
                    Settings::set(self::LEGACY_CODE_KEY, $fallback);
                }
            }

            if ($whatsapp !== null) {
                $wa = preg_replace('/\D+/', '', $whatsapp) ?? '';
                Settings::set('school_whatsapp', $wa);
            }

            self::syncDiscountCodesForYear($pdo, $year, $normalized);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Sincroniza solo los códigos del año indicado; no toca otros años.
     *
     * @param array<int, string> $codes mes => código
     */
    private static function syncDiscountCodesForYear(PDO $pdo, int $year, array $codes): void
    {
        $yearCodes = [];
        foreach ($codes as $month => $code) {
            if ($code === '') {
                continue;
            }
            $yearCodes[] = $code;
            $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
            $endTs = strtotime($start . ' +1 month -1 second');
            $end = date('Y-m-d H:i:s', $endTs ?: time());

            $stmt = $pdo->prepare('SELECT id, type, starts_at FROM discount_codes WHERE code = ? LIMIT 1');
            $stmt->execute([$code]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $type = (string) ($existing['type'] ?? '');
                if ($type !== 'promo_doceo' && $type !== '') {
                    throw new \InvalidArgumentException(
                        "El código {$code} ya está en uso como «{$type}» y no puede reutilizarse en Promo DOCEO."
                    );
                }
                $pdo->prepare(
                    'UPDATE discount_codes
                     SET type = ?, discount_mode = ?, partner_id = NULL, is_active = 1,
                         starts_at = ?, ends_at = ?, applies_to_combos = 1
                     WHERE id = ?'
                )->execute(['promo_doceo', 'to_public', $start, $end, (int) $existing['id']]);
            } else {
                $pdo->prepare(
                    'INSERT INTO discount_codes
                        (code, type, discount_mode, is_active, starts_at, ends_at, applies_to_combos)
                     VALUES (?, ?, ?, 1, ?, ?, 1)'
                )->execute([$code, 'promo_doceo', 'to_public', $start, $end]);
            }
        }

        // Desactivar promo_doceo de este año que ya no están en el calendario.
        $stmt = $pdo->prepare(
            "SELECT id, code FROM discount_codes
             WHERE type = ?
               AND starts_at IS NOT NULL
               AND YEAR(starts_at) = ?"
        );
        $stmt->execute(['promo_doceo', $year]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $keep = array_fill_keys($yearCodes, true);
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code !== '' && !isset($keep[$code])) {
                $pdo->prepare('UPDATE discount_codes SET is_active = 0 WHERE id = ?')
                    ->execute([(int) $row['id']]);
            }
        }
    }
}
