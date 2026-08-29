<?php
/** @var array<string,mixed>|null $product */
/** @var list<array<string,mixed>> $groups */
/** @var list<array<string,mixed>> $suppliers */
/** @var list<array<string,mixed>> $certifiers */
/** @var list<string> $typeOptions */
/** @var list<string> $categoryOptions */
/** @var list<string> $audienceOptions */
/** @var list<string> $platformOptions */
$media = $media ?? [];
/** @var list<array<string,mixed>> $media */
$isEdit = $product !== null;
$action = $isEdit ? url('/admin/productos/' . $product['id']) : url('/admin/productos/nuevo');
$typeOptions = $typeOptions ?? [];
$categoryOptions = $categoryOptions ?? [];
$audienceOptions = $audienceOptions ?? [];
$platformOptions = $platformOptions ?? [];
$groups = $groups ?? [];
$suppliers = $suppliers ?? [];
$certifiers = $certifiers ?? [];
$labels = [
    'type' => [
        'certification' => 'Certificación',
        'course' => 'Curso',
        'procedure' => 'Trámite',
        'shipping' => 'Envío',
        'extension' => 'Extensión',
        'other' => 'Otro',
    ],
    'category' => [
        'it' => 'IT',
        'english_adult' => 'Inglés adultos',
        'english_kids' => 'Inglés kids',
        'teaching' => 'Docencia',
        'other' => 'Otro',
    ],
    'audience' => [
        'adult' => 'Adultos',
        'kids' => 'Kids',
        'any' => 'Cualquiera',
    ],
    'platform' => [
        'none' => 'Ninguna',
        'moodle' => 'Moodle (campus DOCEO)',
        'provider' => 'Proveedor externo',
    ],
];
?>
<p class="meta"><a href="<?= e(url('/admin/productos')) ?>">← Productos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar producto' : 'Nuevo producto' ?>
</h1>
<p class="muted">
    Asigna un <a href="<?= e(url('/admin/grupos')) ?>">grupo de proceso</a> para heredar pagos/MSI.
    Código y nombre se pueden cambiar aquí.
</p>

