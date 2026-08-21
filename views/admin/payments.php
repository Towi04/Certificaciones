<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
    <h1 style="margin:0;color:var(--doceo-blue)">Pagos por confirmar</h1>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/maestra')) ?>">Tabla maestra</a>
</div>
<p class="muted">Aquí solo aparecen compras en <code>awaiting_payment</code> o <code>payment_review</code>.</p>

<div class="panel" style="margin-top:1rem">
    <?php if ($rows === []): ?>
        <p class="muted" style="margin:0">No hay pagos pendientes.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Matrícula</th><th>Alumno</th><th>Email</th><th>Monto</th>
                    <th>Método</th><th>Estatus</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['matricula']) ?></td>
                        <td><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name_p'] ?? ''))) ?></td>
                        <td><?= e($r['student_email'] ?? '') ?></td>
                        <td><?= money($r['charged_amount']) ?></td>
                        <td><?= e($r['payment_method']) ?></td>
                        <td><span class="pill"><?= e($r['status']) ?></span></td>
                        <td><a class="btn btn-accent btn-sm" href="<?= e(url('/admin/compras/' . $r['id'])) ?>">Confirmar pago</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
