<?php
/** @var array<string,mixed> $purchase */
/** @var list<array<string,mixed>> $items */
/** @var list<array<string,mixed>> $trackings */
/** @var list<array<string,mixed>> $documents */
$canConfirm = in_array($purchase['status'], ['awaiting_payment', 'payment_review'], true);
?>
<p class="meta"><a href="<?= e(url('/admin/maestra')) ?>">← Tabla maestra</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">Matrícula <?= e($purchase['matricula']) ?></h1>
<p>
    <span class="pill"><?= e($purchase['status']) ?></span>
    · <?= money($purchase['charged_amount']) ?>
    · <?= e($purchase['payment_method']) ?>
</p>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Alumno</h2>
    <p>
        <?= e(trim(($purchase['first_name'] ?? '') . ' ' . ($purchase['last_name_p'] ?? '') . ' ' . ($purchase['last_name_m'] ?? ''))) ?><br>
        <?= e($purchase['student_email']) ?>
        <?php if (!empty($purchase['student_phone'])): ?> · <?= e($purchase['student_phone']) ?><?php endif; ?>
    </p>
    <?php if (!empty($purchase['partner_code'])): ?>
        <p class="muted">Partner: <?= e($purchase['partner_name'] ?? '') ?> (<?= e($purchase['partner_code']) ?>)</p>
    <?php endif; ?>
    <?php if ((float) $purchase['partner_credit_earned'] > 0): ?>
        <p>Crédito partner al confirmar: <strong><?= money($purchase['partner_credit_earned']) ?></strong></p>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Productos</h2>
    <ul>
        <?php foreach ($items as $it): ?>
            <li><?= e($it['product_name']) ?> — lista <?= money($it['unit_public_price']) ?> · cobrado <?= money($it['unit_charged_price']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Documentos</h2>
    <?php if ($documents === []): ?>
        <p class="muted">Sin documentos.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Tipo</th><th>Archivo</th><th>Estatus</th><th>Fecha</th></tr></thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td><?= e($d['doc_type']) ?></td>
                        <td>
                            <?php if (!empty($d['id'])): ?>
                                <a href="<?= e(url('/admin/documentos/' . $d['id'] . '/ver')) ?>" target="_blank" rel="noopener"><?= e($d['original_name']) ?></a>
                            <?php else: ?>
                                <?= e($d['original_name']) ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="pill"><?= e($d['status']) ?></span></td>
                        <td><?= e($d['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if (!empty($purchase['payment_proof_path'])): ?>
        <p style="margin-top:.75rem">Comprobante de pago: <code><?= e($purchase['payment_proof_path']) ?></code></p>
    <?php endif; ?>
    <?php if (!empty($purchase['openpay_clabe'])): ?>
        <p>CLABE OpenPay: <code><?= e($purchase['openpay_clabe']) ?></code></p>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Seguimientos</h2>
    <?php if ($trackings === []): ?>
        <p class="muted">Sin trackings.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($trackings as $t): ?>
                <li>
                    <a href="<?= e(url('/admin/seguimientos/' . $t['id'])) ?>"><?= e($t['product_name']) ?></a>
                    · <?= e($t['status']) ?> · paso <?= e($t['current_step_code'] ?? '—') ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if ($canConfirm): ?>
    <form method="post" action="<?= e(url('/admin/compras/' . $purchase['id'] . '/confirmar-pago')) ?>" class="panel" style="margin-top:1rem">
        <?= csrf_field() ?>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Confirmar pago</h2>
        <label class="muted" style="display:block;margin-bottom:.5rem">Nota (opcional)
            <input type="text" name="notes" style="width:100%;max-width:420px;padding:.5rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <button class="btn btn-accent" type="submit">Marcar como pagado</button>
    </form>
<?php elseif ($purchase['status'] === 'paid'): ?>
    <p class="flash flash-success" style="margin-top:1rem">Pago confirmado<?= !empty($purchase['paid_at']) ? ' el ' . e($purchase['paid_at']) : '' ?>.</p>
<?php endif; ?>
