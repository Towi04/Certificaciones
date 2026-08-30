<?php
/** @var array<string,mixed>|null $filter */
$isEdit = $filter !== null;
$filter = $filter ?? [];
$action = $isEdit
    ? url('/admin/filtros-catalogo/' . (int) $filter['id'])
    : url('/admin/filtros-catalogo/nuevo');
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
?>
<p class="meta"><a href="<?= e(url('/admin/filtros-catalogo')) ?>">← Filtros del catálogo</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= $isEdit ? 'Editar filtro' : 'Nuevo filtro' ?></h1>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:560px">
    <?= csrf_field() ?>
    <div style="display:grid;gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre visible en catálogo *
            <input type="text" name="label" required maxlength="120"
                   value="<?= e((string) ($filter['label'] ?? '')) ?>"
                   placeholder="Ej. Francés adultos" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Slug (URL) <?= $isEdit ? '*' : '' ?>
            <input type="text" name="slug" <?= $isEdit ? 'required' : '' ?> maxlength="60"
                   value="<?= e((string) ($filter['slug'] ?? '')) ?>"
                   placeholder="Ej. french-adult" style="<?= e($inputStyle) ?>">
            <span style="font-weight:400;font-size:.8rem">Se usa en <code>?filtro=slug</code>. Si lo dejas vacío al crear, se genera del nombre.</span>
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Grupo (opcional)
            <input type="text" name="filter_group" maxlength="60"
                   value="<?= e((string) ($filter['filter_group'] ?? '')) ?>"
                   placeholder="Ej. Idioma, Proveedor, CENNI" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Orden
            <input type="number" name="sort_order" min="0" max="9999"
                   value="<?= (int) ($filter['sort_order'] ?? 100) ?>" style="<?= e($inputStyle) ?>">
        </label>
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem">
            <input type="checkbox" name="show_in_catalog" value="1"
                <?= !isset($filter['show_in_catalog']) || !empty($filter['show_in_catalog']) ? 'checked' : '' ?>>
            Mostrar en el catálogo público
        </label>
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem">
            <input type="checkbox" name="is_active" value="1"
                <?= !isset($filter['is_active']) || !empty($filter['is_active']) ? 'checked' : '' ?>>
            Activo
        </label>
    </div>
    <div style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap">
        <button class="btn btn-accent" type="submit">Guardar</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/filtros-catalogo')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <form method="post" action="<?= e(url('/admin/filtros-catalogo/' . (int) $filter['id'] . '/eliminar')) ?>"
          onsubmit="return confirm('¿Eliminar este filtro? Se quitará de los productos etiquetados.');"
          style="margin-top:1rem">
        <?= csrf_field() ?>
        <button class="btn btn-ghost" type="submit" style="color:#8a1f1f">Eliminar filtro</button>
    </form>
<?php endif; ?>
