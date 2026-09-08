<?php
/** @var array<string,mixed> $template */
/** @var list<string> $placeholders */
/** @var array{to:string,cc:string} $routing */
/** @var string $testEmailDefault */
/** @var bool $requiresFixedRecipient */
/** @var string $audience */
/** @var bool $isUksSolicitud */
/** @var array<string, string> $previewVars */
/** @var array<string, array<string, string>> $availablePlaceholders */
/** @var list<string> $selectedPlaceholders */
/** @var bool $isNew */
$isNew = $isNew ?? false;
$audience = \App\Services\MailTemplateService::normalizeAudience((string) ($audience ?? 'student'));
$requiresFixedRecipient = $requiresFixedRecipient ?? ($audience === 'provider');
$selectedPlaceholders = $selectedPlaceholders ?? $placeholders;
$availablePlaceholders = $availablePlaceholders ?? [];
$formAction = $isNew ? url('/admin/correos/nueva') : url('/admin/correos/' . $template['code']);
?>
<p class="meta"><a href="<?= e(url('/admin/correos')) ?>">← Plantillas de correo</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= $isNew ? 'Nueva plantilla de correo' : e($template['name']) ?></h1>
<p class="muted">Código: <code><?= e($template['code'] ?: 'por definir') ?></code>
    <?php if (!(int) ($template['is_active'] ?? 0)): ?>
        · <strong style="color:#b45309">Plantilla desactivada — no se enviarán correos</strong>
    <?php endif; ?>
</p>

