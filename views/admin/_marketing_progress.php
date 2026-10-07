<?php
/**
 * Barra de progreso de campaña.
 * @var array<string,int> $counts  pending/sent/failed/skipped/cancelled/total
 * @var string $status             draft|scheduled|running|paused|completed|cancelled
 * @var bool $compact
 */
$counts = is_array($counts ?? null) ? $counts : [];
$status = (string) ($status ?? 'draft');
$compact = !empty($compact);
$total = max(0, (int) ($counts['total'] ?? 0));
$sent = max(0, (int) ($counts['sent'] ?? 0));
$failed = max(0, (int) ($counts['failed'] ?? 0));
$pending = max(0, (int) ($counts['pending'] ?? 0));
$cancelled = max(0, (int) ($counts['cancelled'] ?? 0));
$skipped = max(0, (int) ($counts['skipped'] ?? 0));
$done = $sent + $failed + $cancelled + $skipped;
$pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
$sentPct = $total > 0 ? (100 * $sent / $total) : 0;
$failedPct = $total > 0 ? (100 * $failed / $total) : 0;
$pendingPct = $total > 0 ? (100 * $pending / $total) : 0;
$otherPct = max(0, 100 - $sentPct - $failedPct - $pendingPct);

if ($status === 'completed') {
    $label = 'Completada · ' . $sent . '/' . $total . ' enviados'
        . ($failed > 0 ? (' · ' . $failed . ' fallidos') : '');
} elseif ($status === 'cancelled') {
    $label = 'Cancelada · ' . $sent . '/' . $total . ' enviados antes de cancelar';
} elseif ($status === 'paused') {
    $label = 'Pausada · ' . $sent . '/' . $total . ' enviados (' . $pct . '%) · ' . $pending . ' pendientes';
} elseif ($status === 'scheduled') {
    $label = 'Programada · 0/' . $total . ' · espera ventana de fechas';
} elseif ($status === 'running') {
    $label = 'Enviando · ' . $sent . '/' . $total . ' (' . $pct . '%) · ' . $pending . ' pendientes';
} elseif ($total > 0) {
    $label = $sent . '/' . $total . ' enviados';
} else {
    $label = 'Sin cola todavía';
}
?>
<div class="mkt-progress<?= $compact ? ' mkt-progress--compact' : '' ?>" role="img"
     aria-label="<?= e($label) ?>">
    <?php if ($total > 0): ?>
        <div class="mkt-progress-track">
            <?php if ($sentPct > 0.05): ?>
                <span class="mkt-progress-seg mkt-progress-seg--sent" style="width:<?= e(number_format($sentPct, 2, '.', '')) ?>%"></span>
            <?php endif; ?>
            <?php if ($failedPct > 0.05): ?>
                <span class="mkt-progress-seg mkt-progress-seg--failed" style="width:<?= e(number_format($failedPct, 2, '.', '')) ?>%"></span>
            <?php endif; ?>
            <?php if ($pendingPct > 0.05): ?>
                <span class="mkt-progress-seg mkt-progress-seg--pending" style="width:<?= e(number_format($pendingPct, 2, '.', '')) ?>%"></span>
            <?php endif; ?>
            <?php if ($otherPct > 0.05): ?>
                <span class="mkt-progress-seg mkt-progress-seg--other" style="width:<?= e(number_format($otherPct, 2, '.', '')) ?>%"></span>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="mkt-progress-track mkt-progress-track--empty"></div>
    <?php endif; ?>
    <div class="mkt-progress-label"><?= e($label) ?></div>
</div>
<style>
.mkt-progress { display:flex; flex-direction:column; gap:.35rem; min-width:<?= $compact ? '140px' : '220px' ?>; }
.mkt-progress-track {
  display:flex; height:<?= $compact ? '8px' : '12px' ?>; border-radius:999px; overflow:hidden;
  background:#e8eef6; border:1px solid #d5deea;
}
.mkt-progress-track--empty { background:#eef2f7; }
.mkt-progress-seg { display:block; height:100%; }
.mkt-progress-seg--sent { background:#16a34a; }
.mkt-progress-seg--failed { background:#dc2626; }
.mkt-progress-seg--pending { background:#93c5fd; }
.mkt-progress-seg--other { background:#94a3b8; }
.mkt-progress-label { font-size:<?= $compact ? '.75rem' : '.85rem' ?>; color:#475569; font-weight:600; line-height:1.3; }
</style>
