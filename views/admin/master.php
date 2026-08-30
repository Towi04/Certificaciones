<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Tabla maestra</h1>
        <p class="muted" style="margin:.35rem 0 0">Revisa comprobantes y confirma pagos desde el detalle de cada matrícula.</p>
    </div>
    <form method="get" class="search" style="max-width:420px">
        <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Nombre, correo, matrícula…">
        <select name="status">
            <option value="">Todos los estatus</option>
            <?php foreach (['draft','awaiting_docs','awaiting_payment','payment_review','paid','cancelled'] as $st): ?>
                <option value="<?= e($st) ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= e($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin:0 0 .75rem;font-size:1.05rem;color:var(--doceo-blue)">Descargar Excel</h2>
    <form method="get" action="<?= e(url('/admin/maestra/exportar')) ?>" class="export-form">
        <?php if (!empty($filters['q'])): ?>
            <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
        <?php endif; ?>
        <?php if (!empty($filters['status'])): ?>
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <?php endif; ?>
        <label>
            Desde
            <input type="date" name="date_from" value="<?= e($_GET['date_from'] ?? '') ?>">
        </label>
        <label>
            Hasta
            <input type="date" name="date_to" value="<?= e($_GET['date_to'] ?? '') ?>">
        </label>
        <button class="btn btn-ghost" type="submit">Descargar CSV (Excel)</button>
        <span class="muted" style="font-size:.85rem">Incluye los filtros de búsqueda activos y el rango de fechas.</span>
    </form>
</div>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>

<div class="panel" style="margin-top:.75rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Matrícula</th><th>Alumno</th><th>Email</th><th>Partner</th>
                <th>Monto</th><th>Estatus</th><th>Creado</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['matricula']) ?></td>
                    <td><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name_p'] ?? '') . ' ' . ($r['last_name_m'] ?? ''))) ?></td>
                    <td><?= e($r['student_email']) ?></td>
                    <td><?= e($r['partner_code'] ?? '—') ?></td>
                    <td><?= money($r['charged_amount']) ?></td>
                    <td><span class="pill"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['created_at']) ?></td>
                    <td><a href="<?= e(url('/admin/compras/' . $r['id'])) ?>">Ver</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="muted">Sin compras aún.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