<div class="panel" style="margin-top:1rem;max-width:720px">
    <form method="post" action="<?= e($formAction) ?>" id="mail-template-form" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <?php if ($isNew): ?>
        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <h2 style="margin:0 0 .75rem;font-size:1rem;color:var(--doceo-blue)">Identificación</h2>
            <div style="display:grid;gap:.75rem">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Nombre *
                    <input type="text" name="name" required value="<?= e((string) ($template['name'] ?? '')) ?>"
                        placeholder="Alumno · Recordatorio de examen"
                        style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Código *
                    <input type="text" name="code" required pattern="[a-z0-9_]{3,60}" value="<?= e((string) ($template['code'] ?? '')) ?>"
                        placeholder="student_exam_reminder"
                        style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    <span style="font-size:.78rem">Usa minúsculas, números y guion bajo. Este código se usará para invocar la plantilla.</span>
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Modo
                    <select name="trigger_mode" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                        <option value="manual" <?= (($template['trigger_mode'] ?? 'manual') === 'manual') ? 'selected' : '' ?>>Manual / configurable</option>
                        <option value="automatic" <?= (($template['trigger_mode'] ?? '') === 'automatic') ? 'selected' : '' ?>>Automática</option>
                    </select>
                </label>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $audience = \App\Services\MailTemplateService::normalizeAudience((string) ($audience ?? 'student'));
        $showRouting = $audience === 'provider';
        ?>
        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <h2 style="margin:0 0 .75rem;font-size:1rem;color:var(--doceo-blue)">Destinatario</h2>
            <div style="display:grid;gap:.75rem">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    ¿A quién se envía?
                    <select name="audience" id="mail-audience" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                        <option value="student" <?= $audience === 'student' ? 'selected' : '' ?>>Alumno (correo de su cuenta)</option>
                        <option value="partner" <?= $audience === 'partner' ? 'selected' : '' ?>>Partner (según el alumno inscrito)</option>
                        <option value="provider" <?= $audience === 'provider' ? 'selected' : '' ?>>Proveedor (correo fijo)</option>
                    </select>
                </label>
            </div>

            <div id="mail-student-hint" class="muted" style="margin:.75rem 0 0;font-size:.88rem;<?= $audience === 'student' ? '' : 'display:none' ?>">
                Este correo se envía al <strong>alumno</strong> (correo de su cuenta). No requiere destinatario fijo.
            </div>

            <div id="mail-partner-hint" class="muted" style="margin:.75rem 0 0;font-size:.88rem;<?= $audience === 'partner' ? '' : 'display:none' ?>">
                Se envía al <strong>partner vinculado al caso</strong>: el del código usado al inscribirse o el que registró al alumno.
                No pongas el correo a mano; usa etiquetas como <code>{{partner_name}}</code>, <code>{{partner_code}}</code> o <code>{{partner_email}}</code> en el contenido.
            </div>

            <div id="mail-routing-fields" style="<?= $showRouting ? 'display:grid;gap:.75rem;margin-top:.85rem' : 'display:none;margin-top:.85rem' ?>">
                <p class="muted" style="margin:0;font-size:.85rem">
                    Configura el correo fijo del <strong>proveedor</strong>. Para avisar al partner usa destinatario «Partner», no el CC.
                </p>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Para (proveedor) *
                    <input type="email" name="to_email" id="mail-to-email" value="<?= e($routing['to'] ?? '') ?>"
                        placeholder="operaciones@proveedor.com"
                        style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px"
                        <?= $showRouting ? 'required' : '' ?>>
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    CC (opcional)
                    <input type="text" name="cc_email" id="mail-cc-email" value="<?= e($routing['cc'] ?? '') ?>"
                        placeholder="copia@institutodoceo.com"
                        style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    <span style="font-size:.78rem">Solo copias fijas internas. Separa varios con coma. No uses esto para el partner del alumno.</span>
                </label>
            </div>
        </div>

        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-bottom:1rem">
            Asunto
            <input type="text" name="subject" id="mail-subject" required value="<?= e($template['subject']) ?>"
                style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%">
        </label>

        <div style="margin-bottom:1rem">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.35rem">
                <span class="muted" style="font-size:.88rem;font-weight:600">Contenido (HTML)</span>
                <button type="button" class="btn btn-ghost btn-sm mail-preview-toggle" id="mail-preview-toggle"
                    aria-pressed="false" title="Ver vista previa del correo">
                    &lt;/&gt;
                </button>
            </div>
            <div class="mail-body-editor">
                <textarea name="body_html" id="mail-body-html" required rows="16"
                    style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;font-family:ui-monospace,monospace;font-size:.85rem;display:block"><?= e($template['body_html']) ?></textarea>
                <div id="mail-body-preview" class="mail-body-preview" hidden>
                    <p class="muted" style="font-size:.82rem;margin:0 0 .5rem" id="mail-preview-subject-wrap" hidden>
                        <strong>Asunto:</strong> <span id="mail-preview-subject"></span>
                    </p>
                    <div id="mail-preview-content" class="mail-preview-content"></div>
                </div>
            </div>
            <p class="muted" style="font-size:.78rem;margin:.45rem 0 0" id="mail-preview-hint">
                Pulsa <code>&lt;/&gt;</code> para ver el correo renderizado (sin etiquetas HTML).
            </p>
        </div>

        <label class="muted" style="display:flex;gap:.4rem;align-items:center;margin:1rem 0;font-size:.88rem">
            <input type="checkbox" name="is_active" value="1" <?= (int) $template['is_active'] ? 'checked' : '' ?>>
            Plantilla activa
        </label>

        <div style="margin:1rem 0;padding:1rem;background:#f8fafc;border-radius:12px;border:1px solid #e6ebf2">
            <h2 style="margin:0 0 .5rem;font-size:1rem;color:var(--doceo-blue)">Etiquetas de la plantilla</h2>
            <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
                Elige del catálogo e inserta en el asunto o el HTML (ej. <code>{{pago_proveedor}}</code>).
                También puedes escribir la etiqueta a mano; al guardar se reconocen solas.
            </p>

            <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.55rem;align-items:end;margin-bottom:.85rem">
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem;font-weight:600">
                    Agregar etiqueta
                    <select id="placeholder-picker" style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                        <option value="">— Elige un dato —</option>
                        <?php foreach ($availablePlaceholders as $group => $items): ?>
                            <optgroup label="<?= e($group) ?>">
                                <?php foreach ($items as $key => $label): ?>
                                    <option value="<?= e((string) $key) ?>"
                                            data-label="<?= e((string) $label) ?>">
                                        <?= e((string) $label) ?> · {{<?= e((string) $key) ?>}}
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="button" class="btn btn-ghost" id="placeholder-add-btn">Agregar</button>
            </div>

            <div id="placeholder-selected-empty" class="muted" style="font-size:.82rem;<?= $selectedPlaceholders === [] ? '' : 'display:none' ?>">
                Aún no hay etiquetas. Agrega las que quieras usar en este correo.
            </div>
            <ul id="placeholder-selected-list" class="placeholder-selected-list"<?= $selectedPlaceholders === [] ? ' hidden' : '' ?>>
                <?php
                $flatLabels = [];
                foreach ($availablePlaceholders as $items) {
                    foreach ($items as $k => $lbl) {
                        $flatLabels[(string) $k] = (string) $lbl;
                    }
                }
                foreach ($selectedPlaceholders as $key):
                    $label = $flatLabels[$key] ?? $key;
                    ?>
                    <li class="placeholder-selected-item" data-key="<?= e($key) ?>">
                        <input type="hidden" name="placeholders[]" value="<?= e($key) ?>">
                        <div class="placeholder-selected-meta">
                            <code>{{<?= e($key) ?>}}</code>
                            <small><?= e($label) ?></small>
                        </div>
                        <div class="placeholder-selected-actions">
                            <button type="button" class="btn btn-ghost btn-sm placeholder-insert" data-target="subject" title="Insertar en asunto">Asunto</button>
                            <button type="button" class="btn btn-ghost btn-sm placeholder-insert" data-target="body" title="Insertar en HTML">HTML</button>
                            <button type="button" class="btn btn-ghost btn-sm placeholder-remove" title="Quitar">✕</button>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($isUksSolicitud): ?>
                <p class="muted" style="font-size:.82rem;margin:.85rem 0 0">
                    Para la solicitud al proveedor, usa
                    <code>{{pago_proveedor}}</code> (comprobante DOCEO→proveedor),
                    <code>{{reglamento_url}}</code> y/o <code>{{workbook_url}}</code>,
                    o el bloque <code>{{documentos_html}}</code>.
                </p>
            <?php endif; ?>
        </div>

        <?php if (!$isNew): ?>
        <?php
            $wb = \App\Services\MailTemplateService::workbookConfig((string) $template['code']);
            $wbCells = $wb['cell_map'] !== [] ? $wb['cell_map'] : [['cell' => '', 'field' => '']];
            $fieldOptions = \App\Services\ProviderRequestService::FIELD_OPTIONS;
        ?>
        <div style="margin:1.25rem 0;padding:1rem;background:#f8fafc;border:1px solid #e6ebf2;border-radius:12px">
            <h2 style="margin:0 0 .35rem;font-size:1rem;color:var(--doceo-blue)">Plantilla Excel (opcional)</h2>
            <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
                Si marcas esta opción, al enviar el correo se genera un Excel rellenado y se incluye
                como enlace <code>{{workbook_url}}</code> (sin adjuntos SMTP).
            </p>
            <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem;margin-bottom:.75rem">
                <input type="checkbox" name="workbook_enabled" value="1" id="workbook-enabled" <?= !empty($wb['enabled']) ? 'checked' : '' ?>>
                Este correo incluye plantilla Excel
            </label>
            <div id="workbook-fields" style="<?= !empty($wb['enabled']) ? '' : 'opacity:.55' ?>">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.65rem;margin-bottom:.65rem">
                    <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem;font-weight:600">
                        Archivo actual
                        <input type="text" name="workbook_template_path" value="<?= e($wb['template_path']) ?>"
                               readonly style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px;background:#fff">
                    </label>
                    <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem;font-weight:600">
                        Subir / reemplazar .xlsx
                        <input type="file" name="workbook_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    </label>
                    <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem;font-weight:600">
                        Hoja (opcional)
                        <input type="text" name="workbook_sheet" value="<?= e($wb['sheet']) ?>" placeholder="Sheet1"
                               style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                    </label>
                    <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem;font-weight:600">
                        Normalización
                        <select name="workbook_normalize" style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                            <option value="none" <?= $wb['normalize'] === 'none' ? 'selected' : '' ?>>Ninguna</option>
                            <option value="toefl" <?= $wb['normalize'] === 'toefl' ? 'selected' : '' ?>>TOEFL</option>
                        </select>
                    </label>
                </div>
                <p style="margin:0 0 .35rem;font-weight:700;font-size:.85rem;color:var(--doceo-blue)">Celdas → campos</p>
                <div id="workbook-cells">
                    <?php foreach ($wbCells as $i => $cell): ?>
                        <div style="display:grid;grid-template-columns:7rem 1fr auto;gap:.4rem;margin-bottom:.35rem" class="workbook-cell-row">
                            <input type="text" name="workbook_cells[]" value="<?= e((string) ($cell['cell'] ?? '')) ?>" placeholder="B2"
                                   style="padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px">
                            <select name="workbook_fields[]" style="padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px">
                                <option value="">— Campo —</option>
                                <?php foreach ($fieldOptions as $opt): ?>
                                    <option value="<?= e($opt['value']) ?>" <?= ($cell['field'] ?? '') === $opt['value'] ? 'selected' : '' ?>>
                                        <?= e($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-ghost btn-sm workbook-cell-remove">✕</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" id="workbook-cell-add">+ Celda</button>
                <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.82rem;margin-top:.65rem">
                    <input type="checkbox" name="workbook_clear" value="1"> Quitar archivo Excel
                </label>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$isNew): ?>
        <div style="margin:1.25rem 0;padding:1rem;border-top:1px solid #e6ebf2;border-bottom:1px solid #e6ebf2">
            <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem;font-weight:600">
                <input type="checkbox" name="send_test" value="1" id="send-test-check">
                Enviar correo de prueba al guardar
            </label>
            <div id="test-email-wrap" hidden style="margin-top:.75rem">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Enviar prueba a
                    <input type="email" name="test_email" id="test-email-input"
                        value="<?= e($testEmailDefault) ?>"
                        placeholder="tu-correo@ejemplo.com"
                        style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;max-width:360px">
                </label>
                <p class="muted" style="font-size:.82rem;margin:.45rem 0 0">
                    Intenta <strong>SMTP autenticado</strong> (no usa mail() local). Si falla, verás el error en pantalla.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <button class="btn btn-accent" type="submit"><?= $isNew ? 'Crear plantilla' : 'Guardar plantilla' ?></button>
    </form>
