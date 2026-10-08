<?php
/** @var array<string,mixed> $partner */
/** @var array<string,mixed> $history */
$history = is_array($history ?? null) ? $history : [];
$movements = is_array($history['movements'] ?? null) ? $history['movements'] : [];
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center" data-tour="credit-header">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Crédito</h1>
        <p class="muted" style="margin:.25rem 0 0">
            Movimientos de abono y uso de tu saldo a favor.
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/partner/alumnos')) ?>">← Alumnos</a>
</div>

<div class="stats" style="margin:1rem 0" data-tour="credit-balance">
    <div class="stat">
        <div class="label">Saldo actual</div>
        <div class="value" style="font-size:1.25rem"><?= money($history['balance'] ?? $partner['credit_balance'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label">Abonos del mes</div>
        <div class="value" style="font-size:1.1rem;color:#15803d">+<?= money($history['month_credits'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label">Usos del mes</div>
        <div class="value" style="font-size:1.1rem;color:#b45309">−<?= money($history['month_debits'] ?? 0) ?></div>
    </div>
    <div class="stat" data-tour="credit-totals">
        <div class="label">Total histórico</div>
        <div class="value" style="font-size:.95rem">
            +<?= money($history['all_credits'] ?? 0) ?>
            · −<?= money($history['all_debits'] ?? 0) ?>
        </div>
    </div>
</div>

<div class="panel" style="margin-top:1rem" data-tour="credit-movements">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Movimientos</h2>
    <?php if ($movements === []): ?>
        <div class="empty">Aún no hay abonos ni usos de crédito registrados.</div>
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
                <?php foreach ($movements as $m): ?>
                    <?php $isCredit = ($m['type'] ?? '') === 'credit'; ?>
                    <tr>
                        <td><?= e((string) ($m['at'] ?? '')) ?></td>
                        <td>
                            <span class="pill"><?= $isCredit ? 'Abono' : 'Uso' ?></span>
                        </td>
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
