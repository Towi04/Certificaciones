<?php
/** @var array<string,mixed> $template */
/** @var list<string> $placeholders */
/** @var string $uksEmail */
?>
<p class="meta"><a href="<?= e(url('/admin/correos')) ?>">← Plantillas de correo</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($template['name']) ?></h1>
<p class="muted">Código: <code><?= e($template['code']) ?></code></p>

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
                Variables disponibles:
                <?php foreach ($placeholders as $p): ?>
                    <code style="margin-right:.35rem">{{<?= e($p) ?>}}</code>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <button class="btn btn-accent" type="submit">Guardar plantilla</button>
    </form>

    <?php if ($template['code'] === 'uks_elet_solicitud' && $uksEmail !== ''): ?>
        <form method="post" action="<?= e(url('/admin/correos/uks_elet_solicitud/probar')) ?>" style="margin-top:1rem">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit">
                Enviar correo de prueba a <?= e($uksEmail) ?>
            </button>
            <p class="muted" style="font-size:.82rem;margin:.35rem 0 0">Sin adjuntos; solo texto de prueba.</p>
        </form>
    <?php elseif ($template['code'] === 'uks_elet_solicitud'): ?>
        <p class="muted" style="font-size:.82rem;margin-top:1rem">
            Configura el destinatario UKS en la lista de plantillas para enviar una prueba.
        </p>
    <?php endif; ?>
</div>
