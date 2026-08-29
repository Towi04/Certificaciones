<?php
/** @var array<string,mixed> $supplier */
/** @var list<array<string,mixed>> $groups */
/** @var list<array<string,mixed>> $products */
/** @var int $productCount */
/** @var int $groupCount */
$sid = (int) $supplier['id'];
?>
<p class="meta"><a href="<?= e(url('/admin/proveedores')) ?>">← Proveedores</a></p>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)"><?= e((string) $supplier['name']) ?></h1>
        <p class="muted" style="margin:.35rem 0 0">
            Código <code><?= e((string) $supplier['code']) ?></code>
            · <?= (int) $supplier['is_active'] ? 'Activo' : 'Inactivo' ?>
            · <?= (int) $groupCount ?> grupo(s) · <?= (int) $productCount ?> producto(s)
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos/nuevo?supplier_id=' . $sid)) ?>">Nuevo grupo</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/productos/nuevo')) ?>">Nuevo producto</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/precios?supplier_id=' . $sid)) ?>">Precios de este proveedor</a>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/proveedores/' . $sid)) ?>" class="panel" style="margin-top:1rem;max-width:720px">
    <?= csrf_field() ?>
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos del proveedor</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nombre *
            <input type="text" name="name" required value="<?= e((string) $supplier['name']) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Código
            <input type="text" name="code" readonly value="<?= e((string) $supplier['code']) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;background:#f4f7fb">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Sitio web
            <input type="url" name="website" value="<?= e((string) ($supplier['website'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-top:1rem">
        Notas
        <textarea name="notes" rows="3"
                  style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px"><?= e((string) ($supplier['notes'] ?? '')) ?></textarea>
    </label>
    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.85rem">
        <input type="checkbox" name="is_active" value="1" <?= !empty($supplier['is_active']) ? 'checked' : '' ?>>
        Proveedor activo
    </label>
    <div style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap">
        <button class="btn btn-accent" type="submit">Guardar proveedor</button>
    </div>
</form>

<div class="panel" style="margin-top:1rem;max-width:720px">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Cargar certificaciones en lote</h2>
    <p class="muted" style="font-size:.85rem">
        Sube un CSV para crear varios productos de una vez. Después puedes editar cada uno en Productos
        (descripción, logo, galería). Descarga la plantilla para ver columnas y orden.
    </p>
    <p style="margin:.5rem 0 1rem">
        <a class="btn btn-ghost" href="<?= e(url('/admin/proveedores/' . $sid . '/plantilla-certificaciones.csv')) ?>">Descargar plantilla CSV</a>
    </p>
    <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/certificaciones')) ?>" enctype="multipart/form-data"
          style="display:grid;gap:.75rem">
        <?= csrf_field() ?>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Grupo de proceso (recomendado)
            <select name="product_group_id" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <option value="">— Usar product_group_code del CSV o ninguno —</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>">
                        <?= e($g['name']) ?> (<?= e($g['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Archivo CSV
            <input type="file" name="csv" accept=".csv,text/csv" required>
        </label>
        <button class="btn btn-accent" type="submit">Crear certificaciones</button>
    </form>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Grupos de este proveedor</h2>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Código</th><th>Nombre</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td><code><?= e($g['code']) ?></code></td>
                    <td><?= e($g['name']) ?></td>
                    <td><a href="<?= e(url('/admin/grupos/' . $g['id'])) ?>">Editar proceso / horarios</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($groups === []): ?>
                <tr><td colspan="3" class="muted">Aún no hay grupos. Crea uno para definir horarios y reglamento.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Productos recientes</h2>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Código</th><th>Nombre</th><th>Público</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><code><?= e($p['code']) ?></code></td>
                    <td><?= e($p['name']) ?></td>
                    <td>$<?= e(number_format((float) ($p['public_price'] ?? 0), 2)) ?></td>
                    <td><a href="<?= e(url('/admin/productos/' . $p['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr><td colspan="4" class="muted">Sin productos asociados todavía.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/eliminar')) ?>"
      onsubmit="return confirm('¿Eliminar este proveedor? Solo funciona si no tiene productos ni grupos.');"
      style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit" style="color:#b42318">Eliminar proveedor</button>
</form>
