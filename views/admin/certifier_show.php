<?php
/** @var array<string,mixed> $certifier */
/** @var list<array<string,mixed>> $products */
/** @var int $productCount */
$cid = (int) $certifier['id'];
$products = $products ?? [];
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
?>
<p class="meta"><a href="<?= e(url('/admin/certificadoras')) ?>">← Certificadoras</a></p>
<div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem">
    <div style="width:88px;height:88px;border:1px solid #e6ebf2;border-radius:16px;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:.5rem">
        <?php if (!empty($certifier['logo_path'])): ?>
            <img src="<?= e(asset((string) $certifier['logo_path'])) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
        <?php else: ?>
            <span class="muted" style="font-size:.75rem;text-align:center">Sin logo</span>
        <?php endif; ?>
    </div>
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)"><?= e((string) $certifier['name']) ?></h1>
        <p class="muted" style="margin:.35rem 0 0">
            Código <code><?= e((string) $certifier['code']) ?></code>
            · <?= !empty($certifier['is_active']) ? 'Activa' : 'Inactiva' ?>
            · <?= (int) $productCount ?> producto(s)
        </p>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/certificadoras/' . $cid)) ?>" class="panel" style="margin-top:1rem;max-width:860px">
    <?= csrf_field() ?>
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos de la certificadora</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre *
            <input type="text" name="name" required value="<?= e((string) $certifier['name']) ?>" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Código
            <input type="text" name="code" readonly value="<?= e((string) $certifier['code']) ?>"
                   style="<?= e($inputStyle) ?>;background:#f4f7fb">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Sitio web
            <input type="url" name="website" value="<?= e((string) ($certifier['website'] ?? '')) ?>"
                   placeholder="https://..." style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Plataforma / portal
            <input type="url" name="platform_url" value="<?= e((string) ($certifier['platform_url'] ?? '')) ?>"
                   placeholder="https://..." style="<?= e($inputStyle) ?>">
        </label>
    </div>
    <label class="muted" style="<?= e($labelStyle) ?>;margin-top:1rem">
        Notas internas
        <textarea name="notes" rows="3" style="<?= e($inputStyle) ?>"><?= e((string) ($certifier['notes'] ?? '')) ?></textarea>
    </label>
    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.85rem">
        <input type="checkbox" name="is_active" value="1" <?= !empty($certifier['is_active']) ? 'checked' : '' ?>>
        Certificadora activa
    </label>
    <div style="margin-top:1rem">
        <button class="btn btn-accent" type="submit">Guardar cambios</button>
    </div>
</form>

<form method="post" action="<?= e(url('/admin/certificadoras/' . $cid . '/logo')) ?>" enctype="multipart/form-data"
      class="panel" style="margin-top:1rem;max-width:860px">
    <?= csrf_field() ?>
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Logo de la certificadora</h2>
    <label class="muted" style="<?= e($labelStyle) ?>;max-width:360px">
        Imagen (JPG, PNG, WEBP, SVG · máx. 5 MB)
        <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp,.gif,.svg,image/*">
    </label>
    <?php if (!empty($certifier['logo_path'])): ?>
        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.75rem">
            <input type="checkbox" name="remove_logo" value="1">
            Quitar logo actual
        </label>
    <?php endif; ?>
    <button class="btn btn-ghost" type="submit" style="margin-top:.75rem">Actualizar logo</button>
</form>

<?php if (!empty($certifier['website']) || !empty($certifier['platform_url'])): ?>
<div class="panel" style="margin-top:1rem;max-width:860px">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Enlaces rápidos</h2>
    <ul style="margin:0;padding-left:1.1rem">
        <?php if (!empty($certifier['website'])): ?>
            <li><a href="<?= e((string) $certifier['website']) ?>" target="_blank" rel="noopener"><?= e((string) $certifier['website']) ?></a></li>
        <?php endif; ?>
        <?php if (!empty($certifier['platform_url'])): ?>
            <li><a href="<?= e((string) $certifier['platform_url']) ?>" target="_blank" rel="noopener"><?= e((string) $certifier['platform_url']) ?></a></li>
        <?php endif; ?>
    </ul>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem;max-width:960px">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Productos vinculados (<?= (int) $productCount ?>)</h2>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>SKU / código</th><th>Nombre</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><code><?= e((string) ($p['code'] ?? $p['sku'] ?? '')) ?></code></td>
                    <td><?= e((string) $p['name']) ?></td>
                    <td><a href="<?= e(url('/admin/productos/' . (int) $p['id'] . '/editar')) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr><td colspan="3" class="muted">Sin productos vinculados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/certificadoras/' . $cid . '/eliminar')) ?>"
      onsubmit="return confirm('¿Eliminar esta certificadora? Solo si no tiene productos.');"
      style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit" style="color:#b42318">Eliminar certificadora</button>
</form>
