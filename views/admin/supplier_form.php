<?php
/** @var array<string,mixed>|null $supplier */
$isEdit = $supplier !== null;
$action = $isEdit ? url('/admin/proveedores/' . $supplier['id']) : url('/admin/proveedores/nuevo');
?>
<p class="meta"><a href="<?= e(url('/admin/proveedores')) ?>">← Proveedores</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar proveedor' : 'Nuevo proveedor' ?>
</h1>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:640px">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nombre *
            <input type="text" name="name" required
                   value="<?= e((string) ($supplier['name'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Código *
            <input type="text" name="code" required maxlength="40"
                   <?= $isEdit ? 'readonly' : '' ?>
                   value="<?= e((string) ($supplier['code'] ?? '')) ?>"
                   placeholder="ej. itep"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px<?= $isEdit ? ';background:#f4f7fb' : '' ?>">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Sitio web
            <input type="url" name="website"
                   value="<?= e((string) ($supplier['website'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-top:1rem">
        Notas internas
        <textarea name="notes" rows="4"
                  style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px"><?= e((string) ($supplier['notes'] ?? '')) ?></textarea>
    </label>
    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.85rem">
        <input type="checkbox" name="is_active" value="1"
            <?= $isEdit ? (!empty($supplier['is_active']) ? 'checked' : '') : 'checked' ?>>
        Proveedor activo
    </label>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar' : 'Crear proveedor' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/proveedores')) ?>">Cancelar</a>
    </div>
</form>
