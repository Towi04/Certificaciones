<?php
/** @var array<string,mixed> $template */
/** @var list<string> $placeholders */
/** @var array{to:string,cc:string} $routing */
/** @var string $testEmailDefault */
/** @var bool $requiresFixedRecipient */
/** @var bool $isUksSolicitud */
/** @var array<string, string> $previewVars */
?>
<p class="meta"><a href="<?= e(url('/admin/correos')) ?>">← Plantillas de correo</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($template['name']) ?></h1>
<p class="muted">Código: <code><?= e($template['code']) ?></code>
    <?php if (!(int) ($template['is_active'] ?? 0)): ?>
        · <strong style="color:#b45309">Plantilla desactivada — no se enviarán correos</strong>
    <?php endif; ?>
</p>

<div class="panel" style="margin-top:1rem;max-width:720px">
    <form method="post" action="<?= e(url('/admin/correos/' . $template['code'])) ?>" id="mail-template-form">
        <?= csrf_field() ?>

        <?php if ($requiresFixedRecipient): ?>
        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <h2 style="margin:0 0 .75rem;font-size:1rem;color:var(--doceo-blue)">Envío (producción)</h2>
            <p class="muted" style="margin:0 0 .75rem;font-size:.85rem">
                Este correo va a UKS, no al alumno. Configura aquí el destino real y copias opcionales (partner, buzón interno, etc.).
            </p>
            <div style="display:grid;gap:.75rem">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Para (destino UKS) *
                    <input type="email" name="to_email" required value="<?= e($routing['to']) ?>"
                        placeholder="operaciones@uks.mx"
                        style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    CC (opcional, separa varios con coma)
                    <input type="text" name="cc_email" value="<?= e($routing['cc']) ?>"
                        placeholder="partner@ejemplo.com, copia@institutodoceo.com"
                        style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
            </div>
        </div>
        <?php else: ?>
        <p class="muted" style="margin:0 0 1rem;font-size:.88rem;padding:.75rem;background:#f8fafc;border-radius:10px">
            Este correo se envía automáticamente al <strong>alumno</strong> (correo de su cuenta). No requiere destinatario fijo.
        </p>
        <?php endif; ?>

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

        <?php if ($placeholders !== []): ?>
            <p class="muted" style="font-size:.82rem;margin:0 0 1rem">
                Variables:
                <?php foreach ($placeholders as $p): ?>
                    <code style="margin-right:.35rem">{{<?= e($p) ?>}}</code>
                <?php endforeach; ?>
            </p>
            <?php if ($isUksSolicitud): ?>
                <p class="muted" style="font-size:.82rem;margin:0 0 1rem">
                    <strong>{{certificacion}}</strong> = producto comprado.
                    <strong>{{documentos_html}}</strong> = enlaces al reglamento (sin adjuntos).
                </p>
            <?php endif; ?>
        <?php endif; ?>

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
                    Usa datos de ejemplo. Asunto con prefijo <code>[PRUEBA]</code>.
                    Intenta SMTP primero; si falla, usa <code>mail()</code> local. Revisa spam si no llega.
                </p>
            </div>
        </div>

        <button class="btn btn-accent" type="submit">Guardar plantilla</button>
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

  function interpolate(text) {
    return text.replace(/\{\{(\w+)\}\}/g, function (_, key) {
      return sampleVars[key] !== undefined ? sampleVars[key] : '{{' + key + '}}';
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
</script>
