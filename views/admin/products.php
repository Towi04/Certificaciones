<div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;color:var(--doceo-blue)">Productos</h1>
    <form method="get" class="search" style="max-width:360px">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar…">
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
</div>
<p class="muted">Para cursos Moodle: edita el producto y captura el <strong>ID numérico del curso</strong> de campus.</p>
<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th><th>Nombre</th><th>Tipo</th><th>Plataforma</th>
                <th>Moodle ID</th><th>Público</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= e($p['code']) ?></td>
                    <td><?= e($p['name']) ?><?= !empty($p['is_star']) ? ' ⭐' : '' ?></td>
                    <td><?= e($p['type']) ?></td>
                    <td><?= e($p['platform_type'] ?? 'none') ?></td>
                    <td><?= !empty($p['moodle_course_id']) ? (int) $p['moodle_course_id'] : '—' ?></td>
                    <td><?= money($p['public_price']) ?></td>
                    <td><a href="<?= e(url('/admin/productos/' . $p['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr><td colspan="7" class="muted">Sin productos. Ejecuta el seed.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
