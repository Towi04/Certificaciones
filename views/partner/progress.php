<?php
/** @var array<string,mixed>|null $partner */
/** @var array<string,mixed>|null $tierProgress */
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Avance / niveles</h1>
        <p class="muted" style="margin:.25rem 0 0">
            Nivel actual:
            <strong><?= e(\App\Services\PartnerAdminService::tierLabel($partner['tier'] ?? null)) ?></strong>
            · ventas del mes:
            <strong><?= (int) ($tierProgress['month_sales'] ?? 0) ?></strong>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/partner/alumnos')) ?>">← Alumnos</a>
</div>

<?php if ($partner): ?>
    <div class="stats" style="margin:1rem 0" data-tour="progress-stats">
        <div class="stat">
            <div class="label">Tu código</div>
            <div class="value" style="font-size:1.2rem"><?= e((string) $partner['code']) ?></div>
        </div>
        <div class="stat">
            <div class="label">Nivel</div>
            <div class="value" style="font-size:1.2rem"><?= e(\App\Services\PartnerAdminService::tierLabel($partner['tier'] ?? null)) ?></div>
        </div>
        <div class="stat">
            <div class="label">Saldo a favor</div>
            <div class="value" style="font-size:1.2rem">
                <a href="<?= e(url('/partner/credito')) ?>"><?= money($partner['credit_balance']) ?></a>
            </div>
        </div>
        <?php if ($tierProgress && !empty($tierProgress['in_program'])): ?>
            <div class="stat">
                <div class="label">Ventas del mes</div>
                <div class="value" style="font-size:1.2rem"><?= (int) ($tierProgress['month_sales'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">Ventas del convenio</div>
                <div class="value" style="font-size:1.2rem"><?= (int) ($tierProgress['year_sales'] ?? 0) ?></div>
                <?php if ((int) ($tierProgress['legacy_sales_bonus'] ?? 0) > 0): ?>
                    <div class="muted" style="font-size:.75rem;margin-top:.25rem">
                        <?= (int) ($tierProgress['system_year_sales'] ?? 0) ?> en sistema
                        + <?= (int) ($tierProgress['legacy_sales_bonus'] ?? 0) ?> históricas
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require BASE_PATH . '/views/partials/partner_tier_progress.php'; ?>