<?php if (($groups ?? []) === []): ?>
    <div class="flash flash-error" style="margin-top:1rem">
        No hay grupos registrados. El combo quedará vacío.
        Ve a <a href="<?= e(url('/admin/grupos')) ?>"><strong>Grupos de proceso</strong></a>
        y pulsa <strong>Cargar grupos sugeridos</strong>.
    </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem">
    <?= csrf_field() ?>

    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Identidad</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Código *
            <input type="text" name="code" required maxlength="60"
                   value="<?= e((string) ($product['code'] ?? '')) ?>"
                   placeholder="Ej. ITEP-PLUS"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;text-transform:uppercase">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nombre *
            <input type="text" name="name" required
                   value="<?= e((string) ($product['name'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Slug (URL)
            <input type="text" name="slug"
                   value="<?= e((string) ($product['slug'] ?? '')) ?>"
                   placeholder="Se genera del nombre si lo dejas vacío"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Tipo
            <select name="type" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <?php foreach ($typeOptions as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= (($product['type'] ?? 'certification') === $opt) ? 'selected' : '' ?>>
                        <?= e($labels['type'][$opt] ?? $opt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Categoría
            <select name="category" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <?php foreach ($categoryOptions as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= (($product['category'] ?? 'other') === $opt) ? 'selected' : '' ?>>
                        <?= e($labels['category'][$opt] ?? $opt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Audiencia
            <select name="audience" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <?php foreach ($audienceOptions as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= (($product['audience'] ?? 'any') === $opt) ? 'selected' : '' ?>>
                        <?= e($labels['audience'][$opt] ?? $opt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin-top:1.25rem">Proceso y proveedores</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Grupo de proceso
            <select name="product_group_id" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <option value="">— Sin grupo —</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= (int) ($product['product_group_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
                        <?= e($g['name']) ?> (<?= e($g['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <span style="font-weight:500;font-size:.78rem">
                <a href="<?= e(url('/admin/grupos')) ?>">Administrar grupos</a>
            </span>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Proveedor
            <select name="supplier_id" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <option value="">— Ninguno —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) ($product['supplier_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Certificador
            <select name="certifier_id" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <option value="">— Ninguno —</option>
                <?php foreach ($certifiers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) ($product['certifier_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nivel / etiqueta
            <input type="text" name="level_label"
                   value="<?= e((string) ($product['level_label'] ?? '')) ?>"
                   placeholder="Ej. B1 / Intermediate"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin-top:1.25rem">Precios (MXN)</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">
        <?php
        $priceFields = [
            'public_price' => 'Público',
            'catalog_price' => 'Lista / catálogo',
            'cost_price' => 'Costo',
            'price_cncm' => 'CNCM',
            'price_partner_a' => 'Partner A',
            'price_partner_b' => 'Partner B',
            'price_partner_c' => 'Partner C',
        ];
        foreach ($priceFields as $field => $label):
            $val = $product[$field] ?? '';
            ?>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                <?= e($label) ?>
                <input type="number" name="<?= e($field) ?>" min="0" step="0.01"
                       value="<?= e($val !== null && $val !== '' ? (string) $val : '') ?>"
                       style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
        <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:.78rem;margin:.35rem 0 0">
        Si dejas vacío el precio de lista, se calcula automáticamente desde el precio público.
    </p>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin-top:1.25rem">Contenido</h2>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-bottom:.75rem">
        Descripción corta
        <input type="text" name="short_description" maxlength="255"
               value="<?= e((string) ($product['short_description'] ?? '')) ?>"
               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
    </label>
    <div class="html-field" style="margin-bottom:.75rem" data-html-field>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.35rem">
            <span class="muted" style="font-size:.88rem;font-weight:600">Descripción</span>
            <button type="button" class="btn btn-ghost btn-sm html-preview-toggle" aria-pressed="false"
                    title="Ver texto sin código HTML">
                &lt;/&gt;
            </button>
        </div>
        <textarea name="description" rows="5" class="html-field-source"
                  style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;display:block"><?= e((string) ($product['description'] ?? '')) ?></textarea>
        <div class="html-field-preview" hidden></div>
        <p class="muted html-field-hint" style="font-size:.78rem;margin:.35rem 0 0">
            Pulsa <code>&lt;/&gt;</code> para ver el texto sin etiquetas HTML.
        </p>
    </div>
    <div class="html-field" data-html-field>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.35rem">
            <span class="muted" style="font-size:.88rem;font-weight:600">Beneficios (HTML)</span>
            <button type="button" class="btn btn-ghost btn-sm html-preview-toggle" aria-pressed="false"
                    title="Ver texto sin código HTML">
                &lt;/&gt;
            </button>
        </div>
        <textarea name="benefits_html" rows="4" class="html-field-source"
                  style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;font-family:ui-monospace,monospace;font-size:.85rem;display:block"><?= e((string) ($product['benefits_html'] ?? '')) ?></textarea>
        <div class="html-field-preview" hidden></div>
        <p class="muted html-field-hint" style="font-size:.78rem;margin:.35rem 0 0">
            Pulsa <code>&lt;/&gt;</code> para ver el texto sin etiquetas HTML.
        </p>
    </div>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin-top:1.25rem">Campus / publicación</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Plataforma
            <select name="platform_type" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <?php foreach ($platformOptions as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= (($product['platform_type'] ?? 'none') === $opt) ? 'selected' : '' ?>>
                        <?= e($labels['platform'][$opt] ?? $opt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            moodle_course_id
            <input type="number" name="moodle_course_id" min="1" step="1"
                   value="<?= e(($product['moodle_course_id'] ?? '') !== null && ($product['moodle_course_id'] ?? '') !== '' ? (string) $product['moodle_course_id'] : '') ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Meses de acceso
            <input type="number" name="access_months" min="1" max="60"
                   value="<?= (int) ($product['access_months'] ?? 6) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Orden
            <input type="number" name="sort_order"
                   value="<?= (int) ($product['sort_order'] ?? 100) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>

    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:.9rem">
        <label style="display:flex;gap:.4rem;align-items:center;font-size:.9rem">
            <input type="checkbox" name="is_active" value="1" <?= !isset($product['is_active']) || !empty($product['is_active']) ? 'checked' : '' ?>>
            Activo
        </label>
        <label style="display:flex;gap:.4rem;align-items:center;font-size:.9rem">
            <input type="checkbox" name="is_public" value="1" <?= !isset($product['is_public']) || !empty($product['is_public']) ? 'checked' : '' ?>>
            Visible en catálogo
        </label>
        <label style="display:flex;gap:.4rem;align-items:center;font-size:.9rem">
            <input type="checkbox" name="is_star" value="1" <?= !empty($product['is_star']) ? 'checked' : '' ?>>
            Destacado
        </label>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar producto' : 'Crear producto' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/productos')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($isEdit): ?>
<div class="product-edit-grid" style="margin-top:1.25rem">
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
                <input type="file" name="logo" required accept=".jpg,.jpeg,.png,.webp,.gif,.svg">
            </label>
            <button class="btn btn-accent btn-sm" type="submit">Actualizar logo</button>
        </form>
    </div>
</div>

<div class="panel product-edit-card product-gallery-card">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Galería del producto</h2>

    <div class="product-media-admin-layout">
        <form method="post" action="<?= e(url('/admin/productos/' . $product['id'] . '/media')) ?>" enctype="multipart/form-data" class="product-media-upload-form">
            <?= csrf_field() ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Archivo de imagen
                    <input type="file" name="media_file" accept=".jpg,.jpeg,.png,.webp,.gif,.svg">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Video YouTube
                    <input type="url" name="youtube_url" placeholder="https://youtu.be/..." style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
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
            <p class="muted" style="font-size:.82rem;margin:.5rem 0 .85rem">
                Sube una imagen o pega un link de YouTube. Si llenas ambos, se usará el video de YouTube.
            </p>
            <button class="btn btn-accent btn-sm" type="submit">Agregar multimedia</button>
        </form>

        <div class="product-media-admin-list">
            <?php if ($media === []): ?>
                <p class="muted" style="margin:0">Aún no hay multimedia para este producto.</p>
            <?php else: ?>
                <div class="product-media-admin-grid">
                    <?php foreach ($media as $item): ?>
                        <article class="product-media-admin-item">
                            <div class="product-media-admin-preview">
                                <?php if (($item['media_type'] ?? '') === 'video' && !empty($item['external_url'])): ?>
                                    <iframe src="<?= e((string) $item['external_url']) ?>" title="<?= e((string) ($item['title'] ?? 'Video')) ?>" allowfullscreen loading="lazy"></iframe>
                                <?php elseif (($item['media_type'] ?? '') === 'video'): ?>
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
                                <div class="product-media-actions">
                                    <details class="product-media-edit">
                                        <summary class="btn btn-primary btn-sm">Editar</summary>
                                        <form method="post" action="<?= e(url('/admin/productos/' . $product['id'] . '/media/' . $item['id'])) ?>" enctype="multipart/form-data" class="product-media-edit-form">
                                            <?= csrf_field() ?>
                                            <label>Título
                                                <input type="text" name="title" value="<?= e((string) ($item['title'] ?? '')) ?>">
                                            </label>
                                            <label>Orden
                                                <input type="number" name="sort_order" min="0" value="<?= (int) ($item['sort_order'] ?? 0) ?>">
                                            </label>
                                            <label>Descripción breve
                                                <input type="text" name="caption" value="<?= e((string) ($item['caption'] ?? '')) ?>">
                                            </label>
                                            <?php if (($item['media_type'] ?? '') === 'image'): ?>
                                                <label class="product-media-edit-file">Reemplazar imagen
                                                    <input type="file" name="media_file" accept=".jpg,.jpeg,.png,.webp,.gif,.svg">
                                                </label>
                                            <?php endif; ?>
                                            <label class="product-media-check">
                                                <input type="checkbox" name="is_active" value="1" <?= !empty($item['is_active']) ? 'checked' : '' ?>>
                                                Mostrar en catálogo
                                            </label>
                                            <button class="btn btn-accent btn-sm" type="submit">Guardar cambios</button>
                                        </form>
                                    </details>
                                    <form method="post" action="<?= e(url('/admin/productos/' . $product['id'] . '/media/' . $item['id'] . '/eliminar')) ?>" onsubmit="return confirm('¿Eliminar este recurso multimedia?')">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-ghost btn-sm" type="submit">Eliminar</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
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
.product-gallery-card {
    grid-column:1 / -1;
}
.product-media-admin-layout {
    display:grid;
    grid-template-columns:minmax(280px,420px) minmax(0,1fr);
    gap:1rem;
    align-items:start;
}
.product-media-upload-form {
    padding:1rem;
    background:#f8fafc;
    border:1px solid #e6ebf2;
    border-radius:12px;
}
.product-media-admin-list {
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
.product-media-admin-preview video,
.product-media-admin-preview iframe {
    width:100%;
    height:100%;
    object-fit:contain;
    border:0;
}
.product-media-actions {
    display:flex;
    align-items:flex-start;
    gap:.5rem;
    flex-wrap:wrap;
    margin-top:.55rem;
}
.product-media-edit {
    flex:0 0 auto;
}
.product-media-edit[open] {
    flex:1 1 100%;
}
.product-media-edit summary {
    cursor:pointer;
    display:inline-flex;
    list-style:none;
}
.product-media-edit summary::-webkit-details-marker {
    display:none;
}
.product-media-edit[open] summary {
    margin-bottom:.6rem;
}
.product-media-edit-form {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
    gap:.55rem;
    padding:.75rem;
    border:1px solid #e6ebf2;
    border-radius:10px;
    background:#f8fafc;
}
.product-media-edit-form label {
    display:flex;
    flex-direction:column;
    gap:.25rem;
    font-size:.78rem;
    color:var(--doceo-muted);
    font-weight:600;
}
.product-media-edit-form input[type="text"],
.product-media-edit-form input[type="number"] {
    padding:.45rem .55rem;
    border:1px solid #cfd8e6;
    border-radius:8px;
}
.product-media-check {
    flex-direction:row !important;
    align-items:center;
    grid-column:1 / -1;
}
.product-media-edit-file {
    grid-column:1 / -1;
}
@media (max-width: 860px) {
    .product-edit-grid {
        grid-template-columns:1fr;
    }
    .product-media-admin-layout {
        grid-template-columns:1fr;
    }
}
</style>

<?php endif; ?>

<style>
.html-preview-toggle {
    font-family: ui-monospace, monospace;
    font-size: .82rem;
    min-width: 2.25rem;
    padding: .35rem .55rem;
    line-height: 1;
}
.html-preview-toggle[aria-pressed="true"] {
    background: var(--doceo-blue);
    color: #fff;
    border-color: var(--doceo-blue);
}
.html-field-preview {
    border: 1px solid #cfd8e6;
    border-radius: 10px;
    padding: .85rem 1rem;
    background: #fff;
    min-height: 6rem;
    font-size: .95rem;
    line-height: 1.5;
    color: #1a2b42;
}
.html-field-preview a { color: var(--doceo-blue); }
.html-field-preview ul,
.html-field-preview ol { margin: .35rem 0 .35rem 1.1rem; padding: 0; }
</style>
<script>
(function () {
  document.querySelectorAll('[data-html-field]').forEach(function (wrap) {
    var toggleBtn = wrap.querySelector('.html-preview-toggle');
    var textarea = wrap.querySelector('.html-field-source');
    var preview = wrap.querySelector('.html-field-preview');
    var hint = wrap.querySelector('.html-field-hint');
    if (!toggleBtn || !textarea || !preview) return;

    function updatePreview() {
      preview.innerHTML = textarea.value;
    }

    function setPreviewMode(on) {
      toggleBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      toggleBtn.title = on ? 'Ver código HTML' : 'Ver texto sin código HTML';
      textarea.hidden = on;
      preview.hidden = !on;
      if (hint) {
        hint.innerHTML = on
          ? 'Vista previa renderizada. Pulsa <code>&lt;/&gt;</code> para volver al código HTML.'
          : 'Pulsa <code>&lt;/&gt;</code> para ver el texto sin etiquetas HTML.';
      }
      if (on) updatePreview();
    }

    toggleBtn.addEventListener('click', function () {
      setPreviewMode(toggleBtn.getAttribute('aria-pressed') !== 'true');
    });

    textarea.addEventListener('input', function () {
      if (toggleBtn.getAttribute('aria-pressed') === 'true') updatePreview();
    });
  });
})();
</script>
