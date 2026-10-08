<?php
/** @var array<string,mixed>|null $tierProgress */
$tierProgress = is_array($tierProgress ?? null) ? $tierProgress : null;
if (!$tierProgress || empty($tierProgress['in_program'])) {
    return;
}
if (empty($tierProgress['enabled'])) {
    ?>
    <div class="panel">
        <p class="muted" style="margin:0">
            Tu convenio es especial y no participa en la escala Bronze / Silver / Gold.
        </p>
    </div>
    <?php
    return;
}
$yearSales = (int) ($tierProgress['year_sales'] ?? 0);
$next = is_array($tierProgress['next_tier'] ?? null) ? $tierProgress['next_tier'] : null;
$atRisk = !empty($tierProgress['at_risk']);
$daysLeft = $tierProgress['days_left'];
$pct = (int) ($tierProgress['progress_pct'] ?? 0);
?>
<div class="panel partner-tier-panel" data-tour="tier-progress">
    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">
        <div>
            <h2 style="margin:0 0 .35rem;color:var(--doceo-blue);font-size:1.15rem">Tu progreso de nivel</h2>
            <p class="muted" style="margin:0;font-size:.85rem;max-width:36rem">
                Convenio <?= e((string) ($tierProgress['period_start'] ?? '')) ?>
                → <?= e((string) ($tierProgress['period_end'] ?? '')) ?>
                <?php if ($daysLeft !== null): ?>
                    ·
                    <?php if ((int) $daysLeft < 0): ?>
                        periodo cerrado
                    <?php elseif ((int) $daysLeft === 0): ?>
                        cierra hoy
                    <?php else: ?>
                        quedan <strong><?= (int) $daysLeft ?></strong> día(s)
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
        <span class="pill" style="<?= $atRisk ? 'background:#fecaca;color:#991b1b' : 'background:#dcfce7;color:#166534' ?>">
            <?= $atRisk ? 'En riesgo de bajar' : 'Nivel al día' ?>
        </span>
    </div>

    <?php if ($next !== null): ?>
        <p style="margin:.85rem 0 .4rem;font-size:.92rem">
            Te faltan <strong><?= (int) ($tierProgress['sales_to_next'] ?? 0) ?></strong>
            certificación(es) para llegar a
            <strong><?= e((string) ($next['label'] ?? '')) ?></strong>
            (<?= e((string) ($next['range_label'] ?? '')) ?>).
        </p>
        <div class="partner-tier-bar" aria-hidden="true">
            <div class="partner-tier-bar-fill" style="width:<?= max(4, min(100, $pct)) ?>%"></div>
        </div>
    <?php else: ?>
        <p style="margin:.85rem 0 .4rem;font-size:.92rem">
            ¡Vas en el nivel más alto de la escala con <strong><?= $yearSales ?></strong> certificación(es)!
        </p>
    <?php endif; ?>

    <?php if ($atRisk): ?>
        <p class="flash flash-error" style="margin:.85rem 0 0">
            Con tu ritmo actual podrías bajar de
            <strong><?= e((string) ($tierProgress['current_label'] ?? '')) ?></strong>
            al evaluar el convenio
            (hoy calificarías como
            <strong><?= e((string) ($tierProgress['earned_label'] ?? 'Sin nivel')) ?></strong>).
            ¡Aún puedes recuperar ventas!
        </p>
    <?php endif; ?>

    <div class="table-wrap" style="margin-top:1rem">
        <table class="data">
            <thead>
            <tr>
                <th>Nivel</th>
                <th>Meta anual</th>
                <th>Tu avance</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (($tierProgress['tiers'] ?? []) as $t): ?>
                <?php
                $code = (string) ($t['code'] ?? '');
                $isCurrent = $code === (string) ($tierProgress['current_tier'] ?? '');
                $reached = $yearSales >= (int) ($t['min_sales'] ?? 0);
                ?>
                <tr style="<?= $isCurrent ? 'background:#eff6ff' : '' ?>">
                    <td>
                        <strong><?= e((string) ($t['label'] ?? '')) ?></strong>
                        <?php if ($isCurrent): ?>
                            <span class="pill" style="margin-left:.35rem">tu nivel</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($t['range_label'] ?? '')) ?></td>
                    <td>
                        <?php if ($reached): ?>
                            <span style="color:#15803d;font-weight:700">Alcanzado</span>
                        <?php else: ?>
                            <span class="muted">Faltan <?= max(0, (int) ($t['min_sales'] ?? 0) - $yearSales) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted" style="font-size:.78rem;margin:.65rem 0 0">
        Solo cuentan certificaciones con pago confirmado. Los partners con convenio especial
        no participan en esta escala.
    </p>
</div>
<style>
.partner-tier-bar {
  height:.65rem; border-radius:999px; background:#e2e8f0; overflow:hidden;
}
.partner-tier-bar-fill {
  height:100%; border-radius:999px;
  background:linear-gradient(90deg,#2563eb,#16a34a);
}
</style>
