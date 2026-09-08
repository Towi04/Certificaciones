<?php
/** @var array<string,mixed>|null $certifier */
$isEdit = $certifier !== null;
$action = $isEdit ? url('/admin/certificadoras/' . $certifier['id']) : url('/admin/certificadoras/nueva');
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
?>
<p class="meta"><a href="<?= e(url('/admin/proveedores?tab=certificadoras')) ?>">← Certificadoras</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar certificadora' : 'Nueva certificadora' ?>
</h1>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:720px">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre *
            <input type="text" name="name" required
                   value="<?= e((string) ($certifier['name'] ?? '')) ?>"
                   style="<?= e($inputStyle) ?>">
            <?php if (!$isEdit): ?>
                <span style="font-weight:500;font-size:.78rem">El código interno se genera a partir del nombre.</span>
            <?php endif; ?>
        </label>
        <?php if ($isEdit): ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Código interno
                <input type="text" name="code" readonly maxlength="40"
                       value="<?= e((string) ($certifier['code'] ?? '')) ?>"
                       style="<?= e($inputStyle) ?>;background:#f4f7fb">
            </label>
        <?php else: ?>
            <input type="hidden" name="code" value="">
        <?php endif; ?>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Sitio web
            <input type="url" name="website"
                   value="<?= e((string) ($certifier['website'] ?? '')) ?>"
                   placeholder="https://..."
                   style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Plataforma / portal
            <input type="url" name="platform_url"
                   value="<?= e((string) ($certifier['platform_url'] ?? '')) ?>"
                   placeholder="https://..."
                   style="<?= e($inputStyle) ?>">
        </label>
    </div>
    <label class="muted" style="<?= e($labelStyle) ?>;margin-top:1rem">
        Notas internas
        <textarea name="notes" rows="4" style="<?= e($inputStyle) ?>"><?= e((string) ($certifier['notes'] ?? '')) ?></textarea>
    </label>
    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.85rem">
        <input type="checkbox" name="is_active" value="1"
            <?= $isEdit ? (!empty($certifier['is_active']) ? 'checked' : '') : 'checked' ?>>
        Certificadora activa
    </label>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar' : 'Crear certificadora' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/proveedores?tab=certificadoras')) ?>">Cancelar</a>
    </div>
</form>
