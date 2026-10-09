<?php
/** @var array<string,mixed> $partner */
/** @var array<string,mixed> $metrics */
/** @var array<string,string> $tierLabels */
$progress = is_array($metrics['progress'] ?? null) ? $metrics['progress'] : [];
$channel = is_array($metrics['channel'] ?? null) ? $metrics['channel'] : [];
$credit = is_array($metrics['credit'] ?? null) ? $metrics['credit'] : [];
$sales = is_array($metrics['sales'] ?? null) ? $metrics['sales'] : [];
$statusCounts = is_array($metrics['status_counts'] ?? null) ? $metrics['status_counts'] : [];
$legacy = (int) ($metrics['legacy_sales_bonus'] ?? 0);
$selfN = (int) ($channel['self'] ?? 0);
$batchN = (int) ($channel['batch'] ?? 0);
$codeN = (int) ($channel['code'] ?? 0);
$ownN = $selfN + $batchN;
$yearSales = (int) ($progress['year_sales'] ?? 0);
$systemYear = (int) ($progress['system_year_sales'] ?? max(0, $yearSales - $legacy));
$tierCode = (string) ($partner['tier'] ?? '');
$statusLabels = [
    'draft' => 'Borrador',
    'awaiting_docs' => 'Docs',
    'awaiting_payment' => 'Por pagar',
    'payment_review' => 'En revisión',
    'paid' => 'Pagadas',
    'cancelled' => 'Canceladas',
    'refunded' => 'Reembolsadas',
];
?>
<p class="meta">
    <a href="<?= e(url('/admin/partners')) ?>">← Partners</a>
    ·
    <a href="<?= e(url('/admin/partners/' . (int) $partner['id'])) ?>">Editar ficha</a>
</p>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">
    <div>
        <h1 style="margin:.2rem 0;color:var(--doceo-blue)">
            Avance · <?= e((string) ($partner['display_name'] ?? '')) ?>
        </h1>
        <p class="muted" style="margin:.25rem 0 0">
            Código <code><?= e((string) ($partner['code'] ?? '')) ?></code>
            · nivel
            <strong><?= e($tierLabels[$tierCode] ?? strtoupper($tierCode)) ?></strong>
            · convenio
            <?= e((string) ($progress['period_start'] ?? '')) ?>
            → <?= e((string) ($progress['period_end'] ?? '')) ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/partners/' . (int) $partner['id'])) ?>">Editar partner</a>
</div>

