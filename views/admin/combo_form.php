<?php
/** @var array<string,mixed>|null $combo */
/** @var list<array<string,mixed>> $products */
/** @var list<int> $selectedIds */
$isEdit = $combo !== null;
$selectedIds = $selectedIds ?? [];
$action = $isEdit ? url('/admin/combos/' . (int) $combo['id']) : url('/admin/combos/nuevo');
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$typeLabels = [
    'certification' => 'Certificación',
    'course' => 'Curso',
    'procedure' => 'Trámite',
    'shipping' => 'Envío',
    'extension' => 'Extensión',
    'other' => 'Otro',
];
$priceFields = [
    'public_price' => 'Público *',
    'catalog_price' => 'Lista',
    'price_cncm' => 'CNCM',
    'price_partner_a' => 'Partner A',
    'price_partner_b' => 'Partner B',
    'price_partner_c' => 'Partner C',
];
$num = static function (mixed $v): string {
    if ($v === null || $v === '') {
        return '0';
    }

    return (string) round((float) $v, 2);
};
?>
<p class="meta"><a href="<?= e(url('/admin/combos')) ?>">← Combos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar combo' : 'Nuevo combo' ?>
</h1>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:960px" id="combo-form">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre *
            <input type="text" name="name" required value="<?= e((string) ($combo['name'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Código *
            <input type="text" name="code" required maxlength="60"
                   <?= $isEdit ? 'readonly' : '' ?>
                   value="<?= e((string) ($combo['code'] ?? '')) ?>"
                   placeholder="ej. toefl-prep-cenni"
                   style="<?= e($inputStyle) ?><?= $isEdit ? ';background:#f4f7fb' : '' ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Slug
            <input type="text" name="slug" value="<?= e((string) ($combo['slug'] ?? '')) ?>"
                   placeholder="auto" style="<?= e($inputStyle) ?>">
        </label>
    </div>
    <label class="muted" style="<?= e($labelStyle) ?>;margin-top:1rem">
        Descripción (visible al alumno)
        <textarea name="description" rows="3" style="<?= e($inputStyle) ?>"><?= e((string) ($combo['description'] ?? '')) ?></textarea>
    </label>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin:1.25rem 0 .5rem">Productos del combo *</h2>
    <p class="muted" style="font-size:.85rem;margin-top:0">
        Elige al menos 2 (ej. certificación + curso + trámite CENNI). Al marcarlos se calcula la suma
        sugerida abajo para que definas el precio del combo con descuento.
    </p>
    <div style="max-height:360px;overflow:auto;border:1px solid #e6ebf2;border-radius:12px;padding:.5rem .75rem" id="combo-product-list">
        <?php foreach ($products as $p): ?>
            <?php $pid = (int) $p['id']; ?>
            <label style="display:flex;gap:.6rem;align-items:flex-start;padding:.35rem 0;border-bottom:1px solid #f0f3f8;font-size:.9rem">
                <input type="checkbox" name="product_ids[]" value="<?= $pid ?>"
                       class="combo-product-check"
                       data-public="<?= e($num($p['public_price'] ?? 0)) ?>"
                       data-catalog="<?= e($num(($p['catalog_price'] ?? 0) > 0 ? $p['catalog_price'] : ($p['public_price'] ?? 0))) ?>"
                       data-cncm="<?= e($num($p['price_cncm'] ?? $p['public_price'] ?? 0)) ?>"
                       data-partner-a="<?= e($num($p['price_partner_a'] ?? $p['public_price'] ?? 0)) ?>"
                       data-partner-b="<?= e($num($p['price_partner_b'] ?? $p['public_price'] ?? 0)) ?>"
                       data-partner-c="<?= e($num($p['price_partner_c'] ?? $p['public_price'] ?? 0)) ?>"
                       data-name="<?= e((string) $p['name']) ?>"
                    <?= in_array($pid, $selectedIds, true) ? 'checked' : '' ?>
                    style="margin-top:.25rem">
                <span>
                    <strong><?= e((string) $p['name']) ?></strong>
                    <span class="muted"> · <?= e($typeLabels[(string) $p['type']] ?? (string) $p['type']) ?>
                        · <code><?= e((string) $p['code']) ?></code>
                        · <?= money($p['public_price']) ?></span>
                </span>
            </label>
        <?php endforeach; ?>
        <?php if ($products === []): ?>
            <p class="muted">No hay productos activos. Crea productos primero.</p>
        <?php endif; ?>
    </div>

    <div id="combo-sum-panel" class="panel" style="margin-top:1rem;background:#f7faff;border:1px solid #d9e4f5;padding:.85rem 1rem">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">
            <div>
                <strong style="color:var(--doceo-blue)">Suma sugerida de productos</strong>
                <p class="muted" style="margin:.25rem 0 0;font-size:.82rem" id="combo-sum-hint">
                    Marca productos para ver el total suelto. Luego baja el precio del combo para aplicar descuento.
                </p>
            </div>
            <div style="text-align:right">
                <div style="font-size:1.35rem;font-weight:800;color:var(--doceo-blue)" id="combo-sum-public">$0.00</div>
                <div class="muted" style="font-size:.78rem" id="combo-sum-count">0 productos</div>
            </div>
        </div>
        <ul id="combo-sum-lines" style="margin:.75rem 0 0;padding-left:1.1rem;font-size:.86rem"></ul>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.85rem;align-items:center">
            <button type="button" class="btn btn-accent btn-sm" id="combo-apply-sum">Usar suma como precios del combo</button>
            <button type="button" class="btn btn-ghost btn-sm" id="combo-apply-public-only">Solo llenar precio público</button>
            <span class="muted" style="font-size:.8rem" id="combo-discount-preview"></span>
        </div>
    </div>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin:1.25rem 0 .5rem">Precios del combo</h2>
    <p class="muted" style="font-size:.85rem;margin-top:0">
        Pon aquí el precio final del paquete (con descuento). El alumno verá el precio suelto de cada
        producto y cuánto ahorra al elegir el combo.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">
        <?php foreach ($priceFields as $field => $label):
            $val = $combo[$field] ?? '';
            ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                <?= e($label) ?>
                <input type="number" name="<?= e($field) ?>" id="combo-price-<?= e($field) ?>" min="0" step="0.01"
                       value="<?= e($val !== null && $val !== '' ? (string) $val : '') ?>"
                       style="<?= e($inputStyle) ?>"
                       data-price-field="<?= e($field) ?>"
                    <?= $field === 'public_price' ? 'required' : '' ?>>
                <span class="muted" style="font-weight:500;font-size:.75rem" data-suggest-for="<?= e($field) ?>"></span>
            </label>
        <?php endforeach; ?>
    </div>

    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.85rem">
        <input type="checkbox" name="is_active" value="1"
            <?= $isEdit ? (!empty($combo['is_active']) ? 'checked' : '') : 'checked' ?>>
        Combo activo
    </label>
    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.35rem">
        <input type="checkbox" name="is_star" value="1" <?= !empty($combo['is_star']) ? 'checked' : '' ?>>
        Destacado
    </label>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar combo' : 'Crear combo' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/combos')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($isEdit): ?>
