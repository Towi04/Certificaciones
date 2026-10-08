<?php
/** @var array<string,mixed>|null $campaign */
/** @var array<string,mixed> $audience */
/** @var list<array<string,mixed>> $templates */
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $certifiers */
$isEdit = $campaign !== null;
$action = $isEdit
    ? url('/admin/publicidad/' . (int) $campaign['id'] . '/editar')
    : url('/admin/publicidad/nueva');

$includePartners = $isEdit ? !empty($audience['include_partners']) : false;
$includeClients = true;
if ($isEdit) {
    if (array_key_exists('include_clients', $audience)) {
        $includeClients = !empty($audience['include_clients']);
    } else {
        // Campañas antiguas: estudiantes o legacy = clientes.
        $includeClients = !empty($audience['include_students']) || !empty($audience['include_legacy'])
            || (!$includePartners);
    }
}
if (!$includeClients && !$includePartners) {
    $includeClients = true;
}

$onlyDirect = !empty($audience['only_direct_clients']) || !empty($audience['doceo_direct']);
$audienceMode = 'clients';
if ($includeClients && $includePartners) {
    $audienceMode = 'both';
} elseif ($includePartners && !$includeClients) {
    $audienceMode = 'partners';
} elseif ($includeClients && !$includePartners && $onlyDirect) {
    $audienceMode = 'doceo_direct';
}

$promoMonth = 0;
if ($isEdit) {
    if (isset($audience['promo_month'])) {
        $promoMonth = (int) $audience['promo_month'];
    }
}
$promoMonth = max(0, min(12, $promoMonth));
$monthLabels = \App\Services\PromoDoceoService::monthLabels();