</div>

<style>
.mail-preview-toggle {
    font-family: ui-monospace, monospace;
    font-size: .82rem;
    min-width: 2.25rem;
    padding: .35rem .55rem;
    line-height: 1;
}
.mail-preview-toggle[aria-pressed="true"] {
    background: var(--doceo-blue);
    color: #fff;
    border-color: var(--doceo-blue);
}
.mail-body-preview {
    border: 1px solid #cfd8e6;
    border-radius: 10px;
    padding: 1rem;
    background: #fff;
    min-height: 12rem;
}
.mail-preview-content {
    font-size: .95rem;
    line-height: 1.5;
    color: #1a2b42;
}
.mail-preview-content a { color: var(--doceo-blue); }
.placeholder-selected-list {
    list-style:none;
    margin:0;
    padding:0;
    display:flex;
    flex-direction:column;
    gap:.45rem;
}
.placeholder-selected-item {
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    justify-content:space-between;
    gap:.55rem;
    padding:.55rem .7rem;
    border:1px solid #e6ebf2;
    border-radius:10px;
    background:#fff;
}
.placeholder-selected-meta code {
    font-size:.86rem;
}
.placeholder-selected-meta small {
    display:block;
    color:var(--doceo-muted);
    margin-top:.12rem;
    font-size:.78rem;
}
.placeholder-selected-actions {
    display:flex;
    flex-wrap:wrap;
    gap:.3rem;
}
</style>

