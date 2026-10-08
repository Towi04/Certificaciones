<?php
/** @var array<string,mixed> $partner */
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;box-sizing:border-box';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
    <h1 style="margin:0;color:var(--doceo-blue)">Mi perfil</h1>
    <a class="btn btn-ghost" href="<?= e(url('/partner/alumnos')) ?>">← Alumnos</a>
</div>

<div class="stats" style="margin:1rem 0" data-tour="profile-readonly">
    <div class="stat">
        <div class="label">Nivel</div>
        <div class="value" style="font-size:1.1rem"><?= e(\App\Services\PartnerAdminService::tierLabel($partner['tier'] ?? null)) ?></div>
    </div>
    <div class="stat">
        <div class="label">Saldo a favor</div>
        <div class="value" style="font-size:1.1rem"><?= money($partner['credit_balance'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label">Correo de acceso</div>
        <div class="value" style="font-size:1rem"><?= e((string) ($partner['email'] ?? '')) ?></div>
    </div>
</div>

<form method="post" action="<?= e(url('/partner/perfil')) ?>" class="panel" style="max-width:640px" data-tour="profile-form">
    <?= csrf_field() ?>
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos comerciales</h2>
    <div style="display:grid;gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre comercial *
            <input type="text" name="display_name" required style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($partner['display_name'] ?? '')) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre de contacto *
            <input type="text" name="first_name" required style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($partner['first_name'] ?? '')) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Teléfono
            <input type="tel" name="phone" style="<?= e($inputStyle) ?>"
                   value="<?= e((string) ($partner['phone'] ?? '')) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Código de descuento *
            <input type="text" name="code" required maxlength="40" pattern="[A-Za-z0-9_-]{2,40}"
                   style="<?= e($inputStyle) ?>;text-transform:uppercase"
                   value="<?= e((string) ($partner['code'] ?? '')) ?>">
            <span class="muted" style="font-size:.78rem;font-weight:500">
                Debe ser único. Si otro partner o una promoción DOCEO ya lo usa, elige otro.
            </span>
        </label>
    </div>

    <h2 style="margin:1.25rem 0 .5rem;font-size:1.05rem;color:var(--doceo-blue)">Cambiar contraseña</h2>
    <p class="muted" style="margin-top:0;font-size:.82rem">Déjalo vacío si no quieres cambiarla.</p>
    <div style="display:grid;gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Contraseña actual
            <input type="password" name="current_password" autocomplete="current-password" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nueva contraseña
            <input type="password" name="password" autocomplete="new-password" minlength="8" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Confirmar nueva contraseña
            <input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" style="<?= e($inputStyle) ?>">
        </label>
    </div>

    <button class="btn btn-accent" type="submit" style="margin-top:1rem">Guardar cambios</button>
</form>
