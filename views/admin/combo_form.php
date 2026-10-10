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
$specialPriceFields = \App\Services\PartnerAdminService::specialPriceFieldLabels();
$priceFields = [
    'public_price' => 'Público *',
    'catalog_price' => 'Lista',
] + \App\Services\PartnerAdminService::allPartnerPriceFieldLabels();
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
            <?php if (!$isEdit): ?>
                <span style="font-weight:500;font-size:.78rem">El código interno se genera a partir del nombre.</span>
            <?php endif; ?>
        </label>
        <?php if ($isEdit): ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Código interno
                <input type="text" name="code" readonly maxlength="60"
                       value="<?= e((string) ($combo['code'] ?? '')) ?>"
                       style="<?= e($inputStyle) ?>;background:#f4f7fb">
            </label>
        <?php else: ?>
            <input type="hidden" name="code" value="">
        <?php endif; ?>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Slug
            <input type="text" name="slug" value="<?= e((string) ($combo['slug'] ?? '')) ?>"
                   placeholder="auto" style="<?= e($inputStyle) ?>">
        </label>
    </div>
    <h2 id="catalogo" style="font-size:1.05rem;color:var(--doceo-blue);margin:1.25rem 0 .5rem">Contenido del catálogo</h2>
    <p class="muted" style="font-size:.85rem;margin-top:0">
        Resumen y descripción con HTML (como en productos). Si los dejas vacíos, el catálogo
        mostrará por defecto la información de las <strong>certificaciones</strong> del combo.
        La imagen propia se sube abajo tras crear el combo.
    </p>
    <div class="html-field" style="margin-bottom:.75rem" data-html-field>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.35rem">
            <span class="muted" style="font-size:.88rem;font-weight:600">Resumen (tarjeta del catálogo)</span>
            <button type="button" class="btn btn-ghost btn-sm html-preview-toggle" aria-pressed="false"
                    title="Ver texto sin código HTML">
                &lt;/&gt;
            </button>
        </div>
        <textarea name="short_description" rows="3" class="html-field-source"
                  style="<?= e($inputStyle) ?>;width:100%;display:block"
                  placeholder="Ej. Certificación + preparación con <strong>precio preferencial</strong>"><?= e((string) ($combo['short_description'] ?? '')) ?></textarea>
        <div class="html-field-preview" hidden></div>
        <p class="muted html-field-hint" style="font-size:.78rem;margin:.35rem 0 0">
            HTML sencillo (<code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, enlaces). Pulsa <code>&lt;/&gt;</code> para previsualizar.
        </p>
    </div>
    <div class="html-field" style="margin-bottom:.75rem" data-html-field>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.35rem">
            <span class="muted" style="font-size:.88rem;font-weight:600">Descripción (ficha del paquete)</span>
            <button type="button" class="btn btn-ghost btn-sm html-preview-toggle" aria-pressed="false"
                    title="Ver texto sin código HTML">
                &lt;/&gt;
            </button>
        </div>
        <textarea name="description" rows="5" class="html-field-source"
                  style="<?= e($inputStyle) ?>;width:100%;display:block"><?= e((string) ($combo['description'] ?? '')) ?></textarea>
        <div class="html-field-preview" hidden></div>
        <p class="muted html-field-hint" style="font-size:.78rem;margin:.35rem 0 0">
            Pulsa <code>&lt;/&gt;</code> para ver el texto sin etiquetas HTML.
        </p>
    </div>

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
                       <?php foreach ($specialPriceFields as $spCol => $_spLabel): ?>
                       data-<?= e(str_replace('_', '-', $spCol)) ?>="<?= e($num($p[$spCol] ?? $p['public_price'] ?? 0)) ?>"
                       <?php endforeach; ?>
                       data-partner-bronze="<?= e($num($p['price_partner_a'] ?? $p['public_price'] ?? 0)) ?>"
                       data-partner-silver="<?= e($num($p['price_partner_b'] ?? $p['public_price'] ?? 0)) ?>"
                       data-partner-gold="<?= e($num($p['price_partner_c'] ?? $p['public_price'] ?? 0)) ?>"
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
        <input type="checkbox" name="is_public" value="1"
            <?= $isEdit ? (!empty($combo['is_public']) ? 'checked' : '') : 'checked' ?>>
        Visible en catálogo (pestaña Combos)
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
<div class="panel" style="margin-top:1rem;max-width:960px">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Imagen del combo</h2>
    <p class="muted" style="font-size:.88rem;margin-top:0">
        Opcional. Si no subes una, el catálogo usará la imagen de la primera certificación del paquete.
    </p>
    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem">
        <div style="width:150px;height:110px;background:#f4f7fb;border-radius:14px;display:flex;align-items:center;justify-content:center;padding:.75rem;border:1px solid #e6ebf2">
            <img src="<?= e(asset(!empty($combo['logo_path']) ? (string) $combo['logo_path'] : '/assets/brand/logo.png')) ?>"
                 alt="" style="max-width:100%;max-height:100%;object-fit:contain">
        </div>
        <form method="post" action="<?= e(url('/admin/combos/' . (int) $combo['id'] . '/logo')) ?>"
              enctype="multipart/form-data" style="display:grid;gap:.65rem;min-width:260px">
            <?= csrf_field() ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Subir imagen
                <input type="file" name="logo" required accept=".jpg,.jpeg,.png,.webp,.gif,.svg">
            </label>
            <button class="btn btn-accent btn-sm" type="submit">Actualizar imagen</button>
        </form>
        <?php if (!empty($combo['logo_path'])): ?>
            <form method="post" action="<?= e(url('/admin/combos/' . (int) $combo['id'] . '/logo/quitar')) ?>"
                  onsubmit="return confirm('¿Quitar la imagen propia del combo?');">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-sm" type="submit">Quitar imagen propia</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if (empty($combo['logo_path'])): ?>
        <p class="muted" style="font-size:.8rem;margin:0">Sin imagen propia (fallback a certificación / logo DOCEO).</p>
    <?php endif; ?>
