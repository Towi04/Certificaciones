<?php
/** @var array<string,mixed> $template */
/** @var list<string> $placeholders */
/** @var string $uksEmail */
/** @var bool $isUksSolicitud */
/** @var ?array{subject:string,body_html:string,body_text:string} $preview */
?>
<p class="meta"><a href="<?= e(url('/admin/correos')) ?>">← Plantillas de correo</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($template['name']) ?></h1>
<p class="muted">Código: <code><?= e($template['code']) ?></code></p>

<?php if ($isUksSolicitud && $preview): ?>
<div class="panel" style="margin-top:1rem;border:2px solid #dbeafe">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Vista previa (datos de ejemplo)</h2>
    <p class="muted" style="margin-top:0;font-size:.85rem">Así se verá el correo con las variables de prueba (certificación ELeT).</p>
    <p style="margin:.5rem 0"><strong>Asunto:</strong> <?= e($preview['subject']) ?></p>
    <div style="border:1px solid #d5deea;border-radius:12px;padding:1rem;background:#fff">
        <?= $preview['body_html'] ?>
    </div>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem;max-width:720px">
    <form method="post" action="<?= e(url('/admin/correos/' . $template['code'])) ?>">
        <?= csrf_field() ?>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-bottom:1rem">
            Asunto
            <input type="text" name="subject" required value="<?= e($template['subject']) ?>"
                style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%">
        </label>

        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Contenido (HTML)
            <textarea name="body_html" required rows="16"
                style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;font-family:ui-monospace,monospace;font-size:.85rem"><?= e($template['body_html']) ?></textarea>
        </label>

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
                Enviar a
                <input type="email" name="test_to" required
                    value="<?= e($uksEmail) ?>"
                    placeholder="tu-correo@ejemplo.com"
                    style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;max-width:360px">
            </label>
            <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
                Usa SMTP (no mail() local). Sin adjuntos — solo el texto como lo vería UKS.
            </p>
            <button class="btn btn-ghost btn-sm" type="submit">Enviar prueba ahora</button>
        </form>
    <?php endif; ?>
</div>
