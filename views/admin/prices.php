<?php
/** @var list<array<string,mixed>> $items */
/** @var list<array<string,mixed>> $suppliers */
/** @var int|null $filterSupplierId */
$items = $items ?? [];
$filterSupplierId = $filterSupplierId ?? null;
$priceFields = [
    'cost_price' => 'Costo',
    'catalog_price' => 'Lista',
    'public_price' => 'Público',
    'price_cncm' => 'CNCM',
] + \App\Services\PartnerAdminService::priceFieldLabels();
$partnerCsvHeaders = implode(',', \App\Services\PartnerAdminService::priceCsvHeaders());
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Precios masivos</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:48rem">
            Edita en una sola tabla costo, lista, público y niveles partner de productos y combos.
            También puedes descargar una plantilla CSV, actualizarla y volver a subirla.
            La edición individual sigue disponible.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= e(url('/admin/precios/plantilla.csv' . ($filterSupplierId ? ('?supplier_id=' . $filterSupplierId) : ''))) ?>">Descargar plantilla CSV</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/productos')) ?>">Ver productos</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/combos')) ?>">Ver combos</a>
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
    <?php if ($filterSupplierId): ?>
        <span class="muted" style="font-size:.8rem;max-width:22rem">
            Con filtro de proveedor solo se muestran productos. Los combos aparecen al elegir «Todos».
        </span>
    <?php endif; ?>
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
        <code>type,code,name,cost_price,catalog_price,public_price,price_cncm,<?= e($partnerCsvHeaders) ?></code>
        (<code>type</code>: <code>product</code> o <code>combo</code>; el costo no aplica a combos).
        Edita los montos y vuelve a subir: si la celda tiene valor, se sobrescribe.
        Preferible abrir/guardar como CSV UTF-8; la plantilla incluye <code>sep=,</code> para Excel.
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
                <th>Tipo</th>
                <th>Código</th>
                <th>Nombre</th>
                <th>Proveedor</th>
                <?php foreach ($priceFields as $label): ?>
                    <th><?= e($label) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $row): ?>
                <?php
                $isCombo = (($row['_kind'] ?? $row['type'] ?? '') === 'combo');
                $rid = (int) $row['id'];
                $inputPrefix = $isCombo ? "combo_prices[{$rid}]" : "prices[{$rid}]";
                $editUrl = $isCombo ? url('/admin/combos/' . $rid) : url('/admin/productos/' . $rid);
                ?>
                <tr>
                    <td>
                        <?php if ($isCombo): ?>
                            <span class="muted" style="font-size:.78rem;font-weight:700;letter-spacing:.02em">COMBO</span>
                        <?php else: ?>
                            <span class="muted" style="font-size:.78rem;font-weight:700;letter-spacing:.02em">PRODUCTO</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= e((string) $row['code']) ?></code></td>
                    <td>
                        <a href="<?= e($editUrl) ?>"><?= e((string) $row['name']) ?></a>
                    </td>
                    <td><?= e((string) ($row['supplier_name'] ?? '—')) ?></td>
                    <?php foreach ($priceFields as $field => $_label):
                        $val = $row[$field] ?? '';
                        ?>
                        <td>
                            <?php if ($isCombo && $field === 'cost_price'): ?>
                                <span class="muted" title="Los combos no tienen costo">—</span>
                            <?php else: ?>
                                <input type="number" min="0" step="0.01"
                                       name="<?= e($inputPrefix) ?>[<?= e($field) ?>]"
                                       value="<?= e($val !== null && $val !== '' ? (string) $val : '') ?>"
                                       style="width:6.2rem;padding:.35rem .45rem;border:1px solid #cfd8e6;border-radius:8px">
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="11" class="muted">No hay productos ni combos para mostrar.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($items !== []): ?>
        <div style="margin-top:1rem">
            <button class="btn btn-accent" type="submit">Guardar precios</button>
        </div>
    <?php endif; ?>
</form>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>