</div>

<form method="post" action="<?= e(url('/admin/combos/' . (int) $combo['id'] . '/eliminar')) ?>"
      onsubmit="return confirm('¿Eliminar este combo? Solo si no tiene compras.');"
      style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="icon-btn" type="submit" title="Eliminar" aria-label="Eliminar"><?= icon('trash') ?></button>
</form>
<?php endif; ?>

<style>
.html-field-preview {
    padding: .65rem .75rem;
    border: 1px solid #cfd8e6;
    border-radius: 10px;
    background: #fbfcfe;
    min-height: 4.5rem;
    font-size: .9rem;
    line-height: 1.45;
}
.html-field-preview a { color: var(--doceo-blue); }
.html-field-preview ul,
.html-field-preview ol { margin: .35rem 0 .35rem 1.1rem; padding: 0; }
</style>

<script>
(function () {
  document.querySelectorAll('[data-html-field]').forEach(function (wrap) {
    var toggleBtn = wrap.querySelector('.html-preview-toggle');
    var textarea = wrap.querySelector('.html-field-source');
    var preview = wrap.querySelector('.html-field-preview');
    var hint = wrap.querySelector('.html-field-hint');
    if (!toggleBtn || !textarea || !preview) return;

    function updatePreview() {
      preview.innerHTML = textarea.value;
    }

    function setPreviewMode(on) {
      toggleBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      toggleBtn.title = on ? 'Ver código HTML' : 'Ver texto sin código HTML';
      textarea.hidden = on;
      preview.hidden = !on;
      if (hint) {
        hint.innerHTML = on
          ? 'Vista previa renderizada. Pulsa <code>&lt;/&gt;</code> para volver al código HTML.'
          : 'Pulsa <code>&lt;/&gt;</code> para ver el texto sin etiquetas HTML.';
      }
      if (on) updatePreview();
    }

    toggleBtn.addEventListener('click', function () {
      setPreviewMode(toggleBtn.getAttribute('aria-pressed') !== 'true');
    });

    textarea.addEventListener('input', function () {
      if (toggleBtn.getAttribute('aria-pressed') === 'true') updatePreview();
    });
  });
})();
</script>

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

  const specialFields = <?= json_encode(array_keys($specialPriceFields), JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
  const fieldMap = {
    public_price: 'public',
    catalog_price: 'catalog',
    price_partner_a: 'partner-bronze',
    price_partner_b: 'partner-silver',
    price_partner_c: 'partner-gold'
  };
  specialFields.forEach(function (col) {
    fieldMap[col] = String(col).replace(/_/g, '-');
  });

  function money(n) {
    return '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function selected() {
    return checks.filter(function (c) { return c.checked; });
  }

  function totals() {
    const out = { public: 0, catalog: 0, 'partner-bronze': 0, 'partner-silver': 0, 'partner-gold': 0, lines: [] };
    specialFields.forEach(function (col) {
      out[String(col).replace(/_/g, '-')] = 0;
    });
    selected().forEach(function (c) {
      const pub = Number(c.getAttribute('data-public') || 0);
      out.public += pub;
      out.catalog += Number(c.getAttribute('data-catalog') || 0);
      specialFields.forEach(function (col) {
        const key = String(col).replace(/_/g, '-');
        out[key] += Number(c.getAttribute('data-' + key) || 0);
      });
      out['partner-bronze'] += Number(c.getAttribute('data-partner-bronze') || 0);
      out['partner-silver'] += Number(c.getAttribute('data-partner-silver') || 0);
      out['partner-gold'] += Number(c.getAttribute('data-partner-gold') || 0);
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
      specialFields.forEach(function (col) {
        setInput(col, t[String(col).replace(/_/g, '-')] || 0, false);
      });
      setInput('price_partner_a', t['partner-bronze'], false);
      setInput('price_partner_b', t['partner-silver'], false);
      setInput('price_partner_c', t['partner-gold'], false);
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
      specialFields.forEach(function (col) {
        setInput(col, t[String(col).replace(/_/g, '-')] || 0, true);
      });
      setInput('price_partner_a', t['partner-bronze'], true);
      setInput('price_partner_b', t['partner-silver'], true);
      setInput('price_partner_c', t['partner-gold'], true);
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
