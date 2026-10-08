<?php
/** @var list<array<string,mixed>> $batches */
/** @var string $status */
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
    <div>
        <p class="meta" style="margin:0"><a href="<?= e(url('/admin/partners')) ?>">← Partners</a></p>
        <h1 style="margin:.2rem 0;color:var(--doceo-blue)">Lotes de registro masivo</h1>
    </div>
    <form method="get" style="display:flex;gap:.5rem;align-items:center">
        <label class="muted" style="font-size:.85rem">
            Estatus
            <select name="status" style="margin-left:.35rem;padding:.4rem .55rem;border:1px solid #cfd8e6;border-radius:8px">
                <option value="payment_review" <?= $status === 'payment_review' ? 'selected' : '' ?>>En revisión</option>
                <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Pagados</option>
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Todos</option>
            </select>
        </label>
        <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
    </form>
</div>
<p class="muted">Un lote = N compras del mismo producto/fecha con un solo comprobante (precio partner × N).</p>

<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>#</th>
                <th>Partner</th>
                <th>Producto</th>
                <th>Examen</th>
                <th>Alumnos</th>
                <th>Monto</th>
                <th>Estatus</th>
                <th>Creado</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td><?= (int) $b['id'] ?></td>
                    <td>
                        <code><?= e((string) ($b['partner_code'] ?? '')) ?></code><br>
                        <span class="muted"><?= e((string) ($b['partner_name'] ?? '')) ?></span>
                    </td>
                    <td><?= e((string) ($b['product_name'] ?? '')) ?></td>
                    <td>
                        <?= e((string) ($b['exam_date'] ?? '—')) ?>
                        <?php if (!empty($b['exam_time'])): ?>
                            <?= e(substr((string) $b['exam_time'], 0, 5)) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($b['student_count'] ?? 0) ?></td>
                    <td><?= money($b['expected_amount'] ?? 0) ?></td>
                    <td><span class="pill"><?= e((string) ($b['status'] ?? '')) ?></span></td>
                    <td style="font-size:.82rem"><?= e((string) ($b['created_at'] ?? '')) ?></td>
                    <td><a href="<?= e(url('/admin/partners/lotes/' . (int) $b['id'])) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($batches === []): ?>
                <tr><td colspan="9" class="muted">No hay lotes con ese filtro.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