$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;box-sizing:border-box';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
?>
<p class="meta"><a href="<?= e(url('/admin/publicidad')) ?>">← Publicidad</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= $isEdit ? 'Editar campaña' : 'Nueva campaña' ?></h1>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:820px" id="mkt-campaign-form">
    <?= csrf_field() ?>

    <div style="display:grid;gap:.85rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre de la campaña *
            <input type="text" name="name" required maxlength="190" style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($campaign['name'] ?? '')) ?>"
                   placeholder="Ej. Cupón antiguas clientes · diciembre">
        </label>

        <?php
        $mktTemplates = [];
        $otherTemplates = [];
        foreach ($templates as $t) {
            if (empty($t['is_active'])) {
                continue;
            }
            $code = (string) ($t['code'] ?? '');
            if (\App\Services\MailTemplateService::isMarketingTemplate($code)) {
                $mktTemplates[] = $t;
            } else {
                $otherTemplates[] = $t;
            }
        }
        $selectedTpl = (string) ($campaign['mail_template_code'] ?? '');
        ?>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Plantilla de correo *
            <select name="mail_template_code" required style="<?= e($inputStyle) ?>">
                <option value="">— elige plantilla —</option>
                <?php if ($mktTemplates !== []): ?>
                    <optgroup label="Publicidad">
                        <?php foreach ($mktTemplates as $t): ?>
                            <option value="<?= e((string) $t['code']) ?>"
                                <?= $selectedTpl === (string) $t['code'] ? 'selected' : '' ?>>
                                <?= e((string) ($t['name'] ?? $t['code'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if ($otherTemplates !== []): ?>
                    <optgroup label="Otras (operativas)">
                        <?php foreach ($otherTemplates as $t): ?>
                            <option value="<?= e((string) $t['code']) ?>"
                                <?= $selectedTpl === (string) $t['code'] ? 'selected' : '' ?>>
                                <?= e((string) ($t['name'] ?? $t['code'])) ?>
                                · <?= e(\App\Services\MailTemplateService::audienceLabel(
                                    \App\Services\MailTemplateService::audienceForTemplate((string) $t['code'])
                                )) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </label>

        <div>
            <label class="muted" for="mkt-promo-month" style="display:block;font-size:.88rem;font-weight:600;margin:0 0 .35rem;white-space:nowrap">
                Código Promo DOCEO <span style="font-weight:500">(para <code style="white-space:nowrap">{{promo_code}}</code>)</span>
            </label>
            <select name="promo_month" id="mkt-promo-month" style="<?= e($inputStyle) ?>">
                <option value="0" <?= $promoMonth === 0 ? 'selected' : '' ?>>
                    Mes actual (al momento del envío)
                </option>
                <?php foreach ($monthLabels as $m => $label): ?>
                    <option value="<?= (int) $m ?>" <?= $promoMonth === (int) $m ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="muted" style="margin:.4rem 0 0;font-size:.8rem">
                Usa el código del mes definido en Promo DOCEO. Puedes fijar un mes
                (p. ej. Diciembre) para programar campañas futuras o reutilizar la plantilla cada año.
            </p>
        </div>

        <fieldset style="border:1px solid #e6ebf2;border-radius:12px;padding:.85rem 1rem;margin:0">
            <legend style="padding:0 .35rem;font-weight:700;color:var(--doceo-blue)">Audiencia</legend>
            <div style="display:grid;gap:.55rem;margin-bottom:.85rem">
                <label style="display:flex;gap:.45rem;align-items:flex-start;font-size:.9rem">
                    <input type="radio" name="audience_mode" value="clients"
                           <?= $audienceMode === 'clients' ? 'checked' : '' ?> style="margin-top:.2rem">
                    <span>
                        <strong>Todos los clientes</strong>
                        <span class="muted" style="display:block;font-size:.8rem;font-weight:400">
                            Alumnos del sistema (pagados o pendientes) + clientes anteriores (CSV), con o sin partner.
                        </span>
                    </span>
                </label>
                <label style="display:flex;gap:.45rem;align-items:flex-start;font-size:.9rem">
                    <input type="radio" name="audience_mode" value="doceo_direct"
                           <?= $audienceMode === 'doceo_direct' ? 'checked' : '' ?> style="margin-top:.2rem">
                    <span>
                        <strong>Solo clientes DOCEO (sin partner)</strong>
                        <span class="muted" style="display:block;font-size:.8rem;font-weight:400">
                            Compras sin partner ni código partner + clientes anteriores (CSV).
                        </span>
                    </span>
                </label>
                <label style="display:flex;gap:.45rem;align-items:flex-start;font-size:.9rem">
                    <input type="radio" name="audience_mode" value="partners"
                           <?= $audienceMode === 'partners' ? 'checked' : '' ?> style="margin-top:.2rem">
                    <span>
                        <strong>Solo partners</strong>
                        <span class="muted" style="display:block;font-size:.8rem;font-weight:400">
                            Partners activos del sistema.
                        </span>
                    </span>
                </label>
                <label style="display:flex;gap:.45rem;align-items:flex-start;font-size:.9rem">
                    <input type="radio" name="audience_mode" value="both"
                           <?= $audienceMode === 'both' ? 'checked' : '' ?> style="margin-top:.2rem">
                    <span>
                        <strong>Clientes y partners</strong>
                    </span>
                </label>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Filtrar por certificadora
                    <select name="certifier_id" style="<?= e($inputStyle) ?>" id="mkt-certifier">
                        <option value="">Todas</option>
                        <?php foreach ($certifiers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= ((int) ($audience['certifier_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
                                <?= e((string) $c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Filtrar por certificación / producto
                    <select name="product_id" style="<?= e($inputStyle) ?>" id="mkt-product">
                        <option value="">Todas</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"
                                data-certifier="<?= (int) ($p['certifier_id'] ?? 0) ?>"
                                <?= ((int) ($audience['product_id'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>>
                                <?= e((string) $p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="muted" style="margin:.65rem 0 0;font-size:.8rem">
                Los filtros aplican a clientes (compras del sistema y clientes anteriores enlazados).
                Si eliges una certificación concreta, esa tiene prioridad sobre la certificadora.
            </p>
            <p id="mkt-audience-preview" class="muted" style="margin:.55rem 0 0;font-size:.85rem;font-weight:600;color:#334155"></p>
        </fieldset>

        <fieldset style="border:1px solid #e6ebf2;border-radius:12px;padding:.85rem 1rem;margin:0">
            <legend style="padding:0 .35rem;font-weight:700;color:var(--doceo-blue)">Programación</legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Fecha inicio *
                    <input type="date" name="window_start" required style="<?= e($inputStyle) ?>"
                           value="<?= e((string) ($campaign['window_start'] ?? date('Y-m-d'))) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Fecha fin *
                    <input type="date" name="window_end" required style="<?= e($inputStyle) ?>"
                           value="<?= e((string) ($campaign['window_end'] ?? date('Y-m-d', strtotime('+7 days')))) ?>">
                </label>
            </div>
            <p class="muted" style="margin:.65rem 0 0;font-size:.8rem">
                El sistema reparte los envíos automáticamente en ese rango (horario laboral y ritmo
                anti-spam) para evitar que nos marquen como spam. No hace falta configurar intervalos.
            </p>
        </fieldset>

        <label class="muted" style="<?= e($labelStyle) ?>">
            Notas internas (opcional)
            <textarea name="notes" rows="2" style="<?= e($inputStyle) ?>"><?= e((string) ($campaign['notes'] ?? '')) ?></textarea>
        </label>
    </div>

    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1.1rem">
        <button type="submit" class="btn btn-accent"><?= $isEdit ? 'Guardar cambios' : 'Crear campaña' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/publicidad')) ?>">Cancelar</a>
    </div>
</form>

<script>
(function () {
  var form = document.getElementById('mkt-campaign-form');
  var out = document.getElementById('mkt-audience-preview');
  if (!form || !out) return;
  var timer = null;
  var certSel = document.getElementById('mkt-certifier');
  var prodSel = document.getElementById('mkt-product');

  function filterProducts() {
    if (!certSel || !prodSel) return;
    var cid = certSel.value;
    var opts = prodSel.querySelectorAll('option[data-certifier]');
    opts.forEach(function (opt) {
      var show = !cid || opt.getAttribute('data-certifier') === cid;
      opt.hidden = !show;
      if (!show && opt.selected) {
        prodSel.value = '';
      }
    });
  }

  function refresh() {
    var params = new URLSearchParams();
    var modeEl = form.querySelector('input[name="audience_mode"]:checked');
    var mode = modeEl ? modeEl.value : 'clients';
    if (mode === 'clients' || mode === 'both' || mode === 'doceo_direct') params.set('include_clients', '1');
    if (mode === 'partners' || mode === 'both') params.set('include_partners', '1');
    if (mode === 'doceo_direct') params.set('only_direct_clients', '1');
    ['product_id','certifier_id'].forEach(function (name) {
      var el = form.querySelector('[name="' + name + '"]');
      if (el && el.value) params.set(name, el.value);
    });
    out.textContent = 'Calculando audiencia…';
    fetch(<?= json_encode(url('/admin/publicidad/api/preview')) ?> + '?' + params.toString())
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          out.textContent = data.error || 'No se pudo calcular la audiencia';
          return;
        }
        out.textContent = 'Destinatarios únicos estimados: ' + (data.count || 0);
      })
      .catch(function () { out.textContent = ''; });
  }
  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(refresh, 250);
  }
  if (certSel) certSel.addEventListener('change', function () { filterProducts(); schedule(); });
  form.querySelectorAll('input,select').forEach(function (el) {
    if (el === certSel) return;
    el.addEventListener('change', schedule);
  });
  filterProducts();
  refresh();
})();
</script>
