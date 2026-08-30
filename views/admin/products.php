<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Productos</h1>
        <p class="muted" style="margin:.35rem 0 0">
            Crea productos, edita código/nombre/precios y asígnales un
            <a href="<?= e(url('/admin/grupos')) ?>">grupo de proceso</a>
            para heredar pagos y MSI.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <form method="get" class="search" style="max-width:280px;margin:0">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar…">
            <button class="btn btn-primary" type="submit">Filtrar</button>
        </form>
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos')) ?>">Grupos</a>
        <a class="btn btn-accent" href="<?= e(url('/admin/productos/nuevo')) ?>">Nuevo producto</a>
    </div>
</div>

<?php if ((int) ($groupsCount ?? 0) === 0): ?>
    <div class="flash flash-error" style="margin-top:1rem">
        No hay grupos de proceso. Por eso el combo sale vacío al editar.
        Ve a <a href="<?= e(url('/admin/grupos')) ?>"><strong>Grupos</strong></a>
        y pulsa <strong>Cargar grupos sugeridos</strong>.
    </div>
<?php endif; ?>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>

<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th><th>Nombre</th><th>Grupo</th><th>Tipo</th><th>Plataforma</th>
                <th>Moodle ID</th><th>Público</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><code><?= e($p['code']) ?></code></td>
                    <td><?= e($p['name']) ?><?= !empty($p['is_star']) ? ' ⭐' : '' ?></td>
                    <td><?= e($p['product_group_name'] ?? $p['product_group_code'] ?? '—') ?></td>
                    <td><?= e($p['type']) ?></td>
                    <td><?= e($p['platform_type'] ?? 'none') ?></td>
                    <td><?= !empty($p['moodle_course_id']) ? (int) $p['moodle_course_id'] : '—' ?></td>
                    <td><?= money($p['public_price']) ?></td>
                    <td><a href="<?= e(url('/admin/productos/' . $p['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr><td colspan="8" class="muted">Sin productos. Crea uno nuevo o ejecuta el seed.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
