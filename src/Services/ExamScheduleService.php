<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;

/**
 * Horarios de examen en checkout según modo del grupo:
 * - window: ventana continua (ELeT / Cambridge flexible)
 * - fixed_slots: días/horas fijas (TOEFL sábados 11:00 / 13:00) + extraordinarias
 * - dated_list: fechas concretas del proveedor (Cambridge) con deadline de inscripción
 *
 * Siempre persiste en trackings.exam_date / exam_time para placeholders de correo.
 */
final class ExamScheduleService
{
    public const GLOBAL_VACATIONS_KEY = 'global_vacation_dates';

    public const MODE_WINDOW = 'window';
    public const MODE_FIXED_SLOTS = 'fixed_slots';
    public const MODE_DATED_LIST = 'dated_list';

    public const KIND_REGULAR = 'regular';
    public const KIND_EXTRAORDINARY = 'extraordinary';
    public const KIND_PROVIDER_SESSION = 'provider_session';
    public const KIND_AUTHORIZED_SHORT = 'authorized_short_advance';

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
     * @return array<string, mixed>
     */
    public static function scheduleRules(array $product): array
    {
        $cfg = CheckoutRequirements::config($product);
        $schedule = is_array($cfg['schedule'] ?? null) ? $cfg['schedule'] : [];
        $exam = is_array($cfg['exam'] ?? null) ? $cfg['exam'] : [];

        $mode = self::normalizeMode((string) ($schedule['mode'] ?? self::MODE_WINDOW));
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
        $localBlocked = self::normalizeBlockedDates($schedule['blocked_dates'] ?? []);
        foreach ($localBlocked as $d) {
            $blocked[$d] = $d;
        }

        $fixedSlots = [];
        foreach (is_array($schedule['fixed_slots'] ?? null) ? $schedule['fixed_slots'] : [] as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $dow = (int) ($slot['dow'] ?? -1);
            $time = self::normalizeClock((string) ($slot['time'] ?? ''));
            if ($dow < 0 || $dow > 6 || $time === null) {
                continue;
            }
            $fixedSlots[] = [
                'dow' => $dow,
                'time' => $time,
                'label' => trim((string) ($slot['label'] ?? '')),
                'kind' => self::KIND_REGULAR,
            ];
        }

        $extraordinary = is_array($schedule['extraordinary'] ?? null) ? $schedule['extraordinary'] : [];
        $sessions = [];
        foreach (is_array($schedule['sessions'] ?? null) ? $schedule['sessions'] : [] as $i => $session) {
            if (!is_array($session)) {
                continue;
            }
            $examDate = self::normalizeDateStatic((string) ($session['exam_date'] ?? ''));
            if ($examDate === null) {
                continue;
            }
            $examTime = self::normalizeClock((string) ($session['exam_time'] ?? '00:00')) ?? '00:00';
            $deadline = self::normalizeDateStatic((string) ($session['registration_deadline'] ?? ''));
            $id = trim((string) ($session['id'] ?? ''));
            if ($id === '') {
                $id = $examDate . '_' . str_replace(':', '', $examTime) . '_' . $i;
            }
            $sessions[] = [
                'id' => $id,
                'exam_date' => $examDate,
                'exam_time' => $examTime,
                'registration_deadline' => $deadline,
                'label' => trim((string) ($session['label'] ?? '')),
                'capacity' => isset($session['capacity']) && $session['capacity'] !== ''
                    ? (int) $session['capacity']
                    : null,
            ];
        }

        return [
            'mode' => $mode,
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
            'blocked_dates' => array_values($blocked),
            'fixed_slots' => $fixedSlots,
            'extraordinary' => [
                'enabled' => !empty($extraordinary['enabled']),
                'student_may_request' => array_key_exists('student_may_request', $extraordinary)
                    ? !empty($extraordinary['student_may_request'])
                    : true,
                'requires_admin_approval' => array_key_exists('requires_admin_approval', $extraordinary)
                    ? !empty($extraordinary['requires_admin_approval'])
                    : true,
                'surcharge_amount' => max(0, (float) ($extraordinary['surcharge_amount'] ?? 0)),
                'surcharge_label' => trim((string) ($extraordinary['surcharge_label'] ?? 'Fecha extraordinaria'))
                    ?: 'Fecha extraordinaria',
            ],
            'sessions' => $sessions,
            'checkout_help' => trim((string) ($schedule['checkout_help'] ?? '')),
        ];
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, [self::MODE_WINDOW, self::MODE_FIXED_SLOTS, self::MODE_DATED_LIST], true)
            ? $mode
            : self::MODE_WINDOW;
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
        if ($rules['mode'] === self::MODE_DATED_LIST) {
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            $min = null;
            foreach ($rules['sessions'] as $session) {
                $deadline = (string) ($session['registration_deadline'] ?? '');
                if ($deadline !== '' && $deadline < $today) {
                    continue;
                }
                $d = (string) ($session['exam_date'] ?? '');
                if ($d === '') {
                    continue;
                }
                if ($min === null || $d < $min) {
                    $min = $d;
                }
            }

            return $min ?? $today;
        }

