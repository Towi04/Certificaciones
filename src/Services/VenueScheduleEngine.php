<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reglas de agenda por sede (UKS / Cambridge).
 *
 * Tipos de rule:
 * - recurring: DOWs + horas + horizonte + deadline
 * - dated: lista explícita de sesiones (sin repetir sede)
 * - open_window: días + ventana + min_advance_days
 */
final class VenueScheduleEngine
{
    public const RULE_RECURRING = 'recurring';
    public const RULE_DATED = 'dated';
    public const RULE_OPEN_WINDOW = 'open_window';

    /**
     * @param array<string, mixed> $schedule config schedule del grupo
     * @return list<array<string, mixed>>
     */
    public static function normalizeVenues(array $schedule): array
    {
        $raw = is_array($schedule['venues'] ?? null) ? $schedule['venues'] : [];
        $out = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = self::sanitizeId((string) ($row['id'] ?? ''));
            $name = trim((string) ($row['name'] ?? $row['venue'] ?? ''));
            if ($id === '' && $name !== '') {
                $id = self::sanitizeId($name);
            }
            if ($id === '') {
                $id = 'sede_' . ($i + 1);
            }
            $rule = is_array($row['rule'] ?? null) ? $row['rule'] : [];
            $type = strtolower(trim((string) ($rule['type'] ?? self::RULE_DATED)));
            if (!in_array($type, [self::RULE_RECURRING, self::RULE_DATED, self::RULE_OPEN_WINDOW], true)) {
                $type = self::RULE_DATED;
            }
            $normalizedRule = match ($type) {
                self::RULE_RECURRING => self::normalizeRecurringRule($rule),
                self::RULE_OPEN_WINDOW => self::normalizeOpenWindowRule($rule),
                default => self::normalizeDatedRule($rule),
            };
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
                'city' => trim((string) ($row['city'] ?? '')),
                'address' => trim((string) ($row['address'] ?? '')),
                'active' => array_key_exists('active', $row) ? (bool) $row['active'] : true,
                'rule' => $normalizedRule,
            ];
        }

        return $out;
    }

    /**
     * Materializa sesiones abiertas (recurring + dated). open_window no genera sessions.
     *
     * @param list<array<string, mixed>> $venues
     * @param list<string> $blockedDates Y-m-d
     * @return list<array<string, mixed>>
     */
    public static function materializeSessions(array $venues, array $blockedDates = []): array
    {
        $blocked = array_fill_keys($blockedDates, true);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $out = [];
        foreach ($venues as $venue) {
            if (empty($venue['active'])) {
                continue;
            }
            $rule = is_array($venue['rule'] ?? null) ? $venue['rule'] : [];
            $type = (string) ($rule['type'] ?? '');
            $sessions = match ($type) {
                self::RULE_RECURRING => self::expandRecurring($venue, $rule, $blocked),
                self::RULE_DATED => self::expandDated($venue, $rule, $blocked),
                default => [],
            };
            foreach ($sessions as $session) {
                $deadline = (string) ($session['registration_deadline'] ?? '');
                if ($deadline !== '' && $deadline < $today) {
                    continue;
                }
                if (($session['exam_date'] ?? '') < $today) {
                    continue;
                }
                $out[] = $session;
            }
        }
        usort(
            $out,
            static fn (array $a, array $b): int => strcmp(
                (string) ($a['exam_date'] ?? '') . (string) ($a['exam_time'] ?? ''),
                (string) ($b['exam_date'] ?? '') . (string) ($b['exam_time'] ?? '')
            )
        );

        return $out;
    }

    /**
     * @param array<string, mixed> $venue
     * @param list<string> $blockedDates
     * @return list<string> Y-m-d
     */
    public static function openWindowDates(array $venue, array $blockedDates = [], int $horizonDays = 90): array
    {
        if (empty($venue['active'])) {
            return [];
        }
        $rule = is_array($venue['rule'] ?? null) ? $venue['rule'] : [];
        if (($rule['type'] ?? '') !== self::RULE_OPEN_WINDOW) {
            return [];
        }
        $blocked = array_fill_keys($blockedDates, true);
        $minAdvance = max(0, (int) ($rule['min_advance_days'] ?? 2));
        $start = (new \DateTimeImmutable('today'))->modify('+' . $minAdvance . ' days');
        $end = $start->modify('+' . max(1, $horizonDays) . ' days');
        $days = is_array($rule['days'] ?? null) ? $rule['days'] : [];
        $out = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            if (isset($blocked[$ymd])) {
                continue;
            }
            $dow = (int) $d->format('w');
            if (empty($days[(string) $dow]) && empty($days[$dow])) {
                continue;
            }
            if (self::openWindowSlotsForDate($venue, $ymd) !== []) {
                $out[] = $ymd;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $venue
     * @return list<array{value:string,label:string,kind:string}>
     */
    public static function openWindowSlotsForDate(array $venue, string $dateYmd): array
    {
        $rule = is_array($venue['rule'] ?? null) ? $venue['rule'] : [];
        if (($rule['type'] ?? '') !== self::RULE_OPEN_WINDOW) {
            return [];
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd);
        if (!$dt || $dt->format('Y-m-d') !== $dateYmd) {
            return [];
        }
        $dow = (int) $dt->format('w');
        $days = is_array($rule['days'] ?? null) ? $rule['days'] : [];
        if (empty($days[(string) $dow]) && empty($days[$dow])) {
            return [];
        }
        $window = in_array($dow, [0, 6], true)
            ? (is_array($rule['saturday'] ?? null) ? $rule['saturday'] : [])
            : (is_array($rule['weekdays'] ?? null) ? $rule['weekdays'] : []);
        $start = self::parseClock($dateYmd, (string) ($window['start'] ?? '09:00'), false);
        $end = self::parseClock($dateYmd, (string) ($window['end'] ?? '18:00'), true);
        if ($start === null || $end === null || $start >= $end) {
            return [];
        }
        $slotMinutes = max(15, (int) ($rule['slot_minutes'] ?? 30));
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
                'label' => $value,
                'kind' => ExamScheduleService::KIND_REGULAR,
            ];
            $cursor = $next;
        }

        return $slots;
    }

    public static function deadlineForExamDate(string $examDate, array $deadlineCfg): ?string
    {
        $exam = \DateTimeImmutable::createFromFormat('Y-m-d', $examDate);
        if (!$exam || $exam->format('Y-m-d') !== $examDate) {
            return null;
        }
        $type = strtolower(trim((string) ($deadlineCfg['type'] ?? 'days_before')));
        if ($type === 'previous_weekday') {
            $targetDow = (int) ($deadlineCfg['dow'] ?? 3); // miércoles
            if ($targetDow < 0 || $targetDow > 6) {
                $targetDow = 3;
            }
            $weeksBefore = max(1, (int) ($deadlineCfg['weeks_before'] ?? 1));
            // Lunes de la semana del examen (ISO: lun=1… dom=0→7).
            $examDow = (int) $exam->format('w');
            $mondayOffset = $examDow === 0 ? 6 : $examDow - 1;
            $monday = $exam->modify('-' . $mondayOffset . ' days');
            $targetMonday = $monday->modify('-' . ($weeksBefore * 7) . ' days');
            $offsetFromMonday = $targetDow === 0 ? 6 : $targetDow - 1;
            return $targetMonday->modify('+' . $offsetFromMonday . ' days')->format('Y-m-d');
        }

        $daysBefore = max(0, (int) ($deadlineCfg['days_before'] ?? ($deadlineCfg['days'] ?? 7)));
        return $exam->modify('-' . $daysBefore . ' days')->format('Y-m-d');
    }

    public static function sanitizeId(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private static function normalizeRecurringRule(array $rule): array
    {
        $dows = [];
        foreach (is_array($rule['dows'] ?? null) ? $rule['dows'] : [] as $d) {
            $n = (int) $d;
            if ($n >= 0 && $n <= 6) {
                $dows[$n] = $n;
            }
        }
        $dows = array_values($dows);
        if ($dows === []) {
            $dows = [2]; // martes por defecto (UKS)
        }
        $times = [];
        foreach (is_array($rule['times'] ?? null) ? $rule['times'] : [] as $t) {
            $clock = ExamScheduleService::normalizeClock((string) $t);
            if ($clock !== null) {
                $times[] = $clock;
            }
        }
        if ($times === []) {
            $times = ['10:00'];
        }
        $deadline = is_array($rule['deadline'] ?? null) ? $rule['deadline'] : [];

        return [
            'type' => self::RULE_RECURRING,
            'dows' => $dows,
            'times' => array_values(array_unique($times)),
            'horizon_weeks' => max(1, min(52, (int) ($rule['horizon_weeks'] ?? 16))),
            'deadline' => [
                'type' => strtolower(trim((string) ($deadline['type'] ?? 'previous_weekday'))) === 'days_before'
                    ? 'days_before'
                    : 'previous_weekday',
                'dow' => (int) ($deadline['dow'] ?? 3),
                'weeks_before' => max(1, (int) ($deadline['weeks_before'] ?? 1)),
                'days_before' => max(0, (int) ($deadline['days_before'] ?? 6)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private static function normalizeDatedRule(array $rule): array
    {
        $sessions = [];
        foreach (is_array($rule['sessions'] ?? null) ? $rule['sessions'] : [] as $i => $session) {
            if (!is_array($session)) {
                continue;
            }
            $examDate = ExamScheduleService::normalizeDateStatic((string) ($session['exam_date'] ?? ''));
            if ($examDate === null) {
                continue;
            }
            $examTime = ExamScheduleService::normalizeClock((string) ($session['exam_time'] ?? '10:00')) ?? '10:00';
            $deadline = ExamScheduleService::normalizeDateStatic((string) ($session['registration_deadline'] ?? ''));
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
            ];
        }

        return [
            'type' => self::RULE_DATED,
            'sessions' => $sessions,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private static function normalizeOpenWindowRule(array $rule): array
    {
        $daysCfg = is_array($rule['days'] ?? null) ? $rule['days'] : null;
        $days = [];
        foreach ([0, 1, 2, 3, 4, 5, 6] as $d) {
            $days[(string) $d] = $daysCfg === null
                ? in_array($d, [1, 2, 3, 4, 5], true)
                : (!empty($daysCfg[(string) $d]) || !empty($daysCfg[$d]));
        }
        $weekdays = is_array($rule['weekdays'] ?? null) ? $rule['weekdays'] : [];
        $saturday = is_array($rule['saturday'] ?? null) ? $rule['saturday'] : [];

        return [
            'type' => self::RULE_OPEN_WINDOW,
            'days' => $days,
            'weekdays' => [
                'start' => (string) ($weekdays['start'] ?? '09:00'),
                'end' => (string) ($weekdays['end'] ?? '18:00'),
            ],
            'saturday' => [
                'start' => (string) ($saturday['start'] ?? '09:00'),
                'end' => (string) ($saturday['end'] ?? '14:00'),
            ],
            'min_advance_days' => max(0, (int) ($rule['min_advance_days'] ?? 2)),
            'slot_minutes' => max(15, (int) ($rule['slot_minutes'] ?? 30)),
        ];
    }

    /**
     * @param array<string, mixed> $venue
     * @param array<string, mixed> $rule
     * @param array<string, true> $blocked
     * @return list<array<string, mixed>>
     */
    private static function expandRecurring(array $venue, array $rule, array $blocked): array
    {
        $dows = array_map('intval', is_array($rule['dows'] ?? null) ? $rule['dows'] : []);
        $times = is_array($rule['times'] ?? null) ? $rule['times'] : ['10:00'];
        $weeks = max(1, (int) ($rule['horizon_weeks'] ?? 16));
        $deadlineCfg = is_array($rule['deadline'] ?? null) ? $rule['deadline'] : [];
        $start = new \DateTimeImmutable('today');
        $end = $start->modify('+' . ($weeks * 7) . ' days');
        $out = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $dow = (int) $d->format('w');
            if (!in_array($dow, $dows, true)) {
                continue;
            }
            $ymd = $d->format('Y-m-d');
            if (isset($blocked[$ymd])) {
                continue;
            }
            $deadline = self::deadlineForExamDate($ymd, $deadlineCfg);
            foreach ($times as $time) {
                $time = (string) $time;
                $out[] = [
                    'id' => (string) ($venue['id'] ?? 'v') . '_' . $ymd . '_' . str_replace(':', '', $time),
                    'exam_date' => $ymd,
                    'exam_time' => $time,
                    'registration_deadline' => $deadline,
                    'label' => trim((string) ($venue['name'] ?? '')),
                    'venue' => (string) ($venue['name'] ?? ''),
                    'city' => (string) ($venue['city'] ?? ''),
                    'address' => (string) ($venue['address'] ?? ''),
                    'venue_id' => (string) ($venue['id'] ?? ''),
                    'rule_type' => self::RULE_RECURRING,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $venue
     * @param array<string, mixed> $rule
     * @param array<string, true> $blocked
     * @return list<array<string, mixed>>
     */
    private static function expandDated(array $venue, array $rule, array $blocked): array
    {
        $out = [];
        foreach (is_array($rule['sessions'] ?? null) ? $rule['sessions'] : [] as $session) {
            if (!is_array($session)) {
                continue;
            }
            $ymd = (string) ($session['exam_date'] ?? '');
            if ($ymd === '' || isset($blocked[$ymd])) {
                continue;
            }
            $out[] = [
                'id' => (string) ($session['id'] ?? ((string) ($venue['id'] ?? 'v') . '_' . $ymd)),
                'exam_date' => $ymd,
                'exam_time' => (string) ($session['exam_time'] ?? '10:00'),
                'registration_deadline' => $session['registration_deadline'] ?? null,
                'label' => trim((string) ($session['label'] ?? $venue['name'] ?? '')),
                'venue' => (string) ($venue['name'] ?? ''),
                'city' => (string) ($venue['city'] ?? ''),
                'address' => (string) ($venue['address'] ?? ''),
                'venue_id' => (string) ($venue['id'] ?? ''),
                'rule_type' => self::RULE_DATED,
            ];
        }

        return $out;
    }

    private static function parseClock(string $date, string $clock, bool $endExclusive): ?\DateTimeImmutable
    {
        $clock = ExamScheduleService::normalizeClock($clock);
        if ($clock === null) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $clock);
        if (!$dt) {
            return null;
        }
        // endExclusive no cambia el instante; se usa solo por simetría con ExamScheduleService.
        unset($endExclusive);

        return $dt;
    }
}
