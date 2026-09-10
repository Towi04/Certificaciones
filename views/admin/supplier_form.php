<?php
/** @var array<string,mixed>|null $supplier */
$isEdit = $supplier !== null;
$action = $isEdit ? url('/admin/proveedores/' . $supplier['id']) : url('/admin/proveedores/nuevo');
?>
<p class="meta"><a href="<?= e(url('/admin/proveedores')) ?>">← Proveedores</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar proveedor' : 'Nuevo proveedor' ?>
</h1>
<p class="muted" style="max-width:40rem">
    Datos básicos del proveedor. Después podrás agregar logos, certificadoras, contactos,
    notas internas y accesos a portales.
</p>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:640px">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nombre *
            <input type="text" name="name" required
                   value="<?= e((string) ($supplier['name'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            <?php if (!$isEdit): ?>
                <span style="font-weight:500;font-size:.78rem">El código interno se genera a partir del nombre.</span>
            <?php endif; ?>
        </label>
        <?php if ($isEdit): ?>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Código interno
                <input type="text" name="code" readonly maxlength="40"
                       value="<?= e((string) ($supplier['code'] ?? '')) ?>"
                       style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;background:#f4f7fb">
            </label>
        <?php else: ?>
            <input type="hidden" name="code" value="">
        <?php endif; ?>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Sitio web
            <input type="url" name="website"
                   value="<?= e((string) ($supplier['website'] ?? '')) ?>"
                   placeholder="https://..."
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-top:.85rem">
        Notas
        <textarea name="notes" rows="4" placeholder="Acuerdos, condiciones, recordatorios internos…"
                  style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;resize:vertical;min-height:6rem;font:inherit"><?= e((string) ($supplier['notes'] ?? '')) ?></textarea>
        <span style="font-weight:500;font-size:.78rem">Solo para uso interno del equipo.</span>
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
