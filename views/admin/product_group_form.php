<?php
/** @var array<string,mixed>|null $group */
/** @var list<array<string,mixed>> $suppliers */
/** @var string $defaultConfig */
/** @var array<string,mixed> $extras */
/** @var array<string,string> $usedDocCodes */
$isEdit = $group !== null;
$action = $isEdit ? url('/admin/grupos/' . $group['id']) : url('/admin/grupos/nuevo');
$extras = $extras ?? \App\Services\ProductAdminService::groupFormExtrasFromConfig($defaultConfig ?? null);
$usedDocCodes = $usedDocCodes ?? [];
$preselectSupplier = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
if (!$isEdit && $preselectSupplier > 0 && empty($group['supplier_id'])) {
    $group = ['supplier_id' => $preselectSupplier];
}
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$dayLabels = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 0 => 'Dom'];
$days = is_array($extras['schedule_days'] ?? null) ? $extras['schedule_days'] : [
    1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 0 => false,
];
$groupCode = (string) ($group['code'] ?? '');
$autoDocCode = 'reglamento_' . ($groupCode !== '' ? preg_replace('/[^a-z0-9]+/', '_', strtolower($groupCode)) : 'nuevo');
$docCode = trim((string) ($extras['reglamento_doc_code'] ?? ''));
if ($docCode === '') {
    $docCode = (string) $autoDocCode;
}
$msiMonths = is_array($extras['msi_months'] ?? null) ? array_map('intval', $extras['msi_months']) : [1, 3, 6, 9, 12];
$checkoutFields = is_array($extras['checkout_fields'] ?? null)
    ? $extras['checkout_fields']
    : ['email', 'first_name', 'last_name_p', 'last_name_m', 'phone'];
$alwaysFields = ['email', 'first_name', 'last_name_p', 'phone'];
$fieldMeta = \App\Services\CheckoutRequirements::FIELD_META;
?>
<p class="meta"><a href="<?= e(url('/admin/grupos')) ?>">← Grupos de proceso</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar grupo' : 'Nuevo grupo de producto' ?>
</h1>
<p class="muted">
    Configura lo compartido por varias certificaciones del mismo proveedor:
    datos del alumno, días/horarios, reglamento y pagos.
    Las <a href="<?= e(url('/admin/vacaciones')) ?>"><strong>vacaciones globales</strong></a>
    se publican una sola vez (excepto grupos marcados como 365 días).
</p>

<nav class="group-tabs" role="tablist" aria-label="Secciones del grupo">
    <button type="button" class="group-tab active" data-tab="general" role="tab" aria-selected="true">General</button>
    <button type="button" class="group-tab" data-tab="fields" role="tab" aria-selected="false">Datos del alumno</button>
    <button type="button" class="group-tab" data-tab="schedule" role="tab" aria-selected="false">Fechas y horarios</button>
    <button type="button" class="group-tab" data-tab="rules" role="tab" aria-selected="false">Reglamento</button>
    <button type="button" class="group-tab" data-tab="payments" role="tab" aria-selected="false">Pagos</button>
    <button type="button" class="group-tab" data-tab="advanced" role="tab" aria-selected="false">Experto</button>
