<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
    <h1 style="margin:0;color:var(--doceo-blue)">Tabla maestra</h1>
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
<p class="muted">Aquí vivirían filtros por certificación/proveedor/fecha y el botón “Preparar correos”.</p>
<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Matrícula</th><th>Alumno</th><th>Email</th><th>Partner</th>
                <th>Monto</th><th>Estatus</th><th>Creado</th>
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
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="muted">Sin compras aún.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
