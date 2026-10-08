<?php
/** @var array<string,mixed>|null $partner */
/** @var array<string,mixed>|null $tierProgress */
$tierProgress = is_array($tierProgress ?? null) ? $tierProgress : null;
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
    <h1 style="margin:0;color:var(--doceo-blue)">
        <?= e($partner['display_name'] ?? 'Partner') ?>
    </h1>
    <?php if ($partner): ?>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center">
            <a class="btn btn-accent" href="<?= e(url('/partner/registrar')) ?>">Registrar alumno</a>
            <a class="btn btn-ghost" href="<?= e(url('/catalogo')) ?>">Ver catálogo</a>
        </div>
    <?php endif; ?>
</div>
<?php if ($partner): ?>
    <div class="stats" style="margin:1rem 0">
        <div class="stat">
            <div class="label">Tu código</div>
            <div class="value" style="font-size:1.2rem"><?= e($partner['code']) ?></div>
        </div>
        <div class="stat">
            <div class="label">Nivel</div>
            <div class="value" style="font-size:1.2rem"><?= e(\App\Services\PartnerAdminService::tierLabel($partner['tier'] ?? null)) ?></div>
        </div>
        <div class="stat">
            <div class="label">Saldo a favor</div>
            <div class="value" style="font-size:1.2rem"><?= money($partner['credit_balance']) ?></div>
        </div>
        <?php if ($tierProgress && !empty($tierProgress['in_program'])): ?>
            <div class="stat">
                <div class="label">Ventas del mes</div>
                <div class="value" style="font-size:1.2rem"><?= (int) ($tierProgress['month_sales'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">Ventas del convenio</div>
                <div class="value" style="font-size:1.2rem"><?= (int) ($tierProgress['year_sales'] ?? 0) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($tierProgress && !empty($tierProgress['in_program']) && !empty($tierProgress['enabled'])): ?>
        <?php
        $yearSales = (int) ($tierProgress['year_sales'] ?? 0);
        $next = is_array($tierProgress['next_tier'] ?? null) ? $tierProgress['next_tier'] : null;
        $atRisk = !empty($tierProgress['at_risk']);
        $daysLeft = $tierProgress['days_left'];
        $pct = (int) ($tierProgress['progress_pct'] ?? 0);
        ?>
        <div class="panel partner-tier-panel" style="margin:0 0 1rem">
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
    <?php endif; ?>
<?php else: ?>
    <div class="flash flash-error">Tu usuario partner no tiene ficha ligada. Contacta a admin.</div>
<?php endif; ?>

<div class="panel">
    <h2>Alumnos / seguimientos</h2>
    <?php if ($trackings === []): ?>
        <div class="empty">
            Aún no hay alumnos.
            <a href="<?= e(url('/partner/registrar')) ?>">Elige un producto del catálogo</a>
            y completa el mismo flujo de registro (datos, reglamento, pago y progreso).
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Matrícula</th><th>Alumno</th><th>Producto</th><th>Examen</th><th>Estatus</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($trackings as $t): ?>
                    <tr>
                        <td><?= e($t['matricula']) ?></td>
                        <td><?= e(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name_p'] ?? ''))) ?><br><span class="muted"><?= e($t['email']) ?></span></td>
                        <td><?= e($t['product_name']) ?></td>
                        <td><?= e($t['exam_date'] ?? '—') ?><?php if (!empty($t['exam_time'])): ?> <?= e(substr((string) $t['exam_time'], 0, 5)) ?><?php endif; ?></td>
                        <td><span class="pill"><?= e($t['status']) ?></span></td>
                        <td><a href="<?= e(url('/partner/caso/' . $t['id'])) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