</nav>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:.75rem;max-width:960px" id="group-form">
    <?= csrf_field() ?>
    <input type="hidden" name="apply_structured_config" value="1">

    <div class="group-panel" data-panel="general">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos del grupo</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Nombre *
                <input type="text" name="name" required
                       value="<?= e((string) ($group['name'] ?? '')) ?>"
                       placeholder="Ej. iTEP / Oxford · Exámenes"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Código *
                <input type="text" name="code" id="group-code" required maxlength="40"
                       <?= $isEdit ? 'readonly' : '' ?>
                       value="<?= e($groupCode) ?>"
                       placeholder="Ej. itep-exams"
                       style="<?= e($inputStyle) ?><?= $isEdit ? ';background:#f4f7fb' : '' ?>">
                <?php if ($isEdit): ?>
                    <span style="font-weight:500;font-size:.78rem">El código no se cambia después de crear el grupo.</span>
                <?php endif; ?>
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Proveedor
                <select name="supplier_id" style="<?= e($inputStyle) ?>">
                    <option value="">— Ninguno —</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) ($group['supplier_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?> (<?= e($s['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if (!$isEdit): ?>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Plantilla inicial
                    <select name="template" style="<?= e($inputStyle) ?>">
                        <option value="cert">Certificación / trámite (MSI 1–12)</option>
                        <option value="course">Curso Moodle (sin MSI)</option>
                    </select>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="group-panel" data-panel="fields" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos que se piden al alumno</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Marca qué información debe capturar el alumno al adquirir productos de este grupo.
            No necesitas editar JSON: estos campos aparecen en el paso <strong>Datos</strong> del checkout.
        </p>
        <div class="field-check-grid">
            <?php foreach ($fieldMeta as $code => $meta): ?>
                <?php
                $locked = in_array($code, $alwaysFields, true);
                $checked = $locked || in_array($code, $checkoutFields, true);
                ?>
                <label class="field-check<?= $locked ? ' field-check--locked' : '' ?>">
                    <?php if ($locked): ?>
                        <input type="hidden" name="checkout_fields[]" value="<?= e($code) ?>">
                        <input type="checkbox" checked disabled>
                    <?php else: ?>
                        <input type="checkbox" name="checkout_fields[]" value="<?= e($code) ?>"
                            <?= $checked ? 'checked' : '' ?>>
                    <?php endif; ?>
                    <span>
                        <strong><?= e((string) $meta['label']) ?></strong>
                        <span class="muted" style="display:block;font-size:.75rem;font-weight:500">
                            <?= $locked
                                ? 'Obligatorio en toda compra'
                                : (!empty($meta['required']) ? 'Requerido si se pide' : 'Opcional para el alumno') ?>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="group-panel" data-panel="schedule" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Fechas y horarios de aplicación</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Casi todas las certificaciones piden fecha y hora en el checkout (soporte y caducidad).
            Marca los días en que se puede presentar el examen.
        </p>

        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.65rem">
            <input type="checkbox" name="exam_choose_at_checkout" value="1"
                <?= !empty($extras['exam_choose_at_checkout']) ? 'checked' : '' ?>>
            Pedir fecha y hora de aplicación en el checkout
        </label>

        <label class="muted" style="display:flex;align-items:flex-start;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:1rem">
            <input type="checkbox" name="schedule_available_365" value="1" style="margin-top:.2rem"
                <?= !empty($extras['schedule_available_365']) ? 'checked' : '' ?>>
            <span>
                Disponible los 365 días del año
                <span class="muted" style="display:block;font-weight:500;font-size:.78rem;margin-top:.15rem">
                    Si se marca, <strong>no</strong> aplican las vacaciones globales DOCEO.
                    Igual se pide fecha/hora si la opción de arriba está activa.
                </span>
            </span>
        </label>

        <div style="margin-bottom:1rem">
            <div class="muted" style="font-size:.88rem;font-weight:600;margin-bottom:.45rem">Días en que se puede aplicar</div>
            <div style="display:flex;flex-wrap:wrap;gap:.55rem">
                <?php foreach ($dayLabels as $dow => $label): ?>
                    <label class="day-check">
                        <input type="checkbox" name="schedule_days[<?= (int) $dow ?>]" value="1"
                            <?= !empty($days[$dow]) || !empty($days[(string) $dow]) ? 'checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Minutos por bloque
                <input type="number" name="exam_slot_minutes" min="15" step="5"
                       value="<?= (int) ($extras['exam_slot_minutes'] ?? 30) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Anticipo mínimo (días)
                <input type="number" name="schedule_min_advance_days" min="0" step="1"
                       value="<?= (int) ($extras['schedule_min_advance_days'] ?? 2) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Caducidad para presentar (meses)
                <input type="number" name="exam_validity_months" min="1" max="36" step="1"
                       value="<?= (int) ($extras['exam_validity_months'] ?? 6) ?>"
                       style="<?= e($inputStyle) ?>">
                <span style="font-weight:500;font-size:.75rem">Normalmente 6 meses; después pueden comprar prórroga.</span>
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Lun–Vie desde
                <input type="text" name="schedule_weekdays_start" placeholder="10:00"
                       value="<?= e((string) ($extras['schedule_weekdays_start'] ?? '10:00')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Lun–Vie hasta
                <input type="text" name="schedule_weekdays_end" placeholder="17:30"
                       value="<?= e((string) ($extras['schedule_weekdays_end'] ?? '17:30')) ?>"
                       style="<?= e($inputStyle) ?>">
                <span style="font-weight:500;font-size:.75rem">Usa 24:00 para cubrir hasta medianoche.</span>
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Fin de semana desde
                <input type="text" name="schedule_saturday_start" placeholder="08:00"
                       value="<?= e((string) ($extras['schedule_saturday_start'] ?? '08:00')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Fin de semana hasta
                <input type="text" name="schedule_saturday_end" placeholder="12:00"
                       value="<?= e((string) ($extras['schedule_saturday_end'] ?? '12:00')) ?>"
                       style="<?= e($inputStyle) ?>">
                <span style="font-weight:500;font-size:.75rem">También acepta 24:00.</span>
            </label>
        </div>
        <p class="muted" style="font-size:.78rem;margin:.75rem 0 0">
            Vacaciones DOCEO: <a href="<?= e(url('/admin/vacaciones')) ?>">Administrar fechas globales</a>
        </p>
    </div>

    <div class="group-panel" data-panel="rules" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Reglamento</h2>
        <label class="muted" style="display:flex;align-items:flex-start;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.75rem">
            <input type="checkbox" name="reglamento_enabled" value="1" style="margin-top:.2rem"
                <?= !empty($extras['reglamento_enabled']) ? 'checked' : '' ?>>
            <span>
                Este grupo requiere reglamento firmado
                <span class="muted" style="display:block;font-weight:500;font-size:.78rem;margin-top:.15rem">
                    Si se marca, el alumno deberá firmarlo <strong>antes de pagar</strong>
                    (queda como paso obligatorio del checkout). No hace falta otro check aparte.
                </span>
            </span>
        </label>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Ruta / URL de la plantilla PDF
                <input type="text" name="reglamento_template_path"
                       value="<?= e((string) ($extras['reglamento_template_path'] ?? '')) ?>"
                       placeholder="/assets/reglamentos/elet-reglamento.pdf"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Link externo (referencia / respaldo)
                <input type="url" name="reglamento_source_url"
                       value="<?= e((string) ($extras['reglamento_source_url'] ?? '')) ?>"
                       placeholder="https://drive.google.com/..."
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Código del documento
                <input type="text" name="reglamento_doc_code" id="reglamento_doc_code"
                       value="<?= e($docCode) ?>"
                       style="<?= e($inputStyle) ?>">
                <span id="doc-code-hint" style="font-weight:500;font-size:.78rem">
                    Se propone automáticamente según el código del grupo. Puedes editarlo.
                </span>
            </label>
        </div>
    </div>

    <div class="group-panel" data-panel="payments" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Pagos y MSI</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Elige cómo pueden pagar los alumnos. No necesitas editar JSON.
        </p>
        <div style="display:flex;flex-direction:column;gap:.55rem;margin-bottom:1rem">
            <label style="display:flex;gap:.45rem;align-items:center;font-weight:600">
                <input type="checkbox" name="pay_transfer" value="1" <?= !empty($extras['pay_transfer']) ? 'checked' : '' ?>>
                Transferencia / CLABE + comprobante
            </label>
            <label style="display:flex;gap:.45rem;align-items:center;font-weight:600">
                <input type="checkbox" name="pay_oxxo" value="1" <?= !empty($extras['pay_oxxo']) ? 'checked' : '' ?>>
                OXXO / tienda OpenPay
            </label>
            <label style="display:flex;gap:.45rem;align-items:center;font-weight:600">
                <input type="checkbox" name="pay_card" value="1" <?= !empty($extras['pay_card']) ? 'checked' : '' ?>>
                Tarjeta crédito / débito (OpenPay)
            </label>
        </div>
        <label style="display:flex;gap:.45rem;align-items:center;font-weight:600;margin-bottom:.65rem">
            <input type="checkbox" name="msi_enabled" value="1" <?= !empty($extras['msi_enabled']) ? 'checked' : '' ?>>
            Permitir meses sin intereses (MSI)
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:.55rem">
            <?php foreach ([1, 3, 6, 9, 12] as $m): ?>
                <label class="day-check">
                    <input type="checkbox" name="msi_months[]" value="<?= (int) $m ?>"
                        <?= in_array($m, $msiMonths, true) ? 'checked' : '' ?>>
                    <?= (int) $m === 1 ? '1 mes' : ($m . ' meses') ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="group-panel" data-panel="advanced" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Modo experto (JSON)</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
            Vista avanzada del <code>config_json</code>. Al abrir esta pestaña (y al guardar)
            se sincroniza automáticamente con lo configurado en las demás pestañas:
            datos del alumno, horarios, reglamento y pagos. Esas pestañas tienen prioridad
            sobre las mismas claves del JSON.
        </p>
        <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
            Usa el JSON solo para opciones poco frecuentes (pipeline, documentos del expediente,
            correos, etc.). Para campos del alumno usa la pestaña <strong>Datos del alumno</strong>.
        </p>
        <details open>
            <summary style="cursor:pointer;font-weight:700;color:var(--doceo-blue)">Mostrar / editar JSON crudo</summary>
            <label class="muted" style="<?= e($labelStyle) ?>;margin-top:.75rem">
                config_json
                <textarea name="config_json" id="group-config-json" rows="18"
                          style="padding:.65rem .75rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem"><?= e($defaultConfig) ?></textarea>
            </label>
            <button type="button" class="btn btn-ghost btn-sm" id="sync-config-json" style="margin-top:.5rem">
                Actualizar JSON desde las pestañas
            </button>
        </details>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.1rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar grupo' : 'Crear grupo' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos')) ?>">Cancelar</a>
    </div>