<script>
(function () {
  const sampleVars = <?= json_encode($previewVars, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const textarea = document.getElementById('mail-body-html');
  const previewWrap = document.getElementById('mail-body-preview');
  const previewContent = document.getElementById('mail-preview-content');
  const previewSubjectWrap = document.getElementById('mail-preview-subject-wrap');
  const previewSubject = document.getElementById('mail-preview-subject');
  const subjectInput = document.getElementById('mail-subject');
  const toggleBtn = document.getElementById('mail-preview-toggle');
  const hint = document.getElementById('mail-preview-hint');
  const sendTestCheck = document.getElementById('send-test-check');
  const testEmailWrap = document.getElementById('test-email-wrap');
  const testEmailInput = document.getElementById('test-email-input');
  const form = document.getElementById('mail-template-form');

  function toggleTestEmail() {
    if (!sendTestCheck || !testEmailWrap) return;
    const on = sendTestCheck.checked;
    testEmailWrap.hidden = !on;
    if (testEmailInput) {
      testEmailInput.required = on;
    }
  }

  if (sendTestCheck) {
    sendTestCheck.addEventListener('change', toggleTestEmail);
    toggleTestEmail();
  }

  if (form) {
    form.addEventListener('submit', function () {
      if (sendTestCheck && sendTestCheck.checked && testEmailInput && !testEmailInput.value.trim()) {
        testEmailInput.focus();
      }
    });
  }

  if (!textarea || !toggleBtn) return;

  function normalizeKey(key) {
    return String(key || '')
      .trim()
      .toLowerCase()
      .replace(/[\s\-]+/g, '_')
      .replace(/_+/g, '_')
      .replace(/^_+|_+$/g, '');
  }

  function interpolate(text) {
    const lookup = {};
    Object.keys(sampleVars || {}).forEach(function (k) {
      lookup[normalizeKey(k)] = sampleVars[k];
    });
    if (lookup.full_name == null && lookup.name != null) lookup.full_name = lookup.name;
    if (lookup.name == null && lookup.full_name != null) lookup.name = lookup.full_name;
    return String(text || '').replace(/\{\{\s*([a-zA-Z0-9_\- ]+?)\s*\}\}/g, function (m, key) {
      const n = normalizeKey(key);
      return lookup[n] !== undefined ? lookup[n] : m;
    });
  }

  function updatePreview() {
    previewContent.innerHTML = interpolate(textarea.value);
    const subj = interpolate(subjectInput.value || '');
    previewSubject.textContent = subj;
    previewSubjectWrap.hidden = !subj;
  }

  function setPreviewMode(on) {
    toggleBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    toggleBtn.title = on ? 'Ver código HTML' : 'Ver vista previa del correo';
    textarea.hidden = on;
    previewWrap.hidden = !on;
    hint.textContent = on
      ? 'Vista previa con datos de ejemplo. Pulsa </> para volver al código HTML.'
      : 'Pulsa </> para ver el correo renderizado (sin etiquetas HTML).';
    if (on) updatePreview();
  }

  toggleBtn.addEventListener('click', function () {
    setPreviewMode(toggleBtn.getAttribute('aria-pressed') !== 'true');
  });

  textarea.addEventListener('input', function () {
    if (toggleBtn.getAttribute('aria-pressed') === 'true') updatePreview();
  });
  subjectInput.addEventListener('input', function () {
    if (toggleBtn.getAttribute('aria-pressed') === 'true') updatePreview();
  });
})();

(function () {
  var picker = document.getElementById('placeholder-picker');
  var addBtn = document.getElementById('placeholder-add-btn');
  var list = document.getElementById('placeholder-selected-list');
  var empty = document.getElementById('placeholder-selected-empty');
  var subjectInput = document.getElementById('mail-subject');
  var bodyInput = document.getElementById('mail-body-html');
  if (!picker || !addBtn || !list) return;

  function selectedKeys() {
    return Array.prototype.map.call(list.querySelectorAll('.placeholder-selected-item'), function (li) {
      return li.getAttribute('data-key') || '';
    }).filter(Boolean);
  }

  function refreshEmpty() {
    var has = list.querySelector('.placeholder-selected-item');
    list.hidden = !has;
    if (empty) empty.style.display = has ? 'none' : '';
  }

  function insertAtCursor(el, text) {
    if (!el) return;
    var start = el.selectionStart != null ? el.selectionStart : el.value.length;
    var end = el.selectionEnd != null ? el.selectionEnd : el.value.length;
    var before = el.value.slice(0, start);
    var after = el.value.slice(end);
    el.value = before + text + after;
    var pos = start + text.length;
    el.focus();
    if (el.setSelectionRange) el.setSelectionRange(pos, pos);
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function addKey(key, label) {
    if (!key || selectedKeys().indexOf(key) !== -1) return;
    var li = document.createElement('li');
    li.className = 'placeholder-selected-item';
    li.setAttribute('data-key', key);
    li.innerHTML =
      '<input type="hidden" name="placeholders[]" value="">' +
      '<div class="placeholder-selected-meta">' +
        '<code></code><small></small>' +
      '</div>' +
      '<div class="placeholder-selected-actions">' +
        '<button type="button" class="btn btn-ghost btn-sm placeholder-insert" data-target="subject" title="Insertar en asunto">Asunto</button>' +
        '<button type="button" class="btn btn-ghost btn-sm placeholder-insert" data-target="body" title="Insertar en HTML">HTML</button>' +
        '<button type="button" class="btn btn-ghost btn-sm placeholder-remove" title="Quitar">✕</button>' +
      '</div>';
    li.querySelector('input').value = key;
    li.querySelector('code').textContent = '{{' + key + '}}';
    li.querySelector('small').textContent = label || key;
    list.appendChild(li);
    refreshEmpty();
  }

  function syncPickerOptions() {
    var keys = selectedKeys();
    Array.prototype.forEach.call(picker.options, function (opt) {
      if (!opt.value) return;
      opt.disabled = keys.indexOf(opt.value) !== -1;
      opt.hidden = opt.disabled;
    });
  }

  addBtn.addEventListener('click', function () {
    var opt = picker.options[picker.selectedIndex];
    if (!opt || !opt.value || opt.disabled) return;
    addKey(opt.value, opt.getAttribute('data-label') || opt.textContent || opt.value);
    picker.value = '';
    syncPickerOptions();
  });

  list.addEventListener('click', function (e) {
    var removeBtn = e.target.closest('.placeholder-remove');
    if (removeBtn) {
      var row = removeBtn.closest('.placeholder-selected-item');
      if (row) row.remove();
      refreshEmpty();
      syncPickerOptions();
      return;
    }
    var insertBtn = e.target.closest('.placeholder-insert');
    if (!insertBtn) return;
    var item = insertBtn.closest('.placeholder-selected-item');
    if (!item) return;
    var key = item.getAttribute('data-key') || '';
    if (!key) return;
    var tag = '{{' + key + '}}';
    if (insertBtn.getAttribute('data-target') === 'subject') {
      insertAtCursor(subjectInput, tag);
    } else {
      insertAtCursor(bodyInput, tag);
    }
  });

  refreshEmpty();
  syncPickerOptions();
})();

(function () {
  var en = document.getElementById('workbook-enabled');
  var fields = document.getElementById('workbook-fields');
  var list = document.getElementById('workbook-cells');
  var add = document.getElementById('workbook-cell-add');
  if (en && fields) {
    en.addEventListener('change', function () {
      fields.style.opacity = en.checked ? '1' : '.55';
    });
  }
  if (add && list) {
    add.addEventListener('click', function () {
      var row = list.querySelector('.workbook-cell-row');
      if (!row) return;
      var clone = row.cloneNode(true);
      clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      clone.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
      list.appendChild(clone);
    });
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.workbook-cell-remove');
      if (!btn) return;
      var rows = list.querySelectorAll('.workbook-cell-row');
      if (rows.length <= 1) {
        rows[0].querySelectorAll('input').forEach(function (i) { i.value = ''; });
        return;
      }
      btn.closest('.workbook-cell-row').remove();
    });
  }
})();
(function () {
  var audience = document.getElementById('mail-audience');
  var routing = document.getElementById('mail-routing-fields');
  var studentHint = document.getElementById('mail-student-hint');
  var partnerHint = document.getElementById('mail-partner-hint');
  var toInput = document.getElementById('mail-to-email');
  if (!audience || !routing) return;
  function syncAudienceUi() {
    var value = audience.value;
    var isProvider = value === 'provider';
    var isPartner = value === 'partner';
    routing.style.display = isProvider ? 'grid' : 'none';
    routing.style.gap = isProvider ? '.75rem' : '';
    if (studentHint) studentHint.style.display = value === 'student' ? '' : 'none';
    if (partnerHint) partnerHint.style.display = isPartner ? '' : 'none';
    if (toInput) {
      if (isProvider) toInput.setAttribute('required', 'required');
      else toInput.removeAttribute('required');
    }
  }
  audience.addEventListener('change', syncAudienceUi);
  syncAudienceUi();
})();
</script>
