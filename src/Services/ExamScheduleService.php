<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;

/**
 * Horarios de examen en checkout (bloques según config del producto/grupo).
 */
final class ExamScheduleService
{
    public const GLOBAL_VACATIONS_KEY = 'global_vacation_dates';

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
     *   validity_months:int,
     *   available_365:bool,
     *   days:array<int,bool>,
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
        $available365 = (bool) ($schedule['available_365'] ?? false);

        $daysCfg = is_array($schedule['days'] ?? null) ? $schedule['days'] : null;
        if ($daysCfg === null) {
            $days = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 0 => false];
        } else {
            $days = [];
            foreach ([0, 1, 2, 3, 4, 5, 6] as $d) {
                $days[$d] = !empty($daysCfg[(string) $d]) || !empty($daysCfg[$d]);
            }
        }

        $blocked = [];
        if (!$available365) {
            $blocked = self::normalizeBlockedDates(self::globalVacationDates());
        }
        // Fechas locales antiguas (por grupo) se siguen respetando si existen.
        $localBlocked = self::normalizeBlockedDates($schedule['blocked_dates'] ?? []);
        foreach ($localBlocked as $d) {
            $blocked[$d] = $d;
        }
        $blocked = array_values($blocked);

        return [
            'slot_minutes' => max(15, (int) ($exam['slot_minutes'] ?? 30)),
            'min_advance_days' => max(0, (int) ($schedule['min_advance_days'] ?? 2)),
            'validity_months' => max(1, (int) ($exam['validity_months'] ?? 6)),
            'available_365' => $available365,
            'days' => $days,
            'weekdays' => [
                'start' => (string) ($weekdays['start'] ?? '10:00'),
                'end' => (string) ($weekdays['end'] ?? '17:30'),
            ],
            'saturday' => [
                'start' => (string) ($saturday['start'] ?? '08:00'),
                'end' => (string) ($saturday['end'] ?? '12:00'),
            ],
            'blocked_dates' => $blocked,
        ];
    }

    /** @return list<string> */
    public static function globalVacationDates(): array
    {
        return self::normalizeBlockedDates(Settings::get(self::GLOBAL_VACATIONS_KEY, '') ?? '');
    }

    public static function saveGlobalVacationDates(string $raw): void
    {
        $dates = self::normalizeBlockedDates($raw);
        Settings::set(self::GLOBAL_VACATIONS_KEY, implode("\n", $dates));
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
     * @return list<string>
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
        $dow = (int) $dt->format('w'); // 0=Dom .. 6=Sáb
        if (empty($rules['days'][$dow])) {
            return [];
        }

        $window = in_array($dow, [0, 6], true) ? $rules['saturday'] : $rules['weekdays'];
        $start = $this->parseClockOnDate($date, (string) $window['start'], false);
        $end = $this->parseClockOnDate($date, (string) $window['end'], true);
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

    /**
     * Interpreta HH:MM sobre una fecha. Si $isEnd y el valor es 24:00,
     * se toma como medianoche del día siguiente (fin exclusivo del día).
     */
    private function parseClockOnDate(string $date, string $clock, bool $isEnd): ?\DateTimeImmutable
    {
        $clock = trim($clock);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $m)) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($isEnd && $hour === 24 && $minute === 0) {
            return (new \DateTimeImmutable($date . ' 00:00:00'))->modify('+1 day');
        }
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('%s %02d:%02d:00', $date, $hour, $minute));
    }
}
