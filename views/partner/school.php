<?php
/** @var array<string,mixed> $partner */
/** @var array<string,mixed> $profile */
$draft = is_array($profile['draft'] ?? null) ? $profile['draft'] : [];
$published = is_array($profile['published'] ?? null) ? $profile['published'] : [];
$status = (string) ($profile['status'] ?? 'draft');
$statusLabel = match ($status) {
    'pending' => 'En revisión',
    'approved' => 'Aprobado / publicado',
    'rejected' => 'Rechazado',
    default => 'Borrador',
};
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;box-sizing:border-box';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center" data-tour="school-header">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Mi escuela / directorio</h1>
        <p class="muted" style="margin:.25rem 0 0">
            Publica tu escuela en «Distribuidores autorizados» del catálogo (tras aprobación de DOCEO).
        </p>
    </div>
    <span class="pill"><?= e($statusLabel) ?></span>
</div>

<?php if ($status === 'rejected' && !empty($profile['partner']['publish_note'])): ?>
    <div class="flash flash-error" style="margin-top:1rem">
        Rechazado: <?= e((string) $profile['partner']['publish_note']) ?>
    </div>
<?php endif; ?>

<?php if ($status === 'pending'): ?>
    <div class="flash flash-info" style="margin-top:1rem">
        Tu perfil está en revisión. Puedes seguir editando el borrador; los cambios públicos
        solo aparecen cuando DOCEO apruebe de nuevo.
    </div>
<?php endif; ?>

<?php if ($published !== []): ?>
    <div class="panel" style="margin-top:1rem" data-tour="school-published">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Versión publicada</h2>
        <p class="muted" style="margin-top:0;font-size:.82rem">Esto es lo que ve el público mientras aprueban cambios nuevos.</p>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start">
            <?php if (!empty($published['logo_path'])): ?>
                <img src="<?= e(asset((string) $published['logo_path'])) ?>" alt=""
                     style="width:72px;height:72px;object-fit:contain;border:1px solid #e6ebf2;border-radius:12px;background:#fff">
            <?php endif; ?>
            <div>
                <strong><?= e((string) ($published['display_name'] ?? '')) ?></strong><br>
                <?= e((string) ($published['description'] ?? '')) ?><br>
                <span class="muted"><?= e((string) ($published['phone'] ?? '')) ?>
                    · <?= e((string) ($published['address'] ?? '')) ?></span>
            </div>
        </div>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/partner/mi-escuela')) ?>" enctype="multipart/form-data"
      class="panel" style="margin-top:1rem;max-width:720px" data-tour="school-form">
    <?= csrf_field() ?>
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Borrador</h2>
    <div style="display:grid;gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre de la escuela *
            <input type="text" name="display_name" required style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($draft['display_name'] ?? $partner['display_name'] ?? '')) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Teléfono público *
            <input type="tel" name="phone" style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($draft['phone'] ?? '')) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Dirección *
            <input type="text" name="address" style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($draft['address'] ?? '')) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Link de Google Maps
            <input type="url" name="maps_url" placeholder="https://maps.google.com/…" style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($draft['maps_url'] ?? '')) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Descripción corta * (máx. 500)
            <textarea name="description" rows="3" maxlength="500" style="<?= e($inputStyle) ?>"><?= e((string) ($draft['description'] ?? '')) ?></textarea>
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Logo / foto
            <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp,image/*" style="<?= e($inputStyle) ?>">
            <?php if (!empty($draft['logo_path'])): ?>
                <span class="muted" style="font-size:.8rem;font-weight:500">
                    Actual:
                    <a href="<?= e(asset((string) $draft['logo_path'])) ?>" target="_blank" rel="noopener">ver</a>
                </span>
            <?php endif; ?>
        </label>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-ghost" type="submit" name="save_draft" value="1">Guardar borrador</button>
        <button class="btn btn-accent" type="submit" name="submit_review" value="1">Enviar a revisión</button>
    </div>
</form>
