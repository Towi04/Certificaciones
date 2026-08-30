<?php
/** @var list<array<string,mixed>> $suppliers */
/** @var array<int,array{products:int,groups:int}> $counts */
$counts = $counts ?? [];
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Proveedores</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Crea y edita proveedores. En cada ficha puedes guardar contactos (ventas, soporte…),
            logo, links de plataformas y usuarios/contraseñas cifrados, además de cargar
            certificaciones en lote (CSV).
        </p>
    </div>
    <a class="btn btn-accent" href="<?= e(url('/admin/proveedores/nuevo')) ?>">Nuevo proveedor</a>
</div>

<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Sitio</th>
                <th>Grupos</th>
                <th>Productos</th>
                <th>Activo</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
                <?php $sid = (int) $s['id']; ?>
                <tr>
                    <td><code><?= e($s['code']) ?></code></td>
                    <td><?= e($s['name']) ?></td>
                    <td>
                        <?php if (!empty($s['website'])): ?>
                            <a href="<?= e((string) $s['website']) ?>" target="_blank" rel="noopener"><?= e((string) $s['website']) ?></a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($counts[$sid]['groups'] ?? 0) ?></td>
                    <td><?= (int) ($counts[$sid]['products'] ?? 0) ?></td>
                    <td><?= (int) $s['is_active'] ? 'Sí' : 'No' ?></td>
                    <td><a href="<?= e(url('/admin/proveedores/' . $sid)) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($suppliers === []): ?>
                <tr><td colspan="7" class="muted">Sin proveedores. Crea uno o ejecuta el seed de catálogo.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