<form method="post" action="<?= e(url('/admin/combos/' . (int) $combo['id'] . '/eliminar')) ?>"
      onsubmit="return confirm('¿Eliminar este combo? Solo si no tiene compras.');"
      style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit" style="color:#b42318">Eliminar combo</button>
</form>
<?php endif; ?>

<script>
(function () {
  const checks = Array.prototype.slice.call(document.querySelectorAll('.combo-product-check'));
  const sumPublicEl = document.getElementById('combo-sum-public');
  const sumCountEl = document.getElementById('combo-sum-count');
  const sumLinesEl = document.getElementById('combo-sum-lines');
  const discountPreview = document.getElementById('combo-discount-preview');
  const applyAllBtn = document.getElementById('combo-apply-sum');
  const applyPublicBtn = document.getElementById('combo-apply-public-only');
  const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
  let dirty = {};
  let autoFill = !isEdit;

  const fieldMap = {
    public_price: 'public',
    catalog_price: 'catalog',
    price_cncm: 'cncm',
    price_partner_a: 'partner-a',
    price_partner_b: 'partner-b',
    price_partner_c: 'partner-c'
  };

  function money(n) {
    return '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function selected() {
    return checks.filter(function (c) { return c.checked; });
  }

  function totals() {
    const out = { public: 0, catalog: 0, cncm: 0, 'partner-a': 0, 'partner-b': 0, 'partner-c': 0, lines: [] };
    selected().forEach(function (c) {
      const pub = Number(c.getAttribute('data-public') || 0);
      out.public += pub;
      out.catalog += Number(c.getAttribute('data-catalog') || 0);
      out.cncm += Number(c.getAttribute('data-cncm') || 0);
      out['partner-a'] += Number(c.getAttribute('data-partner-a') || 0);
      out['partner-b'] += Number(c.getAttribute('data-partner-b') || 0);
      out['partner-c'] += Number(c.getAttribute('data-partner-c') || 0);
      out.lines.push({ name: c.getAttribute('data-name') || '', price: pub });
    });
    Object.keys(out).forEach(function (k) {
      if (k !== 'lines') out[k] = Math.round(out[k] * 100) / 100;
    });
    return out;
  }

  function setInput(field, value, force) {
    const input = document.getElementById('combo-price-' + field);
    if (!input) return;
    if (!force && dirty[field]) return;
    if (!force && !autoFill && input.value !== '') return;
    input.value = value > 0 ? value.toFixed(2) : '';
  }

  function updateSuggestLabels(t) {
    Object.keys(fieldMap).forEach(function (field) {
      const el = document.querySelector('[data-suggest-for="' + field + '"]');
      if (!el) return;
      const sum = t[fieldMap[field]] || 0;
      el.textContent = selected().length ? ('Suma suelta: ' + money(sum)) : '';
    });
  }

  function updateDiscountPreview(t) {
    if (!discountPreview) return;
    const input = document.getElementById('combo-price-public_price');
    const comboPrice = input ? Number(input.value || 0) : 0;
    if (!selected().length || t.public <= 0) {
      discountPreview.textContent = '';
      return;
    }
    if (comboPrice <= 0) {
      discountPreview.textContent = 'Define un precio público menor a ' + money(t.public) + ' para aplicar descuento.';
      return;
    }
    const savings = Math.round((t.public - comboPrice) * 100) / 100;
    if (savings > 0.009) {
      const pct = Math.round((savings / t.public) * 1000) / 10;
      discountPreview.textContent = 'Ahorro vs sueltos: ' + money(savings) + ' (' + pct + '%)';
      discountPreview.style.color = '#176b3a';
    } else if (savings < -0.009) {
      discountPreview.textContent = 'El combo está más caro que la suma suelta.';
      discountPreview.style.color = '#b42318';
    } else {
      discountPreview.textContent = 'Sin descuento (mismo precio que la suma).';
      discountPreview.style.color = '';
    }
  }

  function refresh(applyAuto) {
    const t = totals();
    const n = selected().length;
    if (sumPublicEl) sumPublicEl.textContent = money(t.public);
    if (sumCountEl) sumCountEl.textContent = n + (n === 1 ? ' producto' : ' productos');
    if (sumLinesEl) {
      sumLinesEl.innerHTML = t.lines.map(function (l) {
        return '<li><strong>' + l.name.replace(/</g, '&lt;') + '</strong> · ' + money(l.price) + '</li>';
      }).join('');
    }
    updateSuggestLabels(t);
    if (applyAuto && autoFill && n >= 2) {
      setInput('public_price', t.public, false);
      setInput('catalog_price', t.catalog, false);
      setInput('price_cncm', t.cncm, false);
      setInput('price_partner_a', t['partner-a'], false);
      setInput('price_partner_b', t['partner-b'], false);
      setInput('price_partner_c', t['partner-c'], false);
    }
    updateDiscountPreview(t);
  }

  function applySum(all) {
    const t = totals();
    if (selected().length < 2) {
      alert('Elige al menos 2 productos para calcular la suma.');
      return;
    }
    setInput('public_price', t.public, true);
    dirty.public_price = false;
    if (all) {
      setInput('catalog_price', t.catalog, true);
      setInput('price_cncm', t.cncm, true);
      setInput('price_partner_a', t['partner-a'], true);
      setInput('price_partner_b', t['partner-b'], true);
      setInput('price_partner_c', t['partner-c'], true);
      dirty = {};
    }
    autoFill = false;
    updateDiscountPreview(t);
  }

  checks.forEach(function (c) {
    c.addEventListener('change', function () { refresh(true); });
  });

  Object.keys(fieldMap).forEach(function (field) {
    const input = document.getElementById('combo-price-' + field);
    if (!input) return;
    input.addEventListener('input', function () {
      dirty[field] = true;
      autoFill = false;
      updateDiscountPreview(totals());
    });
  });

  applyAllBtn && applyAllBtn.addEventListener('click', function () { applySum(true); });
  applyPublicBtn && applyPublicBtn.addEventListener('click', function () { applySum(false); });

  refresh(false);
})();
</script>