</form>

<style>
.group-tabs { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:1rem; }
.group-tab {
    border:1px solid #cfd8e6; background:#fff; color:var(--doceo-blue);
    border-radius:999px; padding:.45rem .9rem; font-weight:700; font-size:.86rem; cursor:pointer;
}
.group-tab.active { background:var(--doceo-blue); border-color:var(--doceo-blue); color:#fff; }
.day-check {
    display:inline-flex; align-items:center; gap:.35rem;
    border:1px solid #cfd8e6; border-radius:999px; padding:.35rem .7rem;
    font-size:.86rem; font-weight:600; background:#fff;
}
.day-check:has(input:checked) { background:#eef4ff; border-color:#9db7e8; color:var(--doceo-blue); }
.field-check-grid {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.65rem;
}
.field-check {
    display:flex; gap:.55rem; align-items:flex-start;
    border:1px solid #cfd8e6; border-radius:12px; padding:.7rem .8rem; background:#fff;
    cursor:pointer; font-size:.88rem;
}
.field-check:has(input:checked) { background:#eef4ff; border-color:#9db7e8; }
.field-check--locked { opacity:.92; cursor:default; background:#f7f9fc; }
.field-check input { margin-top:.15rem; accent-color:var(--doceo-blue); }
#reglamento_doc_code.is-duplicate { border-color:#d64545 !important; background:#fff5f5; color:#a11; }
.doc-code-error { color:#c0392b; font-weight:700; }
</style>
<script>
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.group-tab[data-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.group-panel[data-panel]'));
  var form = document.getElementById('group-form');
  var jsonTa = document.getElementById('group-config-json');
  var syncBtn = document.getElementById('sync-config-json');
  if (!tabs.length) return;

  function activate(name) {
    if (name === 'advanced') {
      syncJsonFromTabs();
    }
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tab') === name;
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-panel') !== name;
    });
    if (history.replaceState) history.replaceState(null, '', '#' + name);
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { activate(tab.getAttribute('data-tab')); });
  });
  var hash = (location.hash || '').replace(/^#/, '');
  if (hash && document.querySelector('.group-panel[data-panel="' + hash + '"]')) activate(hash);

  var usedDocCodes = <?= json_encode($usedDocCodes, JSON_UNESCAPED_UNICODE) ?> || {};
  var currentGroupCode = <?= json_encode($groupCode, JSON_UNESCAPED_UNICODE) ?>;
  var docInput = document.getElementById('reglamento_doc_code');
  var codeInput = document.getElementById('group-code');
  var hint = document.getElementById('doc-code-hint');
  var docTouched = <?= json_encode(trim((string) ($extras['reglamento_doc_code'] ?? '')) !== '') ?>;

  function slugify(v) {
    return String(v || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'nuevo';
  }
  function autoDoc() {
    var code = codeInput ? codeInput.value : currentGroupCode;
    return 'reglamento_' + slugify(code);
  }
  function validateDocCode() {
    if (!docInput) return true;
    var val = (docInput.value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^_|_$/g, '');
    docInput.value = val;
    var owner = usedDocCodes[val];
    var duplicate = !!(owner && owner !== currentGroupCode);
    docInput.classList.toggle('is-duplicate', duplicate);
    if (hint) {
      if (duplicate) {
        hint.innerHTML = '<span class="doc-code-error">Este código ya lo usa el grupo <code>' + owner + '</code>.</span>';
      } else {
        hint.textContent = 'Se propone automáticamente según el código del grupo. Puedes editarlo.';
      }
    }
    return !duplicate;
  }
  if (docInput) {
    docInput.addEventListener('input', function () { docTouched = true; validateDocCode(); });
    validateDocCode();
  }
  if (codeInput && !codeInput.readOnly) {
    codeInput.addEventListener('input', function () {
      if (!docTouched && docInput) {
        docInput.value = autoDoc();
        validateDocCode();
      }
    });
  }

  function checked(name) {
    var el = form && form.querySelector('[name="' + name + '"]');
    return !!(el && el.checked);
  }
  function val(name, fallback) {
    var el = form && form.querySelector('[name="' + name + '"]');
    if (!el) return fallback;
    var v = String(el.value || '').trim();
    return v !== '' ? v : fallback;
  }
  function intVal(name, fallback) {
    var n = parseInt(val(name, String(fallback)), 10);
    return isNaN(n) ? fallback : n;
  }
  function selectedFields() {
    var out = [];
    if (!form) return out;
    form.querySelectorAll('input[name="checkout_fields[]"]').forEach(function (el) {
      if (el.type === 'hidden' || el.checked) {
        if (out.indexOf(el.value) === -1) out.push(el.value);
      }
    });
    ['email', 'first_name', 'last_name_p', 'phone'].forEach(function (must) {
      if (out.indexOf(must) === -1) out.unshift(must);
    });
    return out;
  }
  function selectedDays() {
    var days = {};
    [0, 1, 2, 3, 4, 5, 6].forEach(function (d) {
      var el = form && form.querySelector('[name="schedule_days[' + d + ']"]');
      days[String(d)] = !!(el && el.checked);
    });
    return days;
  }
  function selectedMsiMonths() {
    var months = [];
    if (!form) return [1];
    form.querySelectorAll('input[name="msi_months[]"]:checked').forEach(function (el) {
      months.push(parseInt(el.value, 10));
    });
    months = months.filter(function (m) { return [1, 3, 6, 9, 12].indexOf(m) !== -1; });
    return months.length ? months : [1];
  }
  function paymentOrder() {
    var order = [];
    if (checked('pay_transfer')) order.push('transfer_proof');
    if (checked('pay_oxxo')) order.push('openpay_store');
    if (checked('pay_card')) order.push('openpay_card');
    return order.length ? order : ['transfer_proof', 'openpay_store', 'openpay_card'];
  }

  function syncJsonFromTabs() {
    if (!jsonTa) return;
    var base = {};
    try {
      base = JSON.parse(jsonTa.value || '{}') || {};
    } catch (e) {
      base = {};
    }
    if (typeof base !== 'object' || Array.isArray(base) || base === null) base = {};

    base.checkout_fields = selectedFields();
    base.exam = Object.assign({}, base.exam || {}, {
      choose_at_checkout: checked('exam_choose_at_checkout'),
      slot_minutes: Math.max(15, intVal('exam_slot_minutes', 30)),
      validity_months: Math.max(1, Math.min(36, intVal('exam_validity_months', 6)))
    });
    base.schedule = Object.assign({}, base.schedule || {}, {
      min_advance_days: Math.max(0, intVal('schedule_min_advance_days', 2)),
      available_365: checked('schedule_available_365'),
      days: selectedDays(),
      weekdays: {
        start: val('schedule_weekdays_start', '10:00'),
        end: val('schedule_weekdays_end', '17:30')
      },
      saturday: {
        start: val('schedule_saturday_start', '08:00'),
        end: val('schedule_saturday_end', '12:00')
      }
    });
    delete base.schedule.blocked_dates;

    var order = paymentOrder();
    base.payments = Object.assign({}, base.payments || {}, {
      default_method: order[0],
      order: order,
      price_includes_fee: !!(base.payments && base.payments.price_includes_fee)
    });
    base.card_msi = {
      enabled: checked('msi_enabled'),
      months: selectedMsiMonths(),
      min_amount: 0
    };

    if (checked('reglamento_enabled')) {
      var path = val('reglamento_template_path', '');
      var source = val('reglamento_source_url', '');
      var doc = val('reglamento_doc_code', autoDoc()).toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^_|_$/g, '');
      base.reglamento = {
        template_path: path,
        source_url: source,
        signature_mode: 'append_to_pdf',
        required_before_checkout: true,
        doc_code: doc || autoDoc()
      };
    } else {
      delete base.reglamento;
    }

    jsonTa.value = JSON.stringify(base, null, 2);
  }

  if (syncBtn) {
    syncBtn.addEventListener('click', function () { syncJsonFromTabs(); });
  }
  if (form) {
    form.addEventListener('submit', function (e) {
      syncJsonFromTabs();
      if (!validateDocCode()) {
        e.preventDefault();
        activate('rules');
        if (docInput) docInput.focus();
        alert('El código del documento ya está en uso. Cámbialo antes de guardar.');
      }
    });
  }
})();
</script>
