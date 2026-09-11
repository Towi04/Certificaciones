<?php
/** @var list<array<string,mixed>> $templates */
/** @var array<string,string> $branding */
/** @var string $brandingPreviewHtml */
$branding = is_array($branding ?? null) ? $branding : \App\Mail\MailBranding::config();
$brandingPreviewHtml = (string) ($brandingPreviewHtml ?? \App\Mail\MailBranding::wrap(
    '<p style="margin:0">Así se verá el cuerpo de tus plantillas entre el encabezado y el pie.</p>'
));
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Plantillas de correo</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:48rem">
            Edita asunto, contenido y destinatarios en cada plantilla. Variables con doble llave,
            por ejemplo <code>{{matricula}}</code> o <code>{{certificacion}}</code>.
            <br>Para no volver a ser bloqueados por MailChannels (Neubox): asunto claro (no vacío ni
            TODO MAYÚSCULAS), destinatarios reales, y la solicitud a proveedor <strong>sin adjuntos</strong>
            (solo enlaces).
        </p>
    </div>
    <a class="btn btn-accent" href="<?= e(url('/admin/correos/nueva')) ?>" id="mail-new-template-btn">
        Nueva plantilla
    </a>
</div>

<nav class="group-tabs" style="margin-top:1rem" role="tablist" aria-label="Secciones de correos">
    <button type="button" class="group-tab active" data-tab="templates">Correos</button>
    <button type="button" class="group-tab" data-tab="branding">Encabezado y pie</button>
</nav>

<div class="mail-panel" data-panel="templates">
    <div class="panel" style="margin-top:.75rem">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Plantillas</h2>
        <?php if ($templates === []): ?>
            <p class="muted">No hay plantillas. Abre esta página de nuevo o ejecuta el seed de catálogo.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Nombre</th><th>Código</th><th>Destinatario</th><th>Activa</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($templates as $t): ?>
                        <?php
                        $aud = \App\Services\MailTemplateService::audienceForTemplate((string) ($t['code'] ?? ''));
                        $audLabel = match ($aud) {
                            'provider' => 'Proveedor',
                            'partner' => 'Partner',
                            default => 'Alumno',
                        };
                        ?>
                        <tr>
                            <td><?= e($t['name']) ?></td>
                            <td><code><?= e($t['code']) ?></code></td>
                            <td><?= e($audLabel) ?></td>
                            <td><?= (int) $t['is_active'] ? 'Sí' : 'No' ?></td>
                            <td>
                                <span class="row-actions">
                                    <a class="icon-btn" href="<?= e(url('/admin/correos/' . rawurlencode((string) $t['code']))) ?>"
                                       title="Editar" aria-label="Editar"><?= icon('edit') ?></a>
                                    <form class="icon-btn-form" method="post"
                                          action="<?= e(url('/admin/correos/' . rawurlencode((string) $t['code']) . '/eliminar')) ?>"
                                          onsubmit="return confirm('¿Eliminar esta plantilla de correo?');">
                                        <?= csrf_field() ?>
                                        <button class="icon-btn" type="submit" title="Eliminar" aria-label="Eliminar"><?= icon('trash') ?></button>
                                    </form>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php require BASE_PATH . '/views/shared/pagination.php'; ?>
    </div>
</div>

