<?php
/** @var list<array<string,mixed>> $filters */
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Filtros del catálogo</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Define las etiquetas que aparecen en el catálogo público. Luego asigna cada etiqueta
            a los productos desde <a href="<?= e(url('/admin/productos')) ?>">Productos → Editar</a>.
            Puedes crear filtros por idioma, proveedor, CENNI u otras certificaciones sin tocar código.
        </p>
    </div>
    <a class="btn btn-accent" href="<?= e(url('/admin/filtros-catalogo/nuevo')) ?>">Nuevo filtro</a>
</div>

<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Orden</th>
                <th>Nombre</th>
                <th>Slug</th>
                <th>Grupo</th>
                <th>Productos</th>
                <th>Catálogo</th>
                <th>Activo</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($filters as $f): ?>
                <tr>
                    <td><?= (int) $f['sort_order'] ?></td>
                    <td><?= e($f['label']) ?></td>
                    <td><code><?= e($f['slug']) ?></code></td>
                    <td><?= e($f['filter_group'] ?? '—') ?></td>
                    <td><?= (int) ($f['product_count'] ?? 0) ?></td>
                    <td><?= (int) $f['show_in_catalog'] ? 'Sí' : 'No' ?></td>
                    <td><?= (int) $f['is_active'] ? 'Sí' : 'No' ?></td>
                    <td>
                        <a href="<?= e(url('/admin/filtros-catalogo/' . $f['id'])) ?>">Editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($filters === []): ?>
                <tr><td colspan="8" class="muted">Sin filtros. Crea uno nuevo o ejecuta la migración de catálogo.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="muted" style="margin-top:1rem">
    <strong>Sugerencia:</strong> usa el grupo <em>Proveedor</em> para filtros como «ELT» o «Cambridge»,
    y el grupo <em>CENNI</em> para niveles como «CENNI B1».
</p>