        $dt = new \DateTimeImmutable('today');
        $dt = $dt->modify('+' . (int) $rules['min_advance_days'] . ' days');

        return $dt->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $product
     * @return list<string>
     */
    public function selectableDates(array $product, int $horizonDays = 90): array
    {
        $rules = self::scheduleRules($product);
        if ($rules['mode'] === self::MODE_DATED_LIST) {
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            $out = [];
            foreach ($rules['sessions'] as $session) {
                $deadline = (string) ($session['registration_deadline'] ?? '');
                if ($deadline !== '' && $deadline < $today) {
                    continue;
                }
                $d = (string) ($session['exam_date'] ?? '');
                if ($d !== '') {
                    $out[$d] = $d;
                }
            }
            $list = array_values($out);
            sort($list);

            return $list;
        }

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
     * Sesiones abiertas a inscripción (modo dated_list).
     *
     * @param array<string, mixed> $product
     * @return list<array<string, mixed>>
     */
    public function openSessions(array $product): array
    {
        $rules = self::scheduleRules($product);
        if ($rules['mode'] !== self::MODE_DATED_LIST) {
            return [];
        }
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $out = [];
        foreach ($rules['sessions'] as $session) {
            $deadline = (string) ($session['registration_deadline'] ?? '');
            if ($deadline !== '' && $deadline < $today) {
                continue;
            }
            $label = (string) ($session['label'] ?? '');
            if ($label === '') {
                $label = (string) $session['exam_date'] . ' · ' . (string) $session['exam_time'];
            }
            $out[] = $session + [
                'value' => (string) $session['id'],
                'label' => $label,
                'kind' => self::KIND_PROVIDER_SESSION,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $product
     * @return list<array{value:string,label:string,kind?:string,surcharge?:float}>
     */
    public function slotsForDate(array $product, string $dateRaw, bool $includeExtraordinary = false): array
    {
        $date = $this->normalizeDate($dateRaw);
        if ($date === null) {
            return [];
        }

        $rules = self::scheduleRules($product);
        if ($rules['mode'] === self::MODE_DATED_LIST) {
            $slots = [];
            foreach ($this->openSessions($product) as $session) {
                if ((string) ($session['exam_date'] ?? '') !== $date) {
                    continue;
                }
                $time = (string) ($session['exam_time'] ?? '00:00');
                $slots[] = [
                    'value' => $time,
                    'label' => $time . (!empty($session['label']) ? ' · ' . $session['label'] : ''),
                    'kind' => self::KIND_PROVIDER_SESSION,
                    'session_id' => (string) ($session['id'] ?? ''),
                ];
            }

            return $slots;
        }

        if ($date < $this->minSelectableDate($product) && !$includeExtraordinary) {
            return [];
        }

        if (in_array($date, $rules['blocked_dates'], true)) {
            return [];
        }

        if ($rules['mode'] === self::MODE_FIXED_SLOTS) {
            $dt = new \DateTimeImmutable($date);
            $dow = (int) $dt->format('w');
            $slots = [];
            foreach ($rules['fixed_slots'] as $slot) {
                if ((int) $slot['dow'] !== $dow) {
                    continue;
                }
                if ($date < $this->minSelectableDate($product)) {
                    continue;
                }
                $time = (string) $slot['time'];
                $label = (string) ($slot['label'] ?? '');
                if ($label === '') {
                    $label = $time;
                }
                $slots[] = [
                    'value' => $time,
                    'label' => $label,
                    'kind' => self::KIND_REGULAR,
                    'surcharge' => 0.0,
                ];
            }

            return $slots;
        }

        // window (default)
        $dt = new \DateTimeImmutable($date);
        $dow = (int) $dt->format('w');
        if (empty($rules['days'][$dow])) {
            return [];
        }

        $window = in_array($dow, [0, 6], true) ? $rules['saturday'] : $rules['weekdays'];
        $start = $this->parseClockOnDate($date, (string) $window['start'], false);
        $end = $this->parseClockOnDate($date, (string) $window['end'], true);
        if ($start === null || $end === null || $start >= $end) {
            return [];
        }

        $slotMinutes = (int) $rules['slot_minutes'];
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
                'kind' => self::KIND_REGULAR,
            ];
            $cursor = $next;
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $product
     */
    public function unavailabilityReason(array $product, string $dateRaw): ?string
    {
        if ($this->slotsForDate($product, $dateRaw) !== []) {
            return null;
        }

        $date = $this->normalizeDate($dateRaw);
        if ($date === null) {
            return 'Selecciona una fecha válida.';
        }

        $rules = self::scheduleRules($product);
        $dayNames = [
            0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miércoles',
            4 => 'jueves', 5 => 'viernes', 6 => 'sábado',
        ];

        if ($rules['mode'] === self::MODE_DATED_LIST) {
            return 'Esa fecha no está en las convocatorias abiertas del proveedor. Elige otra opción.';
        }

        if ($date < $this->minSelectableDate($product)) {
            $days = (int) ($rules['min_advance_days'] ?? 0);
            if ($days <= 0) {
                return 'Esa fecha ya no está disponible. Elige una fecha a partir de hoy.';
            }

            return $days === 1
                ? 'Esa fecha no cumple el anticipo mínimo (1 día). Elige otra fecha.'
                : ('Esa fecha no cumple el anticipo mínimo (' . $days . ' días). Elige otra fecha.');
        }

        if (in_array($date, $rules['blocked_dates'], true)) {
            return 'Esa fecha no está disponible (vacaciones o día bloqueado). Elige otra fecha.';
        }

        $dow = (int) (new \DateTimeImmutable($date))->format('w');
        if ($rules['mode'] === self::MODE_FIXED_SLOTS) {
            $allowed = [];
            foreach ($rules['fixed_slots'] as $slot) {
                $allowed[(int) $slot['dow']] = true;
            }
            if (empty($allowed[$dow])) {
                $name = $dayNames[$dow] ?? 'ese día';

                return 'El ' . $name . ' no hay aplicación regular. Usa fecha extraordinaria (con costo extra) o elige otro día.';
            }

            return 'No hay horarios regulares en esa fecha.';
        }

        if (empty($rules['days'][$dow])) {
            $name = $dayNames[$dow] ?? 'ese día';

            return 'El ' . $name . ' no hay aplicación de examen. Elige otro día.';
        }

        return 'No hay horarios disponibles en esa fecha. Elige otra fecha.';
    }

    /**
     * Valida fecha/hora elegida en checkout.
     *
     * @param array<string, mixed> $product
     * @param array{kind?:string,session_id?:string,allow_short_advance?:bool} $options
     * @return array{kind:string,session_id:?string,surcharge:float,requires_admin:bool}
     */
    public function validateSelection(array $product, string $dateRaw, string $timeRaw, array $options = []): array
    {
        $date = $this->normalizeDate($dateRaw);
        $time = $this->normalizeTime($timeRaw);
        if ($date === null) {
            throw new \InvalidArgumentException('Selecciona una fecha de examen válida.');
        }
        if ($time === null) {
            throw new \InvalidArgumentException('Selecciona una hora de examen válida.');
        }

        $rules = self::scheduleRules($product);
        $kind = strtolower(trim((string) ($options['kind'] ?? self::KIND_REGULAR)));
        $timeShort = substr($time, 0, 5);
        $sessionId = trim((string) ($options['session_id'] ?? ''));

        if ($rules['mode'] === self::MODE_DATED_LIST) {
            foreach ($this->openSessions($product) as $session) {
                $matchId = $sessionId === '' || $sessionId === (string) ($session['id'] ?? '');
                if (
                    $matchId
                    && (string) ($session['exam_date'] ?? '') === $date
                    && (string) ($session['exam_time'] ?? '') === $timeShort
                ) {
                    return [
                        'kind' => self::KIND_PROVIDER_SESSION,
                        'session_id' => (string) ($session['id'] ?? ''),
                        'surcharge' => 0.0,
                        'requires_admin' => false,
                    ];
                }
            }
            throw new \InvalidArgumentException(
                'La convocatoria seleccionada no está disponible o ya cerró la inscripción.'
            );
        }

        if ($kind === self::KIND_EXTRAORDINARY) {
            $extra = $rules['extraordinary'];
            if (empty($extra['enabled']) || empty($extra['student_may_request'])) {
                throw new \InvalidArgumentException('Este examen no admite fechas extraordinarias.');
            }
            if (in_array($date, $rules['blocked_dates'], true)) {
                throw new \InvalidArgumentException('Esa fecha no está disponible (bloqueada / vacaciones).');
            }
            if ($date < (new \DateTimeImmutable('today'))->format('Y-m-d')) {
                throw new \InvalidArgumentException('La fecha extraordinaria no puede ser en el pasado.');
            }

            return [
                'kind' => self::KIND_EXTRAORDINARY,
                'session_id' => null,
                'surcharge' => (float) $extra['surcharge_amount'],
                'requires_admin' => !empty($extra['requires_admin_approval']),
            ];
        }

        $allowShort = !empty($options['allow_short_advance']);
        if ($date < $this->minSelectableDate($product) && !$allowShort) {
            throw new \InvalidArgumentException('La fecha de examen no cumple el anticipo mínimo requerido.');
        }
        if (in_array($date, $rules['blocked_dates'], true)) {
            throw new \InvalidArgumentException('Esa fecha de aplicación no está disponible (bloqueada / vacaciones).');
        }

        $allowed = array_column($this->slotsForDate($product, $date), 'value');
        if (!in_array($timeShort, $allowed, true)) {
            throw new \InvalidArgumentException('La hora seleccionada no está disponible para ese día.');
        }

        $resolvedKind = self::KIND_REGULAR;
        $requiresAdmin = false;
        if ($allowShort && $date < $this->minSelectableDate($product)) {
            $resolvedKind = self::KIND_AUTHORIZED_SHORT;
            $requiresAdmin = true;
        }

        return [
            'kind' => $resolvedKind,
            'session_id' => null,
            'surcharge' => 0.0,
            'requires_admin' => $requiresAdmin,
        ];
    }

    /** Compatibilidad con llamadas previas. */
    public function validateSlot(array $product, string $dateRaw, string $timeRaw): void
    {
        $this->validateSelection($product, $dateRaw, $timeRaw);
    }

    /**
     * Payload para la API de checkout.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public function checkoutPayload(array $product, string $date = ''): array
    {
        $rules = self::scheduleRules($product);
        $base = [
            'ok' => true,
            'mode' => $rules['mode'],
            'min_date' => $this->minSelectableDate($product),
            'min_advance_days' => (int) $rules['min_advance_days'],
            'checkout_help' => (string) ($rules['checkout_help'] ?? ''),
            'extraordinary' => $rules['extraordinary'],
        ];

        if ($rules['mode'] === self::MODE_DATED_LIST) {
            return $base + [
                'sessions' => $this->openSessions($product),
                'dates' => $this->selectableDates($product),
                'slots' => $date !== '' ? $this->slotsForDate($product, $date) : [],
            ];
        }

        if ($date === '') {
            return $base + [
                'dates' => $this->selectableDates($product),
                'slots' => [],
            ];
        }

        $slots = $this->slotsForDate($product, $date);

        return $base + [
            'slots' => $slots,
            'unavailable_reason' => $slots === [] ? $this->unavailabilityReason($product, $date) : null,
        ];
    }

    private function normalizeDate(?string $raw): ?string
    {
        return self::normalizeDateStatic((string) $raw);
    }

    public static function normalizeDateStatic(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        return $dt && $dt->format('Y-m-d') === $raw ? $raw : null;
    }

    private function normalizeTime(?string $raw): ?string
    {
        $clock = self::normalizeClock((string) $raw);
        if ($clock === null) {
            return null;
        }

        return $clock . ':00';
    }

    public static function normalizeClock(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $raw)) {
            $parts = explode(':', $raw);
            $h = (int) $parts[0];
            $m = (int) $parts[1];
            if ($h >= 0 && $h <= 23 && $m >= 0 && $m <= 59) {
                return sprintf('%02d:%02d', $h, $m);
            }
        }

        return null;
    }

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
