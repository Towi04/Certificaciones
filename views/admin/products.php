<?php
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $suppliers */
/** @var list<array<string,mixed>> $groups */
/** @var array{supplier_id?:int,product_group_id?:int,is_public?:string,is_star?:string} $filters */
$suppliers = $suppliers ?? [];
$groups = $groups ?? [];
$filters = $filters ?? [
    'supplier_id' => 0,
    'product_group_id' => 0,
    'is_public' => '',
    'is_star' => '',
];
$hasExtraFilters = ((int) ($filters['supplier_id'] ?? 0) > 0)
    || ((int) ($filters['product_group_id'] ?? 0) > 0)
    || (($filters['is_public'] ?? '') !== '')
    || (($filters['is_star'] ?? '') !== '')
    || trim((string) ($q ?? '')) !== '';
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;box-sizing:border-box';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Productos</h1>
        <p class="muted" style="margin:.35rem 0 0">
            Crea productos, edita código/nombre/precios y asígnales un
            <a href="<?= e(url('/admin/grupos')) ?>">grupo de producto</a>
            para heredar pagos y MSI.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos')) ?>">Grupos</a>
        <a class="btn btn-accent" href="<?= e(url('/admin/productos/nuevo')) ?>">Nuevo producto</a>
    </div>
</div>

<?php if ((int) ($groupsCount ?? 0) === 0): ?>
    <div class="flash flash-error" style="margin-top:1rem">
        No hay grupos de producto. Por eso el combo sale vacío al editar.
        Ve a <a href="<?= e(url('/admin/grupos')) ?>"><strong>Grupos</strong></a>
        y pulsa <strong>Cargar grupos sugeridos</strong>.
    </div>
<?php endif; ?>

<form method="get" action="<?= e(url('/admin/productos')) ?>" class="panel"
      style="margin-top:1rem;display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));align-items:end">
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.85rem;font-weight:600">
        Buscar
        <input type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="Código, nombre…"
               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
    </label>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.85rem;font-weight:600">
        Proveedor
        <select name="supplier_id" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            <option value="">— Todos —</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) ($filters['supplier_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                    <?= e((string) $s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.85rem;font-weight:600">
        Grupo
        <select name="product_group_id" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            <option value="">— Todos —</option>
            <?php foreach ($groups as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= (int) ($filters['product_group_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
                    <?= e((string) $g['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.85rem;font-weight:600">
        Publicados
        <select name="is_public" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            <option value="" <?= ($filters['is_public'] ?? '') === '' ? 'selected' : '' ?>>— Todos —</option>
            <option value="1" <?= (string) ($filters['is_public'] ?? '') === '1' ? 'selected' : '' ?>>Sí (en catálogo)</option>
            <option value="0" <?= (string) ($filters['is_public'] ?? '') === '0' ? 'selected' : '' ?>>No</option>
        </select>
    </label>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.85rem;font-weight:600">
        Estrella
        <select name="is_star" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            <option value="" <?= ($filters['is_star'] ?? '') === '' ? 'selected' : '' ?>>— Todos —</option>
            <option value="1" <?= (string) ($filters['is_star'] ?? '') === '1' ? 'selected' : '' ?>>Solo estrellas</option>
            <option value="0" <?= (string) ($filters['is_star'] ?? '') === '0' ? 'selected' : '' ?>>Sin estrella</option>
        </select>
    </label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Filtrar</button>
        <?php if ($hasExtraFilters): ?>
            <a class="btn btn-ghost" href="<?= e(url('/admin/productos')) ?>">Limpiar</a>
        <?php endif; ?>
    </div>
</form>

<div class="panel" style="margin-top:1rem;max-width:920px">
    <h2 style="margin:0 0 .35rem;font-size:1.05rem;color:var(--doceo-blue)">Cargar certificaciones (CSV)</h2>
    <p class="muted" style="margin:0 0 .75rem;font-size:.85rem">
        Sube varias certificaciones de un proveedor. También puedes hacerlo desde la ficha del proveedor.
        Los códigos de grupo del CSV deben existir en
        <a href="<?= e(url('/admin/grupos')) ?>">Grupos</a>.
    </p>
    <p style="margin:0 0 1rem">
        <a class="btn btn-ghost" href="<?= e(url('/admin/productos/plantilla-certificaciones.csv')) ?>">Descargar plantilla CSV</a>
    </p>
    <form method="post" action="<?= e(url('/admin/productos/importar-csv')) ?>" enctype="multipart/form-data"
          style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));align-items:end">
        <?= csrf_field() ?>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Proveedor
            <select name="supplier_id" required style="<?= e($inputStyle) ?>">
                <option value="">— Elige proveedor —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e((string) $s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Grupo por defecto (opcional)
            <select name="product_group_id" style="<?= e($inputStyle) ?>">
                <option value="">— Usar product_group_code del CSV —</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>"><?= e((string) $g['name']) ?> (<?= e((string) $g['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Archivo CSV
            <input type="file" name="csv" accept=".csv,text/csv" required style="<?= e($inputStyle) ?>">
        </label>
        <div>
            <button class="btn btn-accent" type="submit">Crear certificaciones</button>
        </div>
    </form>
</div>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>

<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th><th>Nombre</th><th>Proveedor</th><th>Grupo</th><th>Tipo</th>
                <th>Catálogo</th><th>Precio</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><code><?= e($p['code']) ?></code></td>
                    <td><?= e($p['name']) ?><?= !empty($p['is_star']) ? ' ⭐' : '' ?></td>
                    <td><?= e($p['supplier_name'] ?? '—') ?></td>
                    <td><?= e($p['product_group_name'] ?? $p['product_group_code'] ?? '—') ?></td>
                    <td><?= e($p['type']) ?></td>
                    <td><?= !empty($p['is_public']) ? 'Sí' : 'No' ?></td>
                    <td><?= money($p['public_price']) ?></td>
                    <td>
                        <span class="row-actions">
                            <a class="icon-btn" href="<?= e(url('/admin/productos/' . $p['id'])) ?>" title="Editar" aria-label="Editar"><?= icon('edit') ?></a>
                            <form class="icon-btn-form" method="post" action="<?= e(url('/admin/productos/' . $p['id'] . '/eliminar')) ?>"
                                  onsubmit="return confirm('¿Eliminar este producto? Solo si no tiene compras.');">
                                <?= csrf_field() ?>
                                <button class="icon-btn" type="submit" title="Eliminar" aria-label="Eliminar"><?= icon('trash') ?></button>
                            </form>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr><td colspan="8" class="muted">Sin productos con esos filtros.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
