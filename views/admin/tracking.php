<?php
/** @var array<string,mixed> $tracking */
/** @var list<array<string,mixed>> $steps */
/** @var list<array<string,mixed>> $logs */
/** @var list<array<string,mixed>> $documents */
$current = (string) ($tracking['current_step_code'] ?? '');
$statusLabels = [
    'open' => 'Abierto',
    'waiting_admin' => 'Espera admin',
    'waiting_student' => 'Espera alumno',
    'waiting_partner' => 'Espera partner',
    'waiting_provider' => 'Espera proveedor',
    'completed' => 'Completado',
    'cancelled' => 'Cancelado',
];
?>
<p class="meta">
    <a href="<?= e(url('/admin')) ?>">← Dashboard</a>
    · <a href="<?= e(url('/admin/compras/' . $tracking['purchase_id'])) ?>">Compra <?= e($tracking['matricula']) ?></a>
</p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($tracking['product_name']) ?></h1>
<p>
    Matrícula <strong><?= e($tracking['matricula']) ?></strong>
    · <span class="pill"><?= e($statusLabels[$tracking['status']] ?? $tracking['status']) ?></span>
    · pago compra: <span class="pill"><?= e($tracking['purchase_status']) ?></span>
</p>
<p class="muted">
    <?= e(trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''))) ?>
    · <?= e($tracking['student_email']) ?>
    <?php if (!empty($tracking['student_phone'])): ?> · <?= e($tracking['student_phone']) ?><?php endif; ?>
</p>

<?php if (in_array((string) $tracking['purchase_status'], ['awaiting_payment', 'payment_review'], true)): ?>
    <div class="panel" style="margin-top:1rem;border-color:#F5DF25">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Pago pendiente de confirmación</h2>
        <p class="muted" style="margin-top:0">El alumno ya registró la compra. Confirma el pago para avanzar el caso.</p>
        <a class="btn btn-accent" href="<?= e(url('/admin/compras/' . $tracking['purchase_id'])) ?>">Ir a confirmar pago</a>
    </div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Pipeline<?= !empty($tracking['pipeline_name']) ? ': ' . e($tracking['pipeline_name']) : '' ?></h2>
    <?php if ($steps === []): ?>
        <p class="muted">Sin pipeline asignado. Re-ejecuta el seed de catálogo si hace falta.</p>
    <?php else: ?>
        <ol class="pipeline-list" style="margin:0;padding-left:1.2rem">
            <?php foreach ($steps as $s): ?>
                <?php $active = (string) $s['code'] === $current; ?>
                <li style="<?= $active ? 'font-weight:700;color:var(--doceo-blue)' : '' ?>">
                    <?= e($s['label']) ?>
                    <span class="muted" style="font-weight:500">(<?= e($s['code']) ?> · <?= e($s['actor']) ?>)</span>
                    <?php if ($active): ?> ← actual<?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/avanzar')) ?>" style="margin-top:1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem">Ir a paso
            <select name="step_code" style="padding:.45rem .6rem;border:1px solid #cfd8e6;border-radius:10px">
                <option value="">— siguiente automático —</option>
                <?php foreach ($steps as $s): ?>
                    <option value="<?= e($s['code']) ?>" <?= (string) $s['code'] === $current ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem;flex:1;min-width:180px">Nota
            <input type="text" name="note" style="padding:.45rem .6rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <button class="btn btn-accent" type="submit">Actualizar paso</button>
    </form>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Documentos</h2>
    <?php if ($documents === []): ?>
        <p class="muted">Sin documentos en este caso (normal si el producto no los pide en checkout).</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Tipo</th><th>Archivo</th><th>Estatus</th><th>Motivo</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td><?= e($d['doc_type']) ?></td>
                        <td>
                            <a href="<?= e(url('/admin/documentos/' . $d['id'] . '/ver')) ?>" target="_blank" rel="noopener">
                                <?= e($d['original_name']) ?>
                            </a>
                        </td>
                        <td><span class="pill"><?= e($d['status']) ?></span></td>
                        <td class="muted"><?= e($d['rejection_reason'] ?? '') ?></td>
                        <td style="white-space:nowrap">
                            <?php if ($d['status'] === 'pending' || $d['status'] === 'rejected'): ?>
                                <form method="post" action="<?= e(url('/admin/documentos/' . $d['id'] . '/aprobar')) ?>" style="display:inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-primary btn-sm" type="submit">Aprobar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($d['status'] !== 'rejected'): ?>
                                <form method="post" action="<?= e(url('/admin/documentos/' . $d['id'] . '/rechazar')) ?>" style="display:inline-flex;gap:.35rem;align-items:center;margin-top:.35rem">
                                    <?= csrf_field() ?>
                                    <input type="text" name="reason" required placeholder="Motivo rechazo" style="padding:.3rem .5rem;border:1px solid #cfd8e6;border-radius:8px;max-width:160px">
                                    <button class="btn btn-ghost btn-sm" type="submit">Rechazar</button>
                                </form>
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
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Historial</h2>
    <?php if ($logs === []): ?>
        <p class="muted">Sin eventos.</p>
    <?php else: ?>
        <ul style="margin:0;padding-left:1.1rem">
            <?php foreach ($logs as $l): ?>
                <li>
                    <code><?= e($l['step_code']) ?></code>
                    <?php if (!empty($l['note'])): ?> — <?= e($l['note']) ?><?php endif; ?>
                    <span class="muted" style="font-size:.82rem">
                        · <?= e($l['created_at']) ?>
                        <?php if (!empty($l['first_name'])): ?>
                            · <?= e(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name_p'] ?? ''))) ?>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
