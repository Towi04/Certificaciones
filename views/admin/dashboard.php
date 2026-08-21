<h1 style="margin-top:0;color:var(--doceo-blue)">Dashboard</h1>
<div class="stats" style="margin-bottom:1.25rem">
    <div class="stat"><div class="label">Productos activos</div><div class="value"><?= (int) $stats['products'] ?></div></div>
    <div class="stat"><div class="label">Compras pagadas</div><div class="value"><?= (int) $stats['paid'] ?></div></div>
    <div class="stat"><div class="label">Por confirmar pago</div><div class="value"><?= (int) $stats['awaiting_payment'] ?></div></div>
    <div class="stat"><div class="label">Pendientes de ti</div><div class="value"><?= (int) $stats['waiting_admin'] ?></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <section class="panel">
        <h2>Cola — esperando admin</h2>
        <?php if ($queue === []): ?>
            <p class="muted">No hay seguimientos pendientes de acción admin.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Matrícula</th><th>Alumno</th><th>Producto</th><th>Paso</th></tr></thead>
                    <tbody>
                    <?php foreach ($queue as $row): ?>
                        <tr>
                            <td><a href="<?= e(url('/admin/seguimientos/' . $row['id'])) ?>"><?= e($row['matricula']) ?></a></td>
                            <td><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''))) ?></td>
                            <td><?= e($row['product_name']) ?></td>
                            <td><span class="pill"><?= e($row['current_step_code'] ?? '—') ?></span></td>
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
