<?php
/** @var list<array<string,mixed>> $mailCards */
/** @var array<string,string> $mailCardDefaults */
/** @var list<array<string,mixed>> $mailCardProducts */
$mailCards = is_array($mailCards ?? null) ? $mailCards : [];
$mailCardDefaults = is_array($mailCardDefaults ?? null)
    ? $mailCardDefaults
    : \App\Services\MailProductCardService::defaults();
$mailCardProducts = is_array($mailCardProducts ?? null) ? $mailCardProducts : [];
$cardSvc = new \App\Services\MailProductCardService();
$inputStyle = 'padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;box-sizing:border-box';
$labelStyle = 'display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600';

$productMeta = [];
foreach ($mailCardProducts as $p) {
    $logo = trim((string) ($p['logo_path'] ?? ''));
    $productMeta[(int) $p['id']] = [
        'id' => (int) $p['id'],
        'name' => (string) ($p['name'] ?? ''),
        'logo_url' => $logo !== '' ? asset($logo) : asset('/assets/brand/logo.png'),
        'custom_image_path' => '',
    ];
}
?>
<div class="mail-panel" data-panel="cards" hidden id="mail-cards">
    <div class="panel" style="margin-top:.75rem">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Tarjetas de catálogo para correos</h2>
        <p class="muted" style="margin:0 0 1rem;font-size:.88rem;max-width:52rem">
            Define placeholders como <code>{{elet}}</code> que insertan automáticamente una tarjeta
            con logo, nombre y enlace al producto del catálogo.
            También puedes armar un grid: <code>{{cards:elet,toefl,excel}}</code>.
            Por defecto se usa la imagen del producto; puedes subir otra si queda mejor en el correo.
        </p>

        <form method="post" action="<?= e(url('/admin/correos/tarjetas/defaults')) ?>"
              id="mail-card-defaults-form"
              style="display:grid;gap:.75rem;padding:1rem;border:1px solid #e6ebf2;border-radius:12px;background:#f8fafc;margin-bottom:1.1rem">
            <?= csrf_field() ?>
            <h3 style="margin:0;font-size:.95rem;color:var(--doceo-blue)">Diseño por defecto</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.65rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Layout
                    <select name="default_layout" style="<?= e($inputStyle) ?>">
                        <option value="wide" <?= ($mailCardDefaults['default_layout'] ?? '') === 'wide' ? 'selected' : '' ?>>Rectangular grande</option>
                        <option value="square" <?= ($mailCardDefaults['default_layout'] ?? '') === 'square' ? 'selected' : '' ?>>Cuadro pequeño</option>
                        <option value="row" <?= ($mailCardDefaults['default_layout'] ?? '') === 'row' ? 'selected' : '' ?>>Horizontal + descripción</option>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Badge
                    <select name="default_badge" style="<?= e($inputStyle) ?>">
                        <option value="discount" <?= ($mailCardDefaults['default_badge'] ?? '') === 'discount' ? 'selected' : '' ?>>% de descuento</option>
                        <option value="banner" <?= ($mailCardDefaults['default_badge'] ?? '') === 'banner' ? 'selected' : '' ?>>Franja / bubble de texto</option>
                        <option value="both" <?= ($mailCardDefaults['default_badge'] ?? '') === 'both' ? 'selected' : '' ?>>Ambos</option>
                        <option value="none" <?= ($mailCardDefaults['default_badge'] ?? '') === 'none' ? 'selected' : '' ?>>Sin badge</option>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Texto de franja
                    <input type="text" name="banner_text" maxlength="80" style="<?= e($inputStyle) ?>"
                           value="<?= e((string) ($mailCardDefaults['banner_text'] ?? 'Solicita tu descuento')) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Color acento
                    <input type="color" name="accent_color" style="height:40px;<?= e($inputStyle) ?>"
                           value="<?= e((string) ($mailCardDefaults['accent_color'] ?? '#315285')) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Texto del botón
                    <input type="text" name="cta_label" maxlength="40" style="<?= e($inputStyle) ?>"
                           value="<?= e((string) ($mailCardDefaults['cta_label'] ?? 'Ver en catálogo')) ?>">
                </label>
            </div>
            <div>
                <button type="submit" class="btn btn-ghost btn-sm">Guardar diseño</button>
            </div>
        </form>

        <div class="mail-card-editor" style="display:grid;grid-template-columns:minmax(0,1.15fr) minmax(260px,.85fr);gap:1rem;align-items:start;margin-bottom:1.25rem">
            <form method="post" action="<?= e(url('/admin/correos/tarjetas')) ?>" enctype="multipart/form-data"
                  class="mail-card-form" data-preview-target="mail-card-preview-create"
                  style="display:grid;gap:.75rem;padding:1rem;border:1px solid #dbeafe;border-radius:12px;background:#f4f7fb">
                <?= csrf_field() ?>
                <h3 style="margin:0;font-size:.95rem;color:var(--doceo-blue)">Nueva tarjeta</h3>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.65rem">
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Placeholder *
                        <input type="text" name="placeholder" required pattern="[a-zA-Z][a-zA-Z0-9_\-]{1,40}"
                               placeholder="elet" style="<?= e($inputStyle) ?>">
                        <span style="font-weight:500;font-size:.75rem">Se usará como <code>{{elet}}</code></span>
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Producto *
                        <select name="product_id" required style="<?= e($inputStyle) ?>" class="mail-card-product">
                            <option value="">— elige —</option>
                            <?php foreach ($mailCardProducts as $p): ?>
                                <option value="<?= (int) $p['id'] ?>">
                                    <?= e((string) $p['name']) ?>
                                    <?= !empty($p['code']) ? ' · ' . e((string) $p['code']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Layout
                        <select name="layout" style="<?= e($inputStyle) ?>">
                            <option value="wide">Rectangular grande</option>
                            <option value="square">Cuadro pequeño</option>
                            <option value="row">Horizontal + descripción</option>
                        </select>
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Badge
                        <select name="badge_mode" style="<?= e($inputStyle) ?>">
                            <option value="discount">% de descuento</option>
                            <option value="banner">Franja de texto</option>
                            <option value="both">Ambos</option>
                            <option value="none">Ninguno</option>
                        </select>
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Texto franja (opcional)
                        <input type="text" name="badge_text" maxlength="80" style="<?= e($inputStyle) ?>"
                               placeholder="Solicita tu descuento">
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Orden
                        <input type="number" name="sort_order" min="0" max="9999" value="0" style="<?= e($inputStyle) ?>">
                    </label>
                </div>

                <div style="padding:.75rem;border:1px dashed #cfd8e6;border-radius:10px;background:#fff;display:grid;gap:.55rem">
                    <strong style="font-size:.85rem;color:var(--doceo-blue)">Imagen de la tarjeta</strong>
                    <p class="muted" style="margin:0;font-size:.78rem">
                        Por defecto se usa la imagen/logo del producto. Si el layout horizontal se ve
                        pequeño o recortado, sube aquí una imagen más ancha o con mejor encuadre.
                    </p>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Cambiar imagen (opcional)
                        <input type="file" name="custom_image" accept=".png,.jpg,.jpeg,.webp,.gif,image/*"
                               class="mail-card-image-input" style="<?= e($inputStyle) ?>;background:#fff">
                    </label>
                    <button type="button" class="btn btn-ghost btn-sm mail-card-clear-local-image" hidden>
                        Quitar imagen elegida (volver al logo del producto)
                    </button>
                </div>

                <label style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                    <input type="checkbox" name="show_description" value="1">
                    Mostrar descripción breve
                </label>
                <label style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                    <input type="checkbox" name="is_active" value="1" checked>
                    Activa
                </label>
                <div>
                    <button type="submit" class="btn btn-accent btn-sm">Crear tarjeta</button>
                </div>
            </form>

            <aside class="mail-card-preview-box" style="position:sticky;top:1rem;padding:1rem;border:1px solid #e6ebf2;border-radius:12px;background:#fff;box-shadow:0 8px 20px rgba(15,23,42,.04)">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.55rem">
                    <strong style="font-size:.9rem;color:var(--doceo-blue)">Vista previa</strong>
                    <span class="muted" style="font-size:.72rem" id="mail-card-preview-create-status">En vivo</span>
                </div>
                <div id="mail-card-preview-create" class="mail-card-preview-host"
                     style="min-height:160px;padding:.35rem;border:1px dashed #cfd8e6;border-radius:10px;background:#f8fafc">
                    <p class="muted" style="margin:0;font-size:.85rem">Elige un producto para ver cómo quedará la tarjeta.</p>
                </div>
            </aside>
        </div>

        <?php if ($mailCards === []): ?>
            <p class="muted" style="margin:0">Aún no hay tarjetas. Crea una (ej. placeholder <code>elet</code>) y úsala en una plantilla de publicidad.</p>
        <?php else: ?>
            <h3 style="margin:0 0 .75rem;font-size:1rem;color:var(--doceo-blue)">Tarjetas guardadas</h3>
            <div style="display:grid;gap:1rem">
                <?php foreach ($mailCards as $card): ?>
                    <?php
                    $pct = \App\Services\MailProductCardService::discountPercent(
                        (float) ($card['catalog_price'] ?? 0),
                        (float) ($card['public_price'] ?? 0)
                    );
                    $cid = (int) $card['id'];
                    $customImg = trim((string) ($card['custom_image_path'] ?? ''));
                    $previewId = 'mail-card-preview-' . $cid;
                    ?>
                    <div class="mail-card-editor" style="display:grid;grid-template-columns:minmax(0,1.15fr) minmax(260px,.85fr);gap:1rem;align-items:start;padding:1rem;border:1px solid #e6ebf2;border-radius:12px;background:#fff">
                        <div>
                            <div style="display:flex;flex-wrap:wrap;gap:.5rem .85rem;align-items:center;margin-bottom:.75rem">
                                <code style="font-size:.9rem">{{<?= e((string) $card['placeholder']) ?>}}</code>
                                <strong><?= e((string) ($card['product_name'] ?? '')) ?></strong>
                                <span class="pill"><?= e((string) $card['layout']) ?></span>
                                <span class="pill"><?= e((string) $card['badge_mode']) ?></span>
                                <?php if ($pct > 0): ?><span class="pill">-<?= $pct ?>%</span><?php endif; ?>
                                <span class="muted" style="font-size:.78rem"><?= !empty($card['is_active']) ? 'Activa' : 'Inactiva' ?></span>
                                <?php if ($customImg !== ''): ?>
                                    <span class="pill" style="background:#eef2ff">Imagen personalizada</span>
                                <?php endif; ?>
                            </div>
                            <details>
                                <summary class="btn btn-ghost btn-sm" style="cursor:pointer;list-style:none">Editar configuración</summary>
                                <form method="post" action="<?= e(url('/admin/correos/tarjetas/' . $cid)) ?>"
                                      enctype="multipart/form-data"
                                      class="mail-card-form" data-preview-target="<?= e($previewId) ?>"
                                      data-custom-image-path="<?= e($customImg) ?>"
                                      style="display:grid;gap:.55rem;margin-top:.65rem;padding:.75rem;border:1px solid #e6ebf2;border-radius:10px;background:#f8fafc">
                                    <?= csrf_field() ?>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.55rem">
                                        <label class="muted" style="<?= e($labelStyle) ?>">
                                            Placeholder
                                            <input type="text" name="placeholder" required
                                                   value="<?= e((string) $card['placeholder']) ?>" style="<?= e($inputStyle) ?>">
                                        </label>
                                        <label class="muted" style="<?= e($labelStyle) ?>">
                                            Producto
                                            <select name="product_id" required style="<?= e($inputStyle) ?>" class="mail-card-product">
                                                <?php foreach ($mailCardProducts as $p): ?>
                                                    <option value="<?= (int) $p['id'] ?>"
                                                        <?= ((int) $card['product_id'] === (int) $p['id']) ? 'selected' : '' ?>>
                                                        <?= e((string) $p['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="muted" style="<?= e($labelStyle) ?>">
                                            Layout
                                            <select name="layout" style="<?= e($inputStyle) ?>">
                                                <?php foreach (['wide' => 'Rectangular', 'square' => 'Cuadro', 'row' => 'Horizontal'] as $val => $lab): ?>
                                                    <option value="<?= e($val) ?>" <?= ($card['layout'] ?? '') === $val ? 'selected' : '' ?>><?= e($lab) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="muted" style="<?= e($labelStyle) ?>">
                                            Badge
                                            <select name="badge_mode" style="<?= e($inputStyle) ?>">
                                                <?php foreach (['discount' => '% descuento', 'banner' => 'Franja', 'both' => 'Ambos', 'none' => 'Ninguno'] as $val => $lab): ?>
                                                    <option value="<?= e($val) ?>" <?= ($card['badge_mode'] ?? '') === $val ? 'selected' : '' ?>><?= e($lab) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="muted" style="<?= e($labelStyle) ?>">
                                            Texto franja
                                            <input type="text" name="badge_text" maxlength="80" style="<?= e($inputStyle) ?>"
                                                   value="<?= e((string) ($card['badge_text'] ?? '')) ?>">
                                        </label>
                                        <label class="muted" style="<?= e($labelStyle) ?>">
                                            Orden
                                            <input type="number" name="sort_order" min="0" max="9999"
                                                   value="<?= (int) ($card['sort_order'] ?? 0) ?>" style="<?= e($inputStyle) ?>">
                                        </label>
                                    </div>

                                    <div style="padding:.65rem;border:1px dashed #cfd8e6;border-radius:10px;background:#fff;display:grid;gap:.45rem">
                                        <strong style="font-size:.82rem;color:var(--doceo-blue)">Imagen</strong>
                                        <?php if ($customImg !== ''): ?>
                                            <p class="muted" style="margin:0;font-size:.75rem">
                                                Actual (personalizada):
                                                <a href="<?= e(asset($customImg)) ?>" target="_blank" rel="noopener">ver</a>
                                            </p>
                                            <label style="display:flex;gap:.4rem;align-items:center;font-size:.82rem">
                                                <input type="checkbox" name="clear_custom_image" value="1" class="mail-card-clear-saved-image">
                                                Volver a la imagen del producto
                                            </label>
                                        <?php else: ?>
                                            <p class="muted" style="margin:0;font-size:.75rem">Usando la imagen del producto.</p>
                                        <?php endif; ?>
                                        <label class="muted" style="<?= e($labelStyle) ?>">
                                            Subir otra imagen
                                            <input type="file" name="custom_image" accept=".png,.jpg,.jpeg,.webp,.gif,image/*"
                                                   class="mail-card-image-input" style="<?= e($inputStyle) ?>;background:#fff">
                                        </label>
                                        <button type="button" class="btn btn-ghost btn-sm mail-card-clear-local-image" hidden>
                                            Quitar imagen elegida ahora
                                        </button>
                                    </div>

                                    <label style="display:flex;gap:.4rem;align-items:center;font-size:.85rem">
                                        <input type="checkbox" name="show_description" value="1"
                                            <?= !empty($card['show_description']) ? 'checked' : '' ?>>
                                        Mostrar descripción
                                    </label>
                                    <label style="display:flex;gap:.4rem;align-items:center;font-size:.85rem">
                                        <input type="checkbox" name="is_active" value="1"
                                            <?= !empty($card['is_active']) ? 'checked' : '' ?>>
                                        Activa
                                    </label>
                                    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                                        <button type="submit" class="btn btn-accent btn-sm">Guardar cambios</button>
                                    </div>
                                </form>
                                <form method="post" action="<?= e(url('/admin/correos/tarjetas/' . $cid . '/eliminar')) ?>"
                                      style="margin-top:.4rem"
                                      onsubmit="return confirm('¿Eliminar esta tarjeta?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:#b42318">Eliminar</button>
                                </form>
                            </details>
                        </div>
                        <aside class="mail-card-preview-box" style="padding:.85rem;border:1px solid #e6ebf2;border-radius:12px;background:#f8fafc">
                            <div class="muted" style="font-size:.75rem;margin-bottom:.35rem">Vista previa</div>
                            <div id="<?= e($previewId) ?>" class="mail-card-preview-host"
                                 style="background:#fff;padding:.4rem;border-radius:10px;border:1px dashed #cfd8e6">
                                <?= $cardSvc->renderCard($card) ?>
                            </div>
                        </aside>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media (max-width: 900px) {
  .mail-card-editor { grid-template-columns: 1fr !important; }
  .mail-card-preview-box { position: static !important; }
}
</style>
<script>
(function () {
  var previewUrl = <?= json_encode(url('/admin/correos/tarjetas/preview')) ?>;
  var defaultsForm = document.getElementById('mail-card-defaults-form');
  var localImages = new WeakMap();

  function csrfToken(form) {
    var el = form.querySelector('input[name="_csrf"], input[name="csrf"], input[name="csrf_token"]');
    return el ? el.value : '';
  }

  function defaultsPayload() {
    if (!defaultsForm) return {};
    return {
      accent_color: (defaultsForm.querySelector('[name="accent_color"]') || {}).value || '',
      banner_text: (defaultsForm.querySelector('[name="banner_text"]') || {}).value || '',
      cta_label: (defaultsForm.querySelector('[name="cta_label"]') || {}).value || ''
    };
  }

  function schedulePreview(form) {
    clearTimeout(form._previewTimer);
    form._previewTimer = setTimeout(function () { refreshPreview(form); }, 180);
  }

  function refreshPreview(form) {
    var targetId = form.getAttribute('data-preview-target');
    var host = targetId ? document.getElementById(targetId) : null;
    if (!host) return;
    var productId = (form.querySelector('[name="product_id"]') || {}).value || '';
    if (!productId) {
      host.innerHTML = '<p class="muted" style="margin:0;font-size:.85rem">Elige un producto para ver cómo quedará la tarjeta.</p>';
      return;
    }

    var status = document.getElementById(targetId + '-status');
    if (status) status.textContent = 'Actualizando…';

    var body = new URLSearchParams();
    var csrf = csrfToken(form);
    if (csrf) {
      // Nombres habituales en el proyecto
      ['csrf', '_csrf', 'csrf_token'].forEach(function (k) { body.set(k, csrf); });
    }
    body.set('product_id', productId);
    body.set('layout', (form.querySelector('[name="layout"]') || {}).value || 'wide');
    body.set('badge_mode', (form.querySelector('[name="badge_mode"]') || {}).value || 'discount');
    body.set('badge_text', (form.querySelector('[name="badge_text"]') || {}).value || '');
    if ((form.querySelector('[name="show_description"]') || {}).checked) body.set('show_description', '1');

    var clearSaved = form.querySelector('.mail-card-clear-saved-image');
    var savedCustom = form.getAttribute('data-custom-image-path') || '';
    if (savedCustom && !(clearSaved && clearSaved.checked)) {
      body.set('custom_image_path', savedCustom);
    }

    var local = localImages.get(form);
    if (local) body.set('preview_image_url', local);

    var d = defaultsPayload();
    Object.keys(d).forEach(function (k) { if (d[k]) body.set(k, d[k]); });

    fetch(previewUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          host.innerHTML = '<p class="muted" style="margin:0;font-size:.85rem">' + (data && data.error ? data.error : 'No se pudo generar la vista previa') + '</p>';
          if (status) status.textContent = 'Error';
          return;
        }
        host.innerHTML = data.html || '';
        if (status) status.textContent = 'En vivo';
      })
      .catch(function () {
        if (status) status.textContent = 'Error';
      });
  }

  function bindForm(form) {
    form.querySelectorAll('input,select,textarea').forEach(function (el) {
      el.addEventListener('change', function () { schedulePreview(form); });
      el.addEventListener('input', function () { schedulePreview(form); });
    });

    var fileInput = form.querySelector('.mail-card-image-input');
    var clearLocalBtn = form.querySelector('.mail-card-clear-local-image');
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) {
          localImages.delete(form);
          if (clearLocalBtn) clearLocalBtn.hidden = true;
          schedulePreview(form);
          return;
        }
        var reader = new FileReader();
        reader.onload = function () {
          localImages.set(form, String(reader.result || ''));
          if (clearLocalBtn) clearLocalBtn.hidden = false;
          schedulePreview(form);
        };
        reader.readAsDataURL(file);
      });
    }
    if (clearLocalBtn) {
      clearLocalBtn.addEventListener('click', function () {
        localImages.delete(form);
        if (fileInput) fileInput.value = '';
        clearLocalBtn.hidden = true;
        schedulePreview(form);
      });
    }

    schedulePreview(form);
  }

  document.querySelectorAll('.mail-card-form').forEach(bindForm);

  if (defaultsForm) {
    defaultsForm.querySelectorAll('input,select').forEach(function (el) {
      el.addEventListener('change', function () {
        document.querySelectorAll('.mail-card-form').forEach(schedulePreview);
      });
      el.addEventListener('input', function () {
        document.querySelectorAll('.mail-card-form').forEach(schedulePreview);
      });
    });
  }
})();
</script>
