<h1 style="margin-top:0;color:var(--doceo-blue)">Hola, <?= e(\App\Auth\Auth::user()['first_name'] ?? 'alumno') ?></h1>
<p class="muted">Aquí verás el estatus de cada compra y las acciones pendientes (docs, pago, resultados, CENNI…).</p>
<div class="panel">
    <h2>Mis seguimientos</h2>
    <?php if ($trackings === []): ?>
        <div class="empty">Aún no tienes compras. <a href="<?= e(url('/catalogo')) ?>">Explora el catálogo</a>.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Matrícula</th><th>Producto</th><th>Estatus</th><th>Paso</th><th>Examen</th></tr></thead>
                <tbody>
                <?php foreach ($trackings as $t): ?>
                    <tr>
                        <td><?= e($t['matricula']) ?></td>
                        <td><?= e($t['product_name']) ?></td>
                        <td><span class="pill"><?= e($t['status']) ?></span></td>
                        <td><?= e($t['current_step_code'] ?? '—') ?></td>
                        <td><?= e($t['exam_date'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
