<?php
/** @var array<string,mixed> $product */
/** @var list<array<string,mixed>> $media */
?>
<p class="meta"><a href="<?= e(url('/admin/productos')) ?>">← Productos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($product['name']) ?></h1>
<p class="muted"><?= e($product['code']) ?> · <?= e($product['type']) ?></p>

<div class="product-edit-grid">
<form method="post" action="<?= e(url('/admin/productos/' . $product['id'])) ?>" class="panel product-edit-card">
    <?= csrf_field() ?>

    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Campus Moodle</h2>
    <p class="muted" style="font-size:.88rem">
        El ID es el número del curso en Moodle (no el shortname).<br>
        En campus: entra al curso → mira la URL<br>
        <code>…/course/view.php?id=<strong>123</strong></code> → ese <strong>123</strong> es el valor.
    </p>

    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:.85rem;font-size:.88rem;font-weight:600">
        Plataforma
        <select name="platform_type" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            <?php foreach (['none' => 'Ninguna', 'moodle' => 'Moodle (campus DOCEO)', 'provider' => 'Proveedor externo'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= ($product['platform_type'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:.85rem;font-size:.88rem;font-weight:600">
        moodle_course_id
        <input
            type="number"
            name="moodle_course_id"
            min="1"
            step="1"
            placeholder="Ej. 12"
            value="<?= e($product['moodle_course_id'] !== null && $product['moodle_course_id'] !== '' ? (string) $product['moodle_course_id'] : '') ?>"
            style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;max-width:200px"
        >
    </label>

    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;font-size:.88rem;font-weight:600">
        Meses de acceso
        <input
            type="number"
            name="access_months"
            min="1"
            max="60"
            value="<?= (int) ($product['access_months'] ?? 6) ?>"
            style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;max-width:120px"
        >
    </label>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <button class="btn btn-accent" type="submit">Guardar</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/productos')) ?>">Cancelar</a>
    </div>
</form>

<div class="panel product-edit-card">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Logo / imagen de certificación</h2>
    <p class="muted" style="font-size:.88rem;margin-top:0">
        Esta imagen se muestra en la tarjeta del catálogo y en la ficha del producto.
    </p>
    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem">
        <div style="width:150px;height:110px;background:#f4f7fb;border-radius:14px;display:flex;align-items:center;justify-content:center;padding:.75rem;border:1px solid #e6ebf2">
            <img src="<?= e(asset(!empty($product['logo_path']) ? (string) $product['logo_path'] : '/assets/brand/logo.png')) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
        </div>
        <form method="post" action="<?= e(url('/admin/productos/' . $product['id'] . '/logo')) ?>" enctype="multipart/form-data" style="display:grid;gap:.65rem;min-width:260px">
            <?= csrf_field() ?>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Subir nuevo logo
                <input type="file" name="logo" required accept=".jpg,.jpeg,.png,.webp,.gif">
            </label>
            <button class="btn btn-accent btn-sm" type="submit">Actualizar logo</button>
        </form>
    </div>
</div>

<div class="panel product-edit-card">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Galería del producto</h2>
    <p class="muted" style="font-size:.88rem;margin-top:0">
        Agrega ejemplos de certificados, badges, CENNI o videos explicativos. Se muestran al costado derecho de la descripción pública.
    </p>

    <form method="post" action="<?= e(url('/admin/productos/' . $product['id'] . '/media')) ?>" enctype="multipart/form-data" style="padding:1rem;background:#f8fafc;border:1px solid #e6ebf2;border-radius:12px;margin-bottom:1rem">
        <?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Archivo *
                <input type="file" name="media_file" required accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.mov">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Título
                <input type="text" name="title" placeholder="Ej. Ejemplo de certificado" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Orden
                <input type="number" name="sort_order" min="0" value="0" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
        </div>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-top:.75rem">
            Descripción breve
            <input type="text" name="caption" placeholder="Opcional" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;margin:.85rem 0;font-size:.88rem">
            <input type="checkbox" name="is_active" value="1" checked> Mostrar en catálogo
        </label>
        <button class="btn btn-accent btn-sm" type="submit">Agregar multimedia</button>
    </form>

    <?php if ($media === []): ?>
        <p class="muted" style="margin:0">Aún no hay multimedia para este producto.</p>
    <?php else: ?>
        <div class="product-media-admin-grid">
            <?php foreach ($media as $item): ?>
                <article class="product-media-admin-item">
                    <div class="product-media-admin-preview">
                        <?php if (($item['media_type'] ?? '') === 'video'): ?>
                            <video src="<?= e(asset((string) $item['storage_path'])) ?>" controls preload="metadata"></video>
                        <?php else: ?>
                            <img src="<?= e(asset((string) $item['storage_path'])) ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong><?= e((string) ($item['title'] ?: 'Sin título')) ?></strong>
                        <p class="muted" style="font-size:.82rem;margin:.25rem 0">
                            <?= e((string) $item['media_type']) ?> · orden <?= (int) $item['sort_order'] ?>
                            · <?= !empty($item['is_active']) ? 'visible' : 'oculto' ?>
                        </p>
                        <?php if (!empty($item['caption'])): ?>
                            <p class="muted" style="font-size:.82rem;margin:.25rem 0"><?= e((string) $item['caption']) ?></p>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/admin/productos/' . $product['id'] . '/media/' . $item['id'] . '/eliminar')) ?>" onsubmit="return confirm('¿Eliminar este recurso multimedia?')" style="margin-top:.5rem">
                            <?= csrf_field() ?>
                            <button class="btn btn-ghost btn-sm" type="submit">Eliminar</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel product-edit-card">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Después de guardar</h2>
    <ol style="margin:0;padding-left:1.2rem" class="muted">
        <li>Confirma que /admin/salud → Moodle está OK.</li>
        <li>En un caso de ese curso: <strong>Sincronizar Moodle</strong>, o confirma un pago nuevo.</li>
    </ol>
</div>
</div>

<style>
.product-edit-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(320px,1fr));
    gap:1rem;
    align-items:start;
    max-width:1180px;
    margin-top:1rem;
}
.product-edit-card {
    margin:0;
    max-width:none;
    min-width:0;
}
.product-media-admin-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:.85rem;
}
.product-media-admin-item {
    border:1px solid #e6ebf2;
    border-radius:14px;
    padding:.75rem;
    background:#fff;
}
.product-media-admin-preview {
    height:140px;
    background:#f4f7fb;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    margin-bottom:.65rem;
}
.product-media-admin-preview img,
.product-media-admin-preview video {
    width:100%;
    height:100%;
    object-fit:contain;
}
@media (max-width: 860px) {
    .product-edit-grid {
        grid-template-columns:1fr;
    }
}
</style>
