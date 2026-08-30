<?php
/** @var array<string,mixed>|null $combo */
/** @var list<array<string,mixed>> $products */
/** @var list<int> $selectedIds */
$isEdit = $combo !== null;
$selectedIds = $selectedIds ?? [];
$action = $isEdit ? url('/admin/combos/' . (int) $combo['id']) : url('/admin/combos/nuevo');
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$typeLabels = [
    'certification' => 'Certificación',
    'course' => 'Curso',
    'procedure' => 'Trámite',
    'shipping' => 'Envío',
    'extension' => 'Extensión',
    'other' => 'Otro',
];
?>
<p class="meta"><a href="<?= e(url('/admin/combos')) ?>">← Combos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar combo' : 'Nuevo combo' ?>
</h1>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:960px">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre *
            <input type="text" name="name" required value="<?= e((string) ($combo['name'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Código *
            <input type="text" name="code" required maxlength="60"
                   <?= $isEdit ? 'readonly' : '' ?>
                   value="<?= e((string) ($combo['code'] ?? '')) ?>"
                   placeholder="ej. toefl-prep-cenni"
                   style="<?= e($inputStyle) ?><?= $isEdit ? ';background:#f4f7fb' : '' ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Slug
            <input type="text" name="slug" value="<?= e((string) ($combo['slug'] ?? '')) ?>"
                   placeholder="auto" style="<?= e($inputStyle) ?>">
        </label>
    </div>
    <label class="muted" style="<?= e($labelStyle) ?>;margin-top:1rem">
        Descripción (visible al alumno)
        <textarea name="description" rows="3" style="<?= e($inputStyle) ?>"><?= e((string) ($combo['description'] ?? '')) ?></textarea>
    </label>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin:1.25rem 0 .5rem">Precios del combo</h2>
    <p class="muted" style="font-size:.85rem;margin-top:0">
        Estos precios sustituyen la suma de productos sueltos cuando el alumno arma este paquete.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">
        <?php
        $priceFields = [
            'public_price' => 'Público *',
            'catalog_price' => 'Lista',
            'price_cncm' => 'CNCM',
            'price_partner_a' => 'Partner A',
            'price_partner_b' => 'Partner B',
            'price_partner_c' => 'Partner C',
        ];
        foreach ($priceFields as $field => $label):
            $val = $combo[$field] ?? '';
            ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                <?= e($label) ?>
                <input type="number" name="<?= e($field) ?>" min="0" step="0.01"
                       value="<?= e($val !== null && $val !== '' ? (string) $val : '') ?>"
                       style="<?= e($inputStyle) ?>"
                    <?= $field === 'public_price' ? 'required' : '' ?>>
            </label>
        <?php endforeach; ?>
    </div>

    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.85rem">
        <input type="checkbox" name="is_active" value="1"
            <?= $isEdit ? (!empty($combo['is_active']) ? 'checked' : '') : 'checked' ?>>
        Combo activo
    </label>
    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.35rem">
        <input type="checkbox" name="is_star" value="1" <?= !empty($combo['is_star']) ? 'checked' : '' ?>>
        Destacado
    </label>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin:1.25rem 0 .5rem">Productos del combo *</h2>
    <p class="muted" style="font-size:.85rem;margin-top:0">
        Elige al menos 2: por ejemplo certificación + curso, o certificación + trámite CENNI/CONOCER.
        El alumno podrá activar este combo desde cualquiera de estos productos.
    </p>
    <div style="max-height:360px;overflow:auto;border:1px solid #e6ebf2;border-radius:12px;padding:.5rem .75rem">
        <?php foreach ($products as $p): ?>
            <?php $pid = (int) $p['id']; ?>
            <label style="display:flex;gap:.6rem;align-items:flex-start;padding:.35rem 0;border-bottom:1px solid #f0f3f8;font-size:.9rem">
                <input type="checkbox" name="product_ids[]" value="<?= $pid ?>"
                    <?= in_array($pid, $selectedIds, true) ? 'checked' : '' ?>
                    style="margin-top:.25rem">
                <span>
                    <strong><?= e((string) $p['name']) ?></strong>
                    <span class="muted"> · <?= e($typeLabels[(string) $p['type']] ?? (string) $p['type']) ?>
                        · <code><?= e((string) $p['code']) ?></code>
                        · <?= money($p['public_price']) ?></span>
                </span>
            </label>
        <?php endforeach; ?>
        <?php if ($products === []): ?>
            <p class="muted">No hay productos activos. Crea productos primero.</p>
        <?php endif; ?>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar combo' : 'Crear combo' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/combos')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($isEdit): ?>
<form method="post" action="<?= e(url('/admin/combos/' . (int) $combo['id'] . '/eliminar')) ?>"
      onsubmit="return confirm('¿Eliminar este combo? Solo si no tiene compras.');"
      style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit" style="color:#b42318">Eliminar combo</button>
</form>
<?php endif; ?>
