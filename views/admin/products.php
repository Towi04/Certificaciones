<div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;color:var(--doceo-blue)">Productos</h1>
    <form method="get" class="search" style="max-width:360px">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar…">
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </form>
</div>
<p class="muted">Catálogo configurable. Alta/edición completa en siguientes iteraciones; por ahora listado tras <code>bin/seed-catalog.php</code>.</p>
<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th><th>Nombre</th><th>Tipo</th><th>Certificador</th>
                <th>Público</th><th>Catálogo</th><th>Estado</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= e($p['code']) ?></td>
                    <td><?= e($p['name']) ?><?= !empty($p['is_star']) ? ' ⭐' : '' ?></td>
                    <td><?= e($p['type']) ?></td>
                    <td><?= e($p['certifier_name'] ?? '—') ?></td>
                    <td><?= money($p['public_price']) ?></td>
                    <td><?= money($p['catalog_price']) ?></td>
                    <td><?= (int) $p['is_active'] ? 'Activo' : 'Off' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr><td colspan="7" class="muted">Sin productos. Ejecuta el seed.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
