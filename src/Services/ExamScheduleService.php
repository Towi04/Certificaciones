<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Horarios de examen en checkout (bloques de 30 min según config del producto).
 */
final class ExamScheduleService
{
    /** @param array<string, mixed> $product */
    public static function needsExamAtCheckout(array $product): bool
    {
        $type = (string) ($product['type'] ?? '');
        if (!in_array($type, ['certification', 'procedure'], true)) {
            return false;
        }
        $cfg = CheckoutRequirements::config($product);
        $exam = $cfg['exam'] ?? [];

        return (bool) ($exam['choose_at_checkout'] ?? false);
    }

    /**
     * @param array<string, mixed> $product
     * @return array{
     *   slot_minutes:int,
     *   min_advance_days:int,
     *   weekdays:array{start:string,end:string},
     *   saturday:array{start:string,end:string},
     *   blocked_dates:list<string>
     * }
     */
    public static function scheduleRules(array $product): array
    {
        $cfg = CheckoutRequirements::config($product);
        $schedule = is_array($cfg['schedule'] ?? null) ? $cfg['schedule'] : [];
        $exam = is_array($cfg['exam'] ?? null) ? $cfg['exam'] : [];

        $weekdays = is_array($schedule['weekdays'] ?? null) ? $schedule['weekdays'] : [];
        $saturday = is_array($schedule['saturday'] ?? null) ? $schedule['saturday'] : [];

        return [
            'slot_minutes' => max(15, (int) ($exam['slot_minutes'] ?? 30)),
            'min_advance_days' => max(0, (int) ($schedule['min_advance_days'] ?? 2)),
            'weekdays' => [
                'start' => (string) ($weekdays['start'] ?? '10:00'),
                'end' => (string) ($weekdays['end'] ?? '17:30'),
            ],
            'saturday' => [
                'start' => (string) ($saturday['start'] ?? '08:00'),
                'end' => (string) ($saturday['end'] ?? '12:00'),
            ],
            'blocked_dates' => self::normalizeBlockedDates($schedule['blocked_dates'] ?? []),
        ];
    }

    /**
     * Fechas bloqueadas (vacaciones / cierres) en formato YYYY-MM-DD.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function normalizeBlockedDates(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $date = trim((string) $item);
            if ($date === '') {
                continue;
            }
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
            if ($dt && $dt->format('Y-m-d') === $date) {
                $out[$date] = $date;
            }
        }

        return array_values($out);
    }

    /** @param array<string, mixed> $product */
    public function minSelectableDate(array $product): string
    {
        $rules = self::scheduleRules($product);
        $dt = new \DateTimeImmutable('today');
        $dt = $dt->modify('+' . $rules['min_advance_days'] . ' days');

        return $dt->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $product
     * @return list<string> ISO dates within horizon
     */
    public function selectableDates(array $product, int $horizonDays = 60): array
    {
        $min = $this->minSelectableDate($product);
        $out = [];
        $start = new \DateTimeImmutable($min);
        $end = $start->modify('+' . max(1, $horizonDays) . ' days');

        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            if ($this->slotsForDate($product, $date) !== []) {
                $out[] = $date;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $product
     * @return list<array{value:string,label:string}>
     */
    public function slotsForDate(array $product, string $dateRaw): array
    {
        $date = $this->normalizeDate($dateRaw);
        if ($date === null) {
            return [];
        }

        if ($date < $this->minSelectableDate($product)) {
            return [];
        }

        $rules = self::scheduleRules($product);
        if (in_array($date, $rules['blocked_dates'], true)) {
            return [];
        }

        $dt = new \DateTimeImmutable($date);
        $dow = (int) $dt->format('w');
        if ($dow === 0) {
            return [];
        }

        $window = $dow === 6 ? $rules['saturday'] : $rules['weekdays'];
        $start = $this->parseClock($window['start']);
        $end = $this->parseClock($window['end']);
        if ($start === null || $end === null || $start >= $end) {
            return [];
        }

        $slotMinutes = $rules['slot_minutes'];
        $slots = [];
        $cursor = $start;
        while ($cursor < $end) {
            $next = $cursor->modify('+' . $slotMinutes . ' minutes');
            if ($next > $end) {
                break;
            }
            $value = $cursor->format('H:i');
            $slots[] = [
                'value' => $value,
                'label' => $cursor->format('H:i'),
            ];
            $cursor = $next;
        }

        return $slots;
    }

    /** @param array<string, mixed> $product */
    public function validateSlot(array $product, string $dateRaw, string $timeRaw): void
    {
        $date = $this->normalizeDate($dateRaw);
        $time = $this->normalizeTime($timeRaw);
        if ($date === null) {
            throw new \InvalidArgumentException('Selecciona una fecha de examen válida.');
        }
        if ($time === null) {
            throw new \InvalidArgumentException('Selecciona una hora de examen válida.');
        }
        if ($date < $this->minSelectableDate($product)) {
            throw new \InvalidArgumentException('La fecha de examen no cumple el anticipo mínimo requerido.');
        }
        $blocked = self::scheduleRules($product)['blocked_dates'];
        if (in_array($date, $blocked, true)) {
            throw new \InvalidArgumentException('Esa fecha de aplicación no está disponible (bloqueada / vacaciones).');
        }

        $allowed = array_column($this->slotsForDate($product, $date), 'value');
        $timeShort = substr($time, 0, 5);
        if (!in_array($timeShort, $allowed, true)) {
            throw new \InvalidArgumentException('La hora seleccionada no está disponible para ese día.');
        }
    }

    private function normalizeDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        return $dt && $dt->format('Y-m-d') === $raw ? $raw : null;
    }

    private function normalizeTime(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
            $parts = explode(':', $raw);
            $h = (int) $parts[0];
            $m = (int) $parts[1];
            if ($h >= 0 && $h <= 23 && $m >= 0 && $m <= 59) {
                return sprintf('%02d:%02d:00', $h, $m);
            }
        }

        return null;
    }

    private function parseClock(string $clock): ?\DateTimeImmutable
    {
        $clock = trim($clock);
        if (!preg_match('/^\d{1,2}:\d{2}$/', $clock)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('H:i', $clock) ?: null;
    }
}