<div class="stats" style="margin:1rem 0">
    <div class="stat">
        <div class="label">Ventas del convenio</div>
        <div class="value" style="font-size:1.25rem"><?= $yearSales ?></div>
        <?php if ($legacy > 0): ?>
            <div class="muted" style="font-size:.75rem;margin-top:.25rem">
                <?= $systemYear ?> en sistema + <?= $legacy ?> históricas
            </div>
        <?php endif; ?>
    </div>
    <div class="stat">
        <div class="label">Ventas del mes</div>
        <div class="value" style="font-size:1.25rem"><?= (int) ($progress['month_sales'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label">Registro propio</div>
        <div class="value" style="font-size:1.25rem"><?= $ownN ?></div>
        <div class="muted" style="font-size:.75rem;margin-top:.25rem">
            <?= $selfN ?> individual · <?= $batchN ?> lote
        </div>
    </div>
    <div class="stat">
        <div class="label">Por código</div>
        <div class="value" style="font-size:1.25rem"><?= $codeN ?></div>
        <div class="muted" style="font-size:.75rem;margin-top:.25rem">
            Alumno compró con su código
        </div>
    </div>
    <div class="stat">
        <div class="label">Saldo a favor</div>
        <div class="value" style="font-size:1.15rem"><?= money($partner['credit_balance'] ?? 0) ?></div>
        <div class="muted" style="font-size:.75rem;margin-top:.25rem">
            +<?= money($channel['credit_earned_period'] ?? 0) ?> abonado en el convenio
            · −<?= money($channel['credit_used_period'] ?? 0) ?> usado
        </div>
    </div>
</div>

<?php if ($statusCounts !== []): ?>
    <div class="panel" style="margin-top:1rem">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Compras por estatus</h2>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <?php foreach ($statusCounts as $st => $n): ?>
                <span class="pill"><?= e($statusLabels[$st] ?? $st) ?>: <?= (int) $n ?></span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
$tierProgress = $progress;
require BASE_PATH . '/views/partials/partner_tier_progress.php';
?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">
        Certificaciones pagadas del convenio
    </h2>
    <p class="muted" style="margin-top:0;font-size:.85rem">
        Canal: <strong>Registro partner</strong> = alta desde el portal;
        <strong>Lote / grupo</strong> = registro masivo;
        <strong>Por código</strong> = el alumno compró con el código del partner (genera crédito).
        El crédito se muestra por compra (no por cada ítem de un combo).
    </p>
    <?php if ($sales === []): ?>
        <div class="empty">No hay certificaciones pagadas en el periodo del convenio.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Fecha pago</th>
                    <th>Alumno</th>
                    <th>Matrícula</th>
                    <th>Producto</th>
                    <th>Canal</th>
                    <th>Crédito generado</th>
                    <th>Cobrado</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sales as $row): ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:.85rem"><?= e((string) ($row['paid_at'] ?? '')) ?></td>
                        <td>
                            <?= e((string) ($row['student_name'] ?? '')) ?>
                            <br><span class="muted" style="font-size:.8rem"><?= e((string) ($row['email'] ?? '')) ?></span>
                        </td>
                        <td><code><?= e((string) ($row['matricula'] ?? '')) ?></code></td>
                        <td><?= e((string) ($row['product_name'] ?? '')) ?></td>
                        <td>
                            <span class="pill"><?= e((string) ($row['channel_label'] ?? '')) ?></span>
                            <?php if (($row['discount_code'] ?? '') !== ''): ?>
                                <br><span class="muted" style="font-size:.75rem"><?= e((string) $row['discount_code']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;color:<?= ((float) ($row['credit_earned'] ?? 0) > 0.009) ? '#15803d' : 'inherit' ?>">
                            <?php if ((float) ($row['credit_earned'] ?? 0) > 0.009): ?>
                                +<?= money($row['credit_earned']) ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= money($row['charged_amount'] ?? 0) ?></td>
                        <td>
                            <?php if ((int) ($row['tracking_id'] ?? 0) > 0): ?>
                                <a href="<?= e(url('/admin/seguimientos/' . (int) $row['tracking_id'])) ?>">Caso</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Movimientos de crédito</h2>
    <div class="stats" style="margin:0 0 1rem">
        <div class="stat">
            <div class="label">Saldo actual</div>
            <div class="value"><?= money($credit['balance'] ?? $partner['credit_balance'] ?? 0) ?></div>
        </div>
        <div class="stat">
            <div class="label">Abonos del mes</div>
            <div class="value" style="color:#15803d">+<?= money($credit['month_credits'] ?? 0) ?></div>
        </div>
        <div class="stat">
            <div class="label">Usos del mes</div>
            <div class="value" style="color:#b45309">−<?= money($credit['month_debits'] ?? 0) ?></div>
        </div>
        <div class="stat">
            <div class="label">Histórico</div>
            <div class="value" style="font-size:.95rem">
                +<?= money($credit['all_credits'] ?? 0) ?>
                · −<?= money($credit['all_debits'] ?? 0) ?>
            </div>
        </div>
    </div>
    <?php
    $movements = is_array($credit['movements'] ?? null) ? $credit['movements'] : [];
    if ($movements === []):
    ?>
        <div class="empty">Sin movimientos de crédito registrados.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Monto</th>
                    <th>Matrícula</th>
                    <th>Producto</th>
                    <th>Nota</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($movements, 0, 40) as $m): ?>
                    <?php $isCredit = ($m['type'] ?? '') === 'credit'; ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:.85rem"><?= e((string) ($m['at'] ?? '')) ?></td>
                        <td><span class="pill"><?= $isCredit ? 'Abono' : 'Uso' ?></span></td>
                        <td style="font-weight:700;color:<?= $isCredit ? '#15803d' : '#b45309' ?>">
                            <?= $isCredit ? '+' : '−' ?><?= money($m['amount'] ?? 0) ?>
                        </td>
                        <td><?= e((string) ($m['matricula'] ?? '—')) ?></td>
                        <td><?= e((string) ($m['product_name'] ?? '—')) ?></td>
                        <td class="muted" style="font-size:.85rem"><?= e((string) ($m['note'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
