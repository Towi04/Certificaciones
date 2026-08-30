<?php
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $suppliers */
/** @var int|null $filterSupplierId */
$filterSupplierId = $filterSupplierId ?? null;
$priceFields = [
    'cost_price' => 'Costo',
    'catalog_price' => 'Lista',
    'public_price' => 'Público',
    'price_cncm' => 'CNCM',
    'price_partner_a' => 'Partner A',
    'price_partner_b' => 'Partner B',
    'price_partner_c' => 'Partner C',
];
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Precios masivos</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:48rem">
            Edita en una sola tabla costo, lista, público y niveles partner.
            También puedes descargar una plantilla CSV, actualizarla y volver a subirla.
            La edición por producto individual sigue disponible.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= e(url('/admin/precios/plantilla.csv' . ($filterSupplierId ? ('?supplier_id=' . $filterSupplierId) : ''))) ?>">Descargar plantilla CSV</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/productos')) ?>">Ver productos</a>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/precios')) ?>" class="panel" style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:end">
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
        Filtrar por proveedor
        <select name="supplier_id" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;min-width:220px">
            <option value="">Todos</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) $filterSupplierId === (int) $s['id'] ? 'selected' : '' ?>>
                    <?= e($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>

<form method="post" action="<?= e(url('/admin/precios/import')) ?>" enctype="multipart/form-data" class="panel" style="margin-top:.75rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:end">
    <?= csrf_field() ?>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
        Importar CSV de precios
        <input type="file" name="csv" accept=".csv,text/csv" required>
    </label>
    <button class="btn btn-accent" type="submit">Subir e importar</button>
    <span class="muted" style="font-size:.78rem;max-width:28rem">
        Columnas:
        <code>code,name,cost_price,catalog_price,public_price,price_cncm,price_partner_a,price_partner_b,price_partner_c</code>
    </span>
</form>

<form method="post" action="<?= e(url('/admin/precios')) ?>" class="panel" style="margin-top:1rem">
    <?= csrf_field() ?>
    <?php if ($filterSupplierId): ?>
        <input type="hidden" name="supplier_id" value="<?= (int) $filterSupplierId ?>">
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th>
                <th>Producto</th>
                <th>Proveedor</th>
                <?php foreach ($priceFields as $label): ?>
                    <th><?= e($label) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <?php $pid = (int) $p['id']; ?>
                <tr>
                    <td><code><?= e((string) $p['code']) ?></code></td>
                    <td>
                        <a href="<?= e(url('/admin/productos/' . $pid)) ?>"><?= e((string) $p['name']) ?></a>
                    </td>
                    <td><?= e((string) ($p['supplier_name'] ?? '—')) ?></td>
                    <?php foreach ($priceFields as $field => $_label):
                        $val = $p[$field] ?? '';
                        ?>
                        <td>
                            <input type="number" min="0" step="0.01"
                                   name="prices[<?= $pid ?>][<?= e($field) ?>]"
                                   value="<?= e($val !== null && $val !== '' ? (string) $val : '') ?>"
                                   style="width:6.2rem;padding:.35rem .45rem;border:1px solid #cfd8e6;border-radius:8px">
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr><td colspan="10" class="muted">No hay productos para mostrar.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($products !== []): ?>
        <div style="margin-top:1rem">
            <button class="btn btn-accent" type="submit">Guardar precios</button>
        </div>
    <?php endif; ?>
</form>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>
