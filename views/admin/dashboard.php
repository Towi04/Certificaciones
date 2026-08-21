<?php /** @var list<array<string,mixed>> $paymentQueue */ ?>
<h1 style="margin-top:0;color:var(--doceo-blue)">Dashboard</h1>
<div class="stats" style="margin-bottom:1.25rem">
    <div class="stat"><div class="label">Productos activos</div><div class="value"><?= (int) $stats['products'] ?></div></div>
    <div class="stat"><div class="label">Compras pagadas</div><div class="value"><?= (int) $stats['paid'] ?></div></div>
    <a class="stat" href="<?= e(url('/admin/pagos')) ?>" style="text-decoration:none;color:inherit">
        <div class="label">Por confirmar pago</div>
        <div class="value"><?= (int) $stats['awaiting_payment'] ?></div>
    </a>
    <div class="stat"><div class="label">Pendientes de ti</div><div class="value"><?= (int) $stats['waiting_admin'] ?></div></div>
</div>

<section class="panel" style="margin-bottom:1rem;border:2px solid var(--doceo-yellow)">
    <h2 style="margin-top:0">Pagos por confirmar</h2>
    <?php if (empty($paymentQueue)): ?>
        <p class="muted" style="margin:0">No hay pagos pendientes. <a href="<?= e(url('/admin/pagos')) ?>">Ver página de pagos</a></p>
    <?php else: ?>
        <p class="muted" style="margin-top:0">Haz clic en el botón amarillo para abrir la compra y marcarla como pagada.</p>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Matrícula</th><th>Alumno</th><th>Monto</th><th>Estatus</th><th>Acción</th></tr></thead>
                <tbody>
                <?php foreach ($paymentQueue as $row): ?>
                    <tr>
                        <td><?= e($row['matricula']) ?></td>
                        <td><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''))) ?></td>
                        <td><?= money($row['charged_amount']) ?></td>
                        <td><span class="pill"><?= e($row['status']) ?></span></td>
                        <td>
                            <a class="btn btn-accent" href="<?= e(url('/admin/compras/' . $row['id'])) ?>">Confirmar pago</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <section class="panel">
        <h2>Cola — esperando admin</h2>
        <?php if ($queue === []): ?>
            <p class="muted">No hay seguimientos pendientes de acción admin.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Matrícula</th><th>Alumno</th><th>Producto</th><th>Paso</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($queue as $row): ?>
                        <?php $needsPay = in_array((string) ($row['purchase_status'] ?? ''), ['awaiting_payment', 'payment_review'], true); ?>
                        <tr>
                            <td><a href="<?= e(url('/admin/seguimientos/' . $row['id'])) ?>"><?= e($row['matricula']) ?></a></td>
                            <td><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''))) ?></td>
                            <td><?= e($row['product_name']) ?></td>
                            <td><span class="pill"><?= e($row['current_step_code'] ?? '—') ?></span></td>
                            <td style="white-space:nowrap">
                                <?php if ($needsPay): ?>
                                    <a class="btn btn-accent btn-sm" href="<?= e(url('/admin/compras/' . (int) $row['purchase_id'])) ?>">Confirmar pago</a>
                                <?php else: ?>
                                    <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/seguimientos/' . $row['id'])) ?>">Abrir caso</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Exámenes próximos (14 días)</h2>
        <?php if ($upcoming === []): ?>
            <p class="muted">Sin aplicaciones programadas en la ventana.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Fecha</th><th>Alumno</th><th>Producto</th></tr></thead>
                    <tbody>
                    <?php foreach ($upcoming as $row): ?>
                        <tr>
                            <td><?= e($row['exam_date']) ?> <?= e(substr((string) ($row['exam_time'] ?? ''), 0, 5)) ?></td>
                            <td><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''))) ?></td>
                            <td><a href="<?= e(url('/admin/seguimientos/' . $row['id'])) ?>"><?= e($row['product_name']) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
