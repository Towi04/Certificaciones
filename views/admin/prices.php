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
$sellFields = [
    'catalog_price',
    'public_price',
    'price_cncm',
    'price_partner_a',
    'price_partner_b',
    'price_partner_c',
];
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
        <p class="muted" style="margin:.45rem 0 0;font-size:.82rem;max-width:48rem">
            Semáforo de margen (venta vs costo):
            <span style="display:inline-flex;align-items:center;gap:.3rem;margin-left:.25rem">
                <span style="width:.65rem;height:.65rem;border-radius:999px;background:#16a34a;display:inline-block"></span> &gt; 30%
            </span>
            <span style="display:inline-flex;align-items:center;gap:.3rem;margin-left:.55rem">
                <span style="width:.65rem;height:.65rem;border-radius:999px;background:#eab308;display:inline-block"></span> &gt; 0% y ≤ 30%
            </span>
            <span style="display:inline-flex;align-items:center;gap:.3rem;margin-left:.55rem">
                <span style="width:.65rem;height:.65rem;border-radius:999px;background:#dc2626;display:inline-block"></span> ≤ 0%
            </span>
            · debajo de cada precio de venta se muestra la ganancia en pesos.
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
        <code>type,code,name,supplier,certifier,cost_price,catalog_price,public_price,price_cncm,<?= e($partnerCsvHeaders) ?></code>
        (<code>type</code>: <code>product</code> o <code>combo</code>; el costo no aplica a combos).
        <code>supplier</code> y <code>certifier</code> son solo lectura (identificación); al importar se ignoran.
        Edita los montos y vuelve a subir: si la celda tiene valor, se sobrescribe.
        Preferible abrir/guardar como CSV UTF-8; la plantilla incluye <code>sep=,</code> para Excel.
    </span>
</form>

<form method="post" action="<?= e(url('/admin/precios')) ?>" class="panel" style="margin-top:1rem" id="prices-bulk-form">
    <?= csrf_field() ?>
    <?php if ($filterSupplierId): ?>
        <input type="hidden" name="supplier_id" value="<?= (int) $filterSupplierId ?>">
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data" id="prices-bulk-table">
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
                <tr class="price-row" data-has-cost="<?= $isCombo ? '0' : '1' ?>">
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
                        $isSell = in_array($field, $sellFields, true);
                        ?>
                        <td>
                            <?php if ($isCombo && $field === 'cost_price'): ?>
                                <span class="muted" title="Los combos no tienen costo">—</span>
                            <?php else: ?>
                                <div style="display:flex;flex-direction:column;gap:.25rem;align-items:flex-start">
                                    <div style="display:flex;align-items:center;gap:.35rem">
                                        <?php if ($isSell): ?>
                                            <span class="margin-dot"
                                                  data-sell-field="<?= e($field) ?>"
                                                  title="Margen vs costo"
                                                  style="width:.7rem;height:.7rem;border-radius:999px;background:#cbd5e1;flex-shrink:0;display:inline-block"></span>
                                        <?php endif; ?>
                                        <input type="number" min="0" step="0.01"
                                               class="price-input<?= $field === 'cost_price' ? ' price-cost' : ($isSell ? ' price-sell' : '') ?>"
                                               data-field="<?= e($field) ?>"
                                               name="<?= e($inputPrefix) ?>[<?= e($field) ?>]"
                                               value="<?= e($val !== null && $val !== '' ? (string) $val : '') ?>"
                                               style="width:6.2rem;padding:.35rem .45rem;border:1px solid #cfd8e6;border-radius:8px">
                                    </div>
                                    <?php if ($isSell): ?>
                                        <span class="margin-label muted"
                                              data-sell-field="<?= e($field) ?>"
                                              style="font-size:.72rem;line-height:1.2;min-height:1.1rem"></span>
                                    <?php endif; ?>
                                </div>
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

<script>
(function () {
  const GREEN = '#16a34a';
  const YELLOW = '#eab308';
  const RED = '#dc2626';
  const GRAY = '#cbd5e1';

  function money(n) {
    const sign = n < 0 ? '-' : '';
    return sign + '$' + Math.abs(n).toLocaleString('es-MX', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function parseAmount(input) {
    if (!input) return null;
    const raw = String(input.value || '').trim();
    if (raw === '') return null;
    const n = Number(raw);
    return Number.isFinite(n) ? n : null;
  }

  function marginTone(pct) {
    if (pct === null) return { color: GRAY, title: 'Sin datos de margen' };
    if (pct > 30) return { color: GREEN, title: 'Margen > 30%' };
    if (pct > 0) return { color: YELLOW, title: 'Margen ≤ 30%' };
    return { color: RED, title: 'Sin ganancia o pérdida' };
  }

  function refreshRow(row) {
    const hasCost = row.getAttribute('data-has-cost') === '1';
    const costInput = row.querySelector('.price-cost');
    const cost = hasCost ? parseAmount(costInput) : null;
    const sellInputs = row.querySelectorAll('.price-sell');
    sellInputs.forEach(function (input) {
      const field = input.getAttribute('data-field');
      const dot = row.querySelector('.margin-dot[data-sell-field="' + field + '"]');
      const label = row.querySelector('.margin-label[data-sell-field="' + field + '"]');
      const sell = parseAmount(input);
      if (!hasCost) {
        if (dot) {
          dot.style.background = GRAY;
          dot.title = 'Los combos no tienen costo';
        }
        if (label) label.textContent = 'Sin costo';
        return;
      }
      if (cost === null || sell === null) {
        if (dot) {
          dot.style.background = GRAY;
          dot.title = 'Completa costo y precio';
        }
        if (label) label.textContent = '—';
        return;
      }
      const profit = Math.round((sell - cost) * 100) / 100;
      const pct = cost > 0 ? Math.round(((sell - cost) / cost) * 1000) / 10 : (sell > 0 ? 100 : 0);
      const tone = marginTone(pct);
      if (dot) {
        dot.style.background = tone.color;
        dot.title = tone.title + ' (' + pct + '%)';
      }
      if (label) {
        const profitText = profit >= 0 ? ('+' + money(profit)) : money(profit);
        label.textContent = profitText + ' · ' + pct + '%';
        label.style.color = tone.color;
        label.style.fontWeight = '600';
      }
    });
  }

  function refreshAll() {
    document.querySelectorAll('#prices-bulk-table tr.price-row').forEach(refreshRow);
  }

  const table = document.getElementById('prices-bulk-table');
  if (!table) return;
  table.addEventListener('input', function (ev) {
    const t = ev.target;
    if (!(t instanceof HTMLInputElement) || !t.classList.contains('price-input')) return;
    const row = t.closest('tr.price-row');
    if (row) refreshRow(row);
  });
  refreshAll();
})();
</script>