<div class="mail-panel" data-panel="branding" hidden id="mail-branding">
    <div class="panel" style="margin-top:.75rem">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Encabezado y pie (todos los correos)</h2>
        <p class="muted" style="font-size:.85rem;margin:0 0 1rem">
            Esta marca se aplica a <strong>todas</strong> las plantillas que usan la envoltura DOCEO
            (no a HTML completo pegado desde Outlook). Ideal para cambiar el logo en festividades
            o agregar redes sociales en el pie.
        </p>

        <form method="post" action="<?= e(url('/admin/correos/marca')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div style="display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:1.25rem;align-items:start">
                <div style="display:grid;gap:.85rem">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
                        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                            Nombre en correos
                            <input type="text" name="app_name" value="<?= e((string) ($branding['app_name'] ?? '')) ?>"
                                   placeholder="Instituto DOCEO"
                                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                        </label>
                        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                            Color encabezado
                            <input type="color" name="header_bg" value="<?= e((string) ($branding['header_bg'] ?? '#C4C4C4')) ?>"
                                   style="height:42px;padding:.25rem;border:1px solid #cfd8e6;border-radius:10px;background:#fff">
                        </label>
                        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                            Color pie
                            <input type="color" name="footer_bg" value="<?= e((string) ($branding['footer_bg'] ?? '#315285')) ?>"
                                   style="height:42px;padding:.25rem;border:1px solid #cfd8e6;border-radius:10px;background:#fff">
                        </label>
                        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                            Color texto del pie
                            <input type="color" name="footer_text_color" value="<?= e((string) ($branding['footer_text_color'] ?? '#ffffff')) ?>"
                                   style="height:42px;padding:.25rem;border:1px solid #cfd8e6;border-radius:10px;background:#fff">
                        </label>
                    </div>

                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        Logo (URL)
                        <input type="text" name="logo_url" value="<?= e((string) ($branding['logo_url'] ?? '')) ?>"
                               placeholder="/uploads/mail/branding/logo.png o https://…"
                               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                        <span style="font-weight:500;font-size:.75rem">
                            Vacío = logo por defecto. Actual:
                            <a href="<?= e(\App\Mail\MailBranding::logoUrl()) ?>" target="_blank" rel="noopener">ver</a>
                        </span>
                    </label>

                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        Subir logo (PNG/JPG/WEBP)
                        <input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.webp,.gif,image/*"
                               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;background:#fff">
                        <?php if (trim((string) ($branding['logo_url'] ?? '')) !== ''): ?>
                            <label style="display:flex;align-items:center;gap:.4rem;font-weight:500;font-size:.78rem;margin-top:.2rem">
                                <input type="checkbox" name="clear_logo" value="1"> Quitar logo personalizado
                            </label>
                        <?php endif; ?>
                    </label>

                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        HTML del encabezado (opcional)
                        <textarea name="header_html" rows="4"
                                  placeholder="Vacío = se muestra el logo. Puedes poner imagen + lema, banner de temporada, etc."
                                  style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem;resize:vertical"><?= e((string) ($branding['header_html'] ?? '')) ?></textarea>
                    </label>

                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        HTML del pie
                        <textarea name="footer_html" rows="5"
                                  placeholder="Ejemplo: Instituto DOCEO · <a href=&quot;https://instagram.com/…&quot; style=&quot;color:#fff&quot;>Instagram</a>"
                                  style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem;resize:vertical"><?= e((string) ($branding['footer_html'] ?? '')) ?></textarea>
                        <span style="font-weight:500;font-size:.75rem">
                            Vacío = «<?= e(\App\Mail\MailBranding::appName()) ?> · 🐝». Puedes agregar enlaces a redes, sitio web, aviso legal, etc.
                        </span>
                    </label>

                    <div style="display:flex;flex-wrap:wrap;gap:.55rem;align-items:center">
                        <button class="btn btn-accent" type="submit" name="action" value="save">Guardar marca</button>
                        <button class="btn btn-ghost" type="submit" name="action" value="reset"
                                onclick="return confirm('¿Restablecer encabezado y pie a los valores por defecto?');">
                            Restablecer defaults
                        </button>
                    </div>
                </div>

                <div>
                    <div class="muted" style="font-size:.82rem;font-weight:600;margin-bottom:.45rem">Vista previa</div>
                    <div style="border:1px solid #dbe3ef;border-radius:12px;overflow:hidden;background:#eef2f7;padding:.65rem">
                        <iframe title="Vista previa de marca de correo"
                                sandbox=""
                                srcdoc="<?= e($brandingPreviewHtml) ?>"
                                style="width:100%;min-height:320px;border:0;background:#fff;border-radius:8px"></iframe>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.group-tabs { display:flex; flex-wrap:wrap; gap:.4rem; }
.group-tab {
    border:1px solid #cfd8e6; background:#fff; color:var(--doceo-blue);
    border-radius:999px; padding:.45rem .9rem; font-weight:700; font-size:.86rem; cursor:pointer;
}
.group-tab.active { background:var(--doceo-blue); border-color:var(--doceo-blue); color:#fff; }
</style>
<script>
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.group-tab[data-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.mail-panel[data-panel]'));
  var newBtn = document.getElementById('mail-new-template-btn');
  function activate(name) {
    if (!name) name = 'templates';
    tabs.forEach(function (tab) {
      tab.classList.toggle('active', tab.getAttribute('data-tab') === name);
    });
    panels.forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-panel') !== name;
    });
    if (newBtn) newBtn.style.display = name === 'templates' ? '' : 'none';
    if (history.replaceState) history.replaceState(null, '', '#' + name);
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { activate(tab.getAttribute('data-tab')); });
  });
  var hash = (location.hash || '').replace(/^#/, '');
  if (hash === 'mail-branding') hash = 'branding';
  if (hash && document.querySelector('.mail-panel[data-panel="' + hash + '"]')) {
    activate(hash);
  } else {
    activate('templates');
  }
})();
</script>
