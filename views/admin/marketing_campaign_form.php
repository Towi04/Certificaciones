<?php
/** @var array<string,mixed>|null $campaign */
/** @var array<string,mixed> $audience */
/** @var list<array<string,mixed>> $templates */
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $suppliers */
/** @var list<array<string,mixed>> $certifiers */
$isEdit = $campaign !== null;
$action = $isEdit
    ? url('/admin/publicidad/' . (int) $campaign['id'] . '/editar')
    : url('/admin/publicidad/nueva');
$intervalMin = $isEdit
    ? max(1, (int) round(((int) ($campaign['interval_seconds'] ?? 120)) / 60))
    : 2;
$includeStudents = $isEdit ? !empty($audience['include_students']) : true;
$includePartners = $isEdit ? !empty($audience['include_partners']) : false;
$includeLegacy = $isEdit ? !empty($audience['include_legacy']) : true;
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
        <p class="muted" style="margin:-.4rem 0 0;font-size:.8rem">
            Preferible crear la plantilla con destinatario <strong>Publicidad</strong>
            (así no pide correo fijo; la campaña define a quién llega).
            <a href="<?= e(url('/admin/correos/nueva?audience=marketing')) ?>">Nueva plantilla de publicidad</a>
            · <a href="<?= e(url('/admin/correos?audience=marketing')) ?>">Ver plantillas de publicidad</a>.
            Placeholders: <code>{{name}}</code>, <code>{{full_name}}</code>,
            <code>{{product_name}}</code>, <code>{{catalog_url}}</code>, <code>{{promo_code}}</code>.
        </p>

        <label class="muted" style="<?= e($labelStyle) ?>">
            Código promocional (opcional, para <code>{{promo_code}}</code>)
            <input type="text" name="promo_code" maxlength="40" style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($campaign['promo_code'] ?? '')) ?>"
                   placeholder="Ej. ANTIGUO15">
        </label>

        <fieldset style="border:1px solid #e6ebf2;border-radius:12px;padding:.85rem 1rem;margin:0">
            <legend style="padding:0 .35rem;font-weight:700;color:var(--doceo-blue)">Audiencia</legend>
            <div style="display:flex;flex-wrap:wrap;gap:.85rem 1.25rem;margin-bottom:.75rem">
                <label style="display:flex;gap:.4rem;align-items:center;font-size:.9rem">
                    <input type="checkbox" name="include_students" value="1" <?= $includeStudents ? 'checked' : '' ?>>
                    Compradores del sistema (pagados)
                </label>
                <label style="display:flex;gap:.4rem;align-items:center;font-size:.9rem">
                    <input type="checkbox" name="include_partners" value="1" <?= $includePartners ? 'checked' : '' ?>>
                    Partners activos
                </label>
                <label style="display:flex;gap:.4rem;align-items:center;font-size:.9rem">
                    <input type="checkbox" name="include_legacy" value="1" <?= $includeLegacy ? 'checked' : '' ?>>
                    Clientes anteriores (CSV)
                </label>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Solo producto
                    <select name="product_id" style="<?= e($inputStyle) ?>">
                        <option value="">Todos</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"
                                <?= ((int) ($audience['product_id'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>>
                                <?= e((string) $p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Solo proveedor
                    <select name="supplier_id" style="<?= e($inputStyle) ?>">
                        <option value="">Todos</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"
                                <?= ((int) ($audience['supplier_id'] ?? 0) === (int) $s['id']) ? 'selected' : '' ?>>
                                <?= e((string) $s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Solo certificadora
                    <select name="certifier_id" style="<?= e($inputStyle) ?>">
                        <option value="">Todas</option>
                        <?php foreach ($certifiers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= ((int) ($audience['certifier_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
                                <?= e((string) $c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="muted" style="margin:.65rem 0 0;font-size:.8rem">
                Ej.: compradores de TOEFL → elige el producto. Certificaciones de un proveedor → elige proveedor.
                Los filtros de producto/proveedor aplican a compradores y a contactos CSV emparejados.
            </p>
            <p id="mkt-audience-preview" class="muted" style="margin:.55rem 0 0;font-size:.85rem;font-weight:600;color:#334155"></p>
        </fieldset>

        <fieldset style="border:1px solid #e6ebf2;border-radius:12px;padding:.85rem 1rem;margin:0">
            <legend style="padding:0 .35rem;font-weight:700;color:var(--doceo-blue)">Programación anti-spam</legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
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
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Intervalo entre correos (minutos)
                    <input type="number" name="interval_minutes" min="1" max="1440" required
                           value="<?= (int) $intervalMin ?>" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Hora inicio (día)
                    <input type="number" name="day_hour_start" min="0" max="23"
                           value="<?= (int) ($campaign['day_hour_start'] ?? 9) ?>" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Hora fin (día)
                    <input type="number" name="day_hour_end" min="1" max="24"
                           value="<?= (int) ($campaign['day_hour_end'] ?? 18) ?>" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Máx. por corrida del cron
                    <input type="number" name="max_per_run" min="1" max="50"
                           value="<?= (int) ($campaign['max_per_run'] ?? 15) ?>" style="<?= e($inputStyle) ?>">
                </label>
            </div>
            <p class="muted" style="margin:.65rem 0 0;font-size:.8rem">
                Ejemplo fiestas: del 3 al 20 de diciembre, cada 30–60 minutos entre 9:00 y 18:00.
                No se mandan todos el mismo día ni fuera de ese horario.
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
  function refresh() {
    var params = new URLSearchParams();
    ['include_students','include_partners','include_legacy'].forEach(function (name) {
      var el = form.querySelector('[name="' + name + '"]');
      if (el && el.checked) params.set(name, '1');
    });
    ['product_id','supplier_id','certifier_id'].forEach(function (name) {
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
  form.querySelectorAll('input,select').forEach(function (el) {
    el.addEventListener('change', schedule);
  });
  refresh();
})();
</script>
