<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\PartnerRepository;
use App\Support\Settings;
use PDO;

/**
 * Programa de niveles partner (Bronze / Silver / Gold) por ventas de certificaciones.
 *
 * - Admin define umbrales y periodo de convenio.
 * - Partners en escala ven progreso mes/año y la meta de cada nivel.
 * - Convenios especiales (CNCM u otros con tier_program=0) no ven el ranking ni se reevalúan.
 * - Aviso por correo a quienes van muy bajos en ventas (plantilla configurable).
 */
final class PartnerTierService
{
    public const SETTINGS_KEY = 'partner_tier_program';
    public const LADDER_TIERS = ['a', 'b', 'c'];

    private PDO $pdo;
    private PartnerRepository $partners;
    private static bool $schemaReady = false;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->partners = new PartnerRepository();
        self::ensureSchema($this->pdo);
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (self::$schemaReady) {
            return;
        }
        $pdo = $pdo ?? Connection::get();
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM partners')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $have = array_fill_keys(array_map('strval', $cols), true);
            if (!isset($have['tier_program'])) {
                $pdo->exec(
                    "ALTER TABLE partners
                     ADD COLUMN tier_program TINYINT(1) NOT NULL DEFAULT 1 AFTER tier"
                );
                $pdo->exec("UPDATE partners SET tier_program = 0 WHERE tier = 'cncm'");
            }
            if (!isset($have['agreement_starts_at'])) {
                $pdo->exec('ALTER TABLE partners ADD COLUMN agreement_starts_at DATE NULL AFTER notes');
            }
            if (!isset($have['agreement_ends_at'])) {
                $pdo->exec('ALTER TABLE partners ADD COLUMN agreement_ends_at DATE NULL AFTER agreement_starts_at');
            }
            if (!isset($have['tier_evaluated_at'])) {
                $pdo->exec('ALTER TABLE partners ADD COLUMN tier_evaluated_at DATETIME NULL AFTER agreement_ends_at');
            }
            self::$schemaReady = true;
        } catch (\Throwable $e) {
            error_log('[Doceo] partner tier schema: ' . $e->getMessage());
        }
    }

    /**
     * @return array{
     *   enabled:bool,
     *   period_start:string,
     *   period_end:string,
     *   tiers:list<array{code:string,label:string,min_sales:int}>,
     *   warning:array{
     *     enabled:bool,template_code:string,max_sales_year:int,months_without_sale:int
     *   }
     * }
     */
    public static function defaultConfig(): array
    {
        $year = (int) date('Y');

        return [
            'enabled' => true,
            'period_start' => sprintf('%04d-01-01', $year),
            'period_end' => sprintf('%04d-12-31', $year),
            'tiers' => [
                ['code' => 'a', 'label' => 'Bronze', 'min_sales' => 1],
                ['code' => 'b', 'label' => 'Silver', 'min_sales' => 50],
                ['code' => 'c', 'label' => 'Gold', 'min_sales' => 120],
            ],
            'warning' => [
                'enabled' => true,
                'template_code' => 'partner_low_sales_warning',
                'max_sales_year' => 1,
                'months_without_sale' => 8,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        $defaults = self::defaultConfig();
        $raw = Settings::get(self::SETTINGS_KEY, '');
        if ($raw === null || $raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $cfg = $defaults;
        $cfg['enabled'] = array_key_exists('enabled', $decoded) ? !empty($decoded['enabled']) : true;
        $start = trim((string) ($decoded['period_start'] ?? $defaults['period_start']));
        $end = trim((string) ($decoded['period_end'] ?? $defaults['period_end']));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $cfg['period_start'] = $start;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $cfg['period_end'] = $end;
        }

        $tiers = [];
        if (isset($decoded['tiers']) && is_array($decoded['tiers'])) {
            foreach ($decoded['tiers'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = strtolower(trim((string) ($row['code'] ?? '')));
                if (!in_array($code, self::LADDER_TIERS, true)) {
                    continue;
                }
                $label = trim((string) ($row['label'] ?? PartnerAdminService::tierLabel($code)));
                if ($label === '') {
                    $label = PartnerAdminService::tierLabel($code);
                }
                $tiers[] = [
                    'code' => $code,
                    'label' => $label,
                    'min_sales' => max(0, (int) ($row['min_sales'] ?? 0)),
                ];
            }
        }
        if ($tiers !== []) {
            usort($tiers, static fn (array $a, array $b): int => $a['min_sales'] <=> $b['min_sales']);
            $cfg['tiers'] = $tiers;
        }

        $warnIn = is_array($decoded['warning'] ?? null) ? $decoded['warning'] : [];
        $cfg['warning'] = [
            'enabled' => array_key_exists('enabled', $warnIn)
                ? !empty($warnIn['enabled'])
                : (bool) $defaults['warning']['enabled'],
            'template_code' => trim((string) ($warnIn['template_code'] ?? $defaults['warning']['template_code']))
                ?: (string) $defaults['warning']['template_code'],
            'max_sales_year' => max(0, (int) ($warnIn['max_sales_year'] ?? $defaults['warning']['max_sales_year'])),
            'months_without_sale' => max(1, (int) ($warnIn['months_without_sale'] ?? $defaults['warning']['months_without_sale'])),
        ];

        return $cfg;
    }

    /** @param array<string, mixed> $input */
    public function saveConfig(array $input): void
    {
        $defaults = self::defaultConfig();
        $start = trim((string) ($input['period_start'] ?? ''));
        $end = trim((string) ($input['period_end'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            throw new \InvalidArgumentException('Fechas de convenio inválidas (usa YYYY-MM-DD).');
        }
        if ($end < $start) {
            throw new \InvalidArgumentException('La fecha de término debe ser posterior al inicio.');
        }

        $mins = [
            'a' => max(0, (int) ($input['min_a'] ?? 1)),
            'b' => max(0, (int) ($input['min_b'] ?? 50)),
            'c' => max(0, (int) ($input['min_c'] ?? 120)),
        ];
        if (!($mins['a'] < $mins['b'] && $mins['b'] < $mins['c'])) {
            throw new \InvalidArgumentException(
                'Los umbrales deben ir en orden: Bronze < Silver < Gold (mínimos de ventas).'
            );
        }

        $cfg = [
            'enabled' => !empty($input['enabled']),
            'period_start' => $start,
            'period_end' => $end,
            'tiers' => [
                ['code' => 'a', 'label' => 'Bronze', 'min_sales' => $mins['a']],
                ['code' => 'b', 'label' => 'Silver', 'min_sales' => $mins['b']],
                ['code' => 'c', 'label' => 'Gold', 'min_sales' => $mins['c']],
            ],
            'warning' => [
                'enabled' => !empty($input['warning_enabled']),
                'template_code' => trim((string) ($input['warning_template'] ?? $defaults['warning']['template_code']))
                    ?: (string) $defaults['warning']['template_code'],
                'max_sales_year' => max(0, (int) ($input['warning_max_sales'] ?? 1)),
                'months_without_sale' => max(1, (int) ($input['warning_months'] ?? 8)),
            ],
        ];
        Settings::set(self::SETTINGS_KEY, json_encode($cfg, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    public static function isInProgram(array $partner): bool
    {
        $tier = strtolower(trim((string) ($partner['tier'] ?? '')));
        if (PartnerAdminService::isSpecialTier($tier) || $tier === 'cncm') {
            return false;
        }
        if (array_key_exists('tier_program', $partner)) {
            return !empty($partner['tier_program']);
        }

        return in_array($tier, self::LADDER_TIERS, true);
    }

    /**
     * Ventas de certificaciones pagadas del partner en un rango.
     * Cuenta ítems de compra (una certificación = 1 venta).
     */
    public function countCertificationSales(int $partnerId, string $fromYmd, string $toYmd): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM purchase_items pi
             JOIN purchases pu ON pu.id = pi.purchase_id
             JOIN products pr ON pr.id = pi.product_id
             WHERE pu.partner_id = ?
               AND pu.status = 'paid'
               AND pr.type = 'certification'
               AND DATE(COALESCE(pu.paid_at, pu.created_at)) BETWEEN ? AND ?"
        );
        $stmt->execute([$partnerId, $fromYmd, $toYmd]);

        return (int) $stmt->fetchColumn();
    }

    public function lastSaleDate(int $partnerId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT DATE(MAX(COALESCE(pu.paid_at, pu.created_at)))
             FROM purchases pu
             JOIN purchase_items pi ON pi.purchase_id = pu.id
             JOIN products pr ON pr.id = pi.product_id
             WHERE pu.partner_id = ?
               AND pu.status = 'paid'
               AND pr.type = 'certification'"
        );
        $stmt->execute([$partnerId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null || $val === '') {
            return null;
        }

        return (string) $val;
    }

    /**
     * @param list<array{code:string,label:string,min_sales:int}> $tiers
     * @return list<array{code:string,label:string,min_sales:int,max_sales:?int,range_label:string}>
     */
    public static function tiersWithRanges(array $tiers): array
    {
        usort($tiers, static fn (array $a, array $b): int => $a['min_sales'] <=> $b['min_sales']);
        $out = [];
        $n = count($tiers);
        for ($i = 0; $i < $n; $i++) {
            $min = (int) $tiers[$i]['min_sales'];
            $max = null;
            if ($i + 1 < $n) {
                $max = max($min, (int) $tiers[$i + 1]['min_sales'] - 1);
            }
            $range = $max === null
                ? ($min . '+')
                : ($min . '–' . $max);
            $out[] = [
                'code' => (string) $tiers[$i]['code'],
                'label' => (string) $tiers[$i]['label'],
                'min_sales' => $min,
                'max_sales' => $max,
                'range_label' => $range . ' cert./año',
            ];
        }

        return $out;
    }

    /** Nivel de escala que corresponde a N ventas (null si no alcanza el mínimo). */
    public function tierForSales(int $sales, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? $this->config();
        $tiers = self::tiersWithRanges($cfg['tiers'] ?? []);
        $best = null;
        foreach ($tiers as $t) {
            if ($sales >= (int) $t['min_sales']) {
                $best = (string) $t['code'];
            }
        }

        return $best;
    }

    /**
     * Periodo efectivo del partner (ficha propia o global).
     *
     * @return array{start:string,end:string}
     */
    public function agreementPeriod(array $partner, ?array $cfg = null): array
    {
        $cfg = $cfg ?? $this->config();
        $start = trim((string) ($partner['agreement_starts_at'] ?? ''));
        $end = trim((string) ($partner['agreement_ends_at'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = (string) $cfg['period_start'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = (string) $cfg['period_end'];
        }
        if ($end < $start) {
            $end = $start;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Resumen de progreso para el portal / admin.
     *
     * @return array<string, mixed>
     */
    public function progressForPartner(array $partner): array
    {
        $cfg = $this->config();
        $inProgram = self::isInProgram($partner) && !empty($cfg['enabled']);
        $period = $this->agreementPeriod($partner, $cfg);
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        $yearSales = $this->countCertificationSales(
            (int) $partner['id'],
            $period['start'],
            min($today, $period['end'])
        );
        $monthSales = $this->countCertificationSales(
            (int) $partner['id'],
            max($monthStart, $period['start']),
            min($monthEnd, $period['end'], $today)
        );
        $lastSale = $this->lastSaleDate((int) $partner['id']);
        $tiers = self::tiersWithRanges($cfg['tiers'] ?? []);
        $earnedTier = $this->tierForSales($yearSales, $cfg);
        $currentTier = strtolower(trim((string) ($partner['tier'] ?? 'a')));
        $next = null;
        foreach ($tiers as $t) {
            if ($yearSales < (int) $t['min_sales']) {
                $next = $t;
                break;
            }
        }
        $atRisk = false;
        if ($inProgram && in_array($currentTier, self::LADDER_TIERS, true) && $earnedTier !== null) {
            $rank = array_flip(self::LADDER_TIERS);
            $atRisk = ($rank[$earnedTier] ?? 0) < ($rank[$currentTier] ?? 0);
        } elseif ($inProgram && in_array($currentTier, self::LADDER_TIERS, true) && $earnedTier === null) {
            $atRisk = true;
        }

        $daysLeft = null;
        try {
            $endDt = new \DateTimeImmutable($period['end']);
            $todayDt = new \DateTimeImmutable($today);
            $daysLeft = (int) $todayDt->diff($endDt)->format('%r%a');
        } catch (\Throwable) {
            $daysLeft = null;
        }

        $toNext = $next !== null ? max(0, (int) $next['min_sales'] - $yearSales) : 0;
        $progressPct = 100;
        if ($next !== null) {
            $prevMin = 0;
            foreach ($tiers as $t) {
                if ($t['code'] === $next['code']) {
                    break;
                }
                $prevMin = (int) $t['min_sales'];
            }
            $span = max(1, (int) $next['min_sales'] - $prevMin);
            $progressPct = (int) min(99, max(0, round((($yearSales - $prevMin) / $span) * 100)));
        }

        return [
            'enabled' => !empty($cfg['enabled']),
            'in_program' => $inProgram,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'days_left' => $daysLeft,
            'month_sales' => $monthSales,
            'year_sales' => $yearSales,
            'last_sale_date' => $lastSale,
            'current_tier' => $currentTier,
            'current_label' => PartnerAdminService::tierLabel($currentTier),
            'earned_tier' => $earnedTier,
            'earned_label' => $earnedTier !== null ? PartnerAdminService::tierLabel($earnedTier) : 'Sin nivel',
            'at_risk' => $atRisk,
            'next_tier' => $next,
            'sales_to_next' => $toNext,
            'progress_pct' => $progressPct,
            'tiers' => $tiers,
            'warning' => $cfg['warning'],
        ];
    }

    /**
     * Aplica el nivel ganado por ventas a partners del programa.
     *
     * @return array{updated:int,skipped:int,details:list<string>}
     */
    public function evaluateProgram(?string $asOfYmd = null, bool $force = false): array
    {
        $cfg = $this->config();
        if (empty($cfg['enabled'])) {
            return ['updated' => 0, 'skipped' => 0, 'details' => ['Programa desactivado.']];
        }
        $asOf = $asOfYmd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfYmd) ? $asOfYmd : date('Y-m-d');
        $partners = $this->partners->adminList(null, 2000, 0);
        $updated = 0;
        $skipped = 0;
        $details = [];
        foreach ($partners as $p) {
            if (!self::isInProgram($p) || empty($p['is_active'])) {
                $skipped++;
                continue;
            }
            $period = $this->agreementPeriod($p, $cfg);
            // Cron: solo al cerrar. Manual ($force): permite recalcular con ventas a la fecha.
            if (!$force && $asOf < $period['end']) {
                $skipped++;
                continue;
            }
            $to = $force ? min($asOf, $period['end']) : $period['end'];
            $sales = $this->countCertificationSales((int) $p['id'], $period['start'], $to);
            $earned = $this->tierForSales($sales, $cfg) ?? 'a';
            $current = strtolower(trim((string) ($p['tier'] ?? 'a')));
            if ($current === $earned) {
                $this->pdo->prepare(
                    'UPDATE partners SET tier_evaluated_at = NOW() WHERE id = ?'
                )->execute([(int) $p['id']]);
                $skipped++;
                $details[] = ($p['code'] ?? '') . ': se mantiene en '
                    . PartnerAdminService::tierLabel($current) . " ({$sales} ventas)";
                continue;
            }
            $this->pdo->prepare(
                'UPDATE partners SET tier = ?, tier_evaluated_at = NOW() WHERE id = ?'
            )->execute([$earned, (int) $p['id']]);
            $updated++;
            $details[] = ($p['code'] ?? '') . ': '
                . PartnerAdminService::tierLabel($current) . ' → '
                . PartnerAdminService::tierLabel($earned) . " ({$sales} ventas)";
        }
        Settings::set(
            'partner_tier_last_eval',
            json_encode([
                'at' => date('c'),
                'as_of' => $asOf,
                'updated' => $updated,
                'forced' => $force,
            ], JSON_UNESCAPED_UNICODE) ?: '{}'
        );

        return ['updated' => $updated, 'skipped' => $skipped, 'details' => $details];
    }

    /**
     * Envía avisos a partners del programa con pocas ventas.
     *
     * @return array{sent:int,skipped:int,errors:list<string>}
     */
    public function processLowSalesWarnings(): array
    {
        $cfg = $this->config();
        $warn = $cfg['warning'] ?? [];
        if (empty($cfg['enabled']) || empty($warn['enabled'])) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => []];
        }
        $template = trim((string) ($warn['template_code'] ?? 'partner_low_sales_warning'));
        $maxSales = max(0, (int) ($warn['max_sales_year'] ?? 1));
        $monthsWithout = max(1, (int) ($warn['months_without_sale'] ?? 8));
        $periodKey = (string) $cfg['period_start'] . '_' . (string) $cfg['period_end'];
        $today = new \DateTimeImmutable('today');
        $cutoff = $today->modify('-' . $monthsWithout . ' months')->format('Y-m-d');

        $mail = new MailTemplateService();
        if ($mail->find($template) === null) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => ['Plantilla no encontrada: ' . $template]];
        }

        $partners = $this->partners->adminList(null, 2000, 0);
        $sent = 0;
        $skipped = 0;
        $errors = [];
        foreach ($partners as $p) {
            if (!self::isInProgram($p) || empty($p['is_active'])) {
                $skipped++;
                continue;
            }
            $period = $this->agreementPeriod($p, $cfg);
            // Solo avisar cuando ya pasó el umbral de meses dentro del convenio.
            $periodStart = $period['start'];
            if ($today->format('Y-m-d') < $periodStart) {
                $skipped++;
                continue;
            }
            $monthsInto = 0;
            try {
                $startDt = new \DateTimeImmutable($periodStart);
                $monthsInto = ((int) $today->format('Y') - (int) $startDt->format('Y')) * 12
                    + ((int) $today->format('n') - (int) $startDt->format('n'));
            } catch (\Throwable) {
                $monthsInto = 0;
            }
            if ($monthsInto < $monthsWithout) {
                $skipped++;
                continue;
            }

            $sales = $this->countCertificationSales(
                (int) $p['id'],
                $period['start'],
                min($today->format('Y-m-d'), $period['end'])
            );
            if ($sales > $maxSales) {
                $skipped++;
                continue;
            }
            $lastSale = $this->lastSaleDate((int) $p['id']);
            if ($lastSale !== null && $lastSale > $cutoff && $sales > 0) {
                // Vendió algo reciente aunque el acumulado anual sea bajo: aún avisar si sales <= max.
                // Si tiene venta reciente y sales<=max, igual avisamos (van mal en el año).
            }

            $dedupeKey = 'partner_low_sales_warn_' . (int) $p['id'] . '_' . $periodKey;
            $prev = Settings::get($dedupeKey, '');
            if ($prev !== null && $prev !== '') {
                $skipped++;
                continue;
            }

            $email = trim((string) ($p['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            $progress = $this->progressForPartner($p);
            $vars = [
                'partner_name' => (string) ($p['display_name'] ?? ''),
                'partner_code' => (string) ($p['code'] ?? ''),
                'partner_email' => $email,
                'partner_tier' => PartnerAdminService::tierLabel((string) ($p['tier'] ?? '')),
                'sales_year' => (string) $sales,
                'sales_month' => (string) ($progress['month_sales'] ?? 0),
                'max_sales_warning' => (string) $maxSales,
                'months_without_sale' => (string) $monthsWithout,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'days_left' => (string) ($progress['days_left'] ?? ''),
                'login_url' => url('/login'),
                'portal_url' => url('/partner'),
            ];
            try {
                if ($mail->render($template, $vars) === null) {
                    $errors[] = ($p['code'] ?? '') . ': plantilla inválida';
                    continue;
                }
                $mail->send($template, $email, $vars);
                Settings::set($dedupeKey, date('c'));
                $sent++;
            } catch (\Throwable $e) {
                $errors[] = ($p['code'] ?? '') . ': ' . $e->getMessage();
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Tareas periódicas (evaluación al cierre + avisos).
     *
     * @return array{evaluated:array<string,mixed>,warnings:array<string,mixed>}
     */
    public function processDue(): array
    {
        $cfg = $this->config();
        $evaluated = ['updated' => 0, 'skipped' => 0, 'details' => []];
        if (!empty($cfg['enabled']) && date('Y-m-d') >= (string) $cfg['period_end']) {
            $lastRaw = Settings::get('partner_tier_last_eval', '');
            $last = is_string($lastRaw) && $lastRaw !== '' ? json_decode($lastRaw, true) : null;
            $already = is_array($last) && ($last['as_of'] ?? '') === (string) $cfg['period_end'];
            if (!$already) {
                $evaluated = $this->evaluateProgram((string) $cfg['period_end']);
            }
        }
        $warnings = $this->processLowSalesWarnings();

        return ['evaluated' => $evaluated, 'warnings' => $warnings];
    }
}
