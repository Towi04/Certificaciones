<?php
/** @var array<string,mixed> $template */
/** @var list<string> $placeholders */
/** @var string $uksEmail */
/** @var string $testEmailDefault */
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
    <form method="post" action="<?= e(url('/admin/correos/' . $template['code'])) ?>">
        <?= csrf_field() ?>
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
                    <strong>{{certificacion}}</strong> = nombre del producto comprado (ELeT, TOEFL UKS, etc.).
                    <strong>{{product_name}}</strong> es lo mismo.
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <button class="btn btn-accent" type="submit">Guardar plantilla</button>
    </form>

    <?php if ($isUksSolicitud): ?>
        <form id="prueba" method="post" action="<?= e(url('/admin/correos/' . $template['code'] . '/probar')) ?>" style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid #e6ebf2">
            <?= csrf_field() ?>
            <h3 style="margin:0 0 .5rem;font-size:.95rem;color:var(--doceo-blue)">Enviar correo de prueba</h3>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-bottom:.75rem">
                Enviar a (tu correo para probar)
                <input type="email" name="test_to" required
                    value="<?= e($testEmailDefault !== '' ? $testEmailDefault : $uksEmail) ?>"
                    placeholder="tu-correo@ejemplo.com"
                    style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;max-width:360px">
            </label>
            <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
                Por defecto usa <strong>tu correo de admin</strong>, no el destinatario UKS de producción.
                Mismo formato que la prueba de /admin/salud (con logo DOCEO).
            </p>
            <button class="btn btn-ghost btn-sm" type="submit">Enviar prueba ahora</button>
        </form>
    <?php endif; ?>
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
