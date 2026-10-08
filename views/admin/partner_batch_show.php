<?php
/** @var array<string,mixed> $batch */
/** @var list<array<string,mixed>> $purchases */
$canConfirm = ($batch['status'] ?? '') === 'payment_review';
?>
<p class="meta"><a href="<?= e(url('/admin/partners/lotes')) ?>">← Lotes</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">Lote #<?= (int) $batch['id'] ?></h1>
<p>
    Partner <code><?= e((string) ($batch['partner_code'] ?? '')) ?></code>
    · <?= e((string) ($batch['partner_name'] ?? '')) ?><br>
    Producto <strong><?= e((string) ($batch['product_name'] ?? '')) ?></strong>
    · examen <?= e((string) ($batch['exam_date'] ?? '—')) ?>
    <?php if (!empty($batch['exam_time'])): ?>
        <?= e(substr((string) $batch['exam_time'], 0, 5)) ?>
    <?php endif; ?>
    · <span class="pill"><?= e((string) ($batch['status'] ?? '')) ?></span>
</p>
<div class="stats" style="margin:1rem 0">
    <div class="stat">
        <div class="label">Alumnos</div>
        <div class="value" style="font-size:1.2rem"><?= (int) ($batch['student_count'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label">Precio unitario</div>
        <div class="value" style="font-size:1.2rem"><?= money($batch['unit_price'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label">Monto esperado</div>
        <div class="value" style="font-size:1.2rem"><?= money($batch['expected_amount'] ?? 0) ?></div>
    </div>
</div>

<?php if (!empty($batch['proof_path'])): ?>
    <p>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/partners/lotes/' . (int) $batch['id'] . '/comprobante')) ?>" target="_blank" rel="noopener">
            Ver comprobante del lote
        </a>
    </p>
<?php endif; ?>

<?php if ($canConfirm): ?>
<form method="post" action="<?= e(url('/admin/partners/lotes/' . (int) $batch['id'] . '/confirmar-pago')) ?>"
      class="panel" style="margin-top:1rem;max-width:560px">
    <?= csrf_field() ?>
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Confirmar pago del lote</h2>
    <p class="muted" style="margin-top:0;font-size:.85rem">
        Confirma el pago de todas las compras del lote (misma lógica que confirmar una compra individual).
    </p>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
        Notas (opcional)
        <input type="text" name="notes" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
    </label>
    <button class="btn btn-accent" type="submit" style="margin-top:.75rem"
            onclick="return confirm('¿Confirmar el pago de las <?= count($purchases) ?> compra(s) del lote?');">
        Marcar lote como pagado
    </button>
</form>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Compras del lote</h2>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Matrícula</th>
                <th>Alumno</th>
                <th>Monto</th>
                <th>Pago</th>
                <th>Caso</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($purchases as $pu): ?>
                <tr>
                    <td><?= e((string) ($pu['matricula'] ?? '')) ?></td>
                    <td>
                        <?= e(trim(($pu['first_name'] ?? '') . ' ' . ($pu['last_name_p'] ?? ''))) ?>
                        <br><span class="muted"><?= e((string) ($pu['email'] ?? '')) ?></span>
                    </td>
                    <td><?= money($pu['charged_amount'] ?? 0) ?></td>
                    <td><span class="pill"><?= e((string) ($pu['status'] ?? '')) ?></span></td>
                    <td>
                        <?php if (!empty($pu['tracking_id'])): ?>
                            <span class="pill"><?= e((string) ($pu['tracking_status'] ?? '')) ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= e(url('/admin/compras/' . (int) $pu['id'])) ?>">Compra</a>
                        <?php if (!empty($pu['tracking_id'])): ?>
                            · <a href="<?= e(url('/admin/seguimientos/' . (int) $pu['tracking_id'])) ?>">Caso</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($purchases === []): ?>
                <tr><td colspan="6" class="muted">Sin compras.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
