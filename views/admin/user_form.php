<?php
/** @var array<string,mixed>|null $userRow */
/** @var bool $isSelf */
$userRow = $userRow ?? null;
$isEdit = $userRow !== null;
$isSelf = !empty($isSelf);
$action = $isEdit
    ? url('/admin/usuarios/' . (int) $userRow['id'])
    : url('/admin/usuarios/nuevo');
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
?>
<p class="meta"><a href="<?= e(url('/admin/usuarios')) ?>">← Usuarios admin</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar admin' : 'Nuevo admin' ?>
</h1>
<p class="muted">
    <?= $isEdit
        ? 'Actualiza datos de acceso, activa/desactiva la cuenta o restablece la contraseña.'
        : 'Se crea una cuenta con rol admin para entrar al panel.' ?>
</p>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:640px">
    <?= csrf_field() ?>

    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos personales</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Nombre(s) *
            <input type="text" name="first_name" required
                   value="<?= e((string) ($userRow['first_name'] ?? '')) ?>"
                   style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Apellido paterno *
            <input type="text" name="last_name_p" required
                   value="<?= e((string) ($userRow['last_name_p'] ?? '')) ?>"
                   style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Apellido materno
            <input type="text" name="last_name_m"
                   value="<?= e((string) ($userRow['last_name_m'] ?? '')) ?>"
                   style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Teléfono
            <input type="tel" name="phone"
                   value="<?= e((string) ($userRow['phone'] ?? '')) ?>"
                   style="<?= e($inputStyle) ?>">
        </label>
    </div>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin-top:1.25rem">Acceso</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Correo *
            <input type="email" name="email" required
                   value="<?= e((string) ($userRow['email'] ?? '')) ?>"
                   style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Contraseña<?= $isEdit ? '' : ' (opcional)' ?>
            <input type="text" name="password" minlength="8" autocomplete="new-password"
                   placeholder="<?= $isEdit ? 'Dejar vacío para no cambiar' : 'Vacío = contraseña por defecto' ?>"
                   style="<?= e($inputStyle) ?>">
        </label>
    </div>

    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:1rem">
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
            <input type="checkbox" name="is_active" value="1"
                <?= (!$isEdit || (int) ($userRow['is_active'] ?? 1) === 1) ? 'checked' : '' ?>
                <?= $isSelf ? 'disabled' : '' ?>>
            Usuario activo
        </label>
        <?php if ($isSelf): ?>
            <input type="hidden" name="is_active" value="1">
        <?php endif; ?>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
            <input type="checkbox" name="must_change_password" value="1"
                <?= (!$isEdit || (int) ($userRow['must_change_password'] ?? 0) === 1) ? 'checked' : '' ?>>
            Debe cambiar contraseña al entrar
        </label>
    </div>

    <?php if ($isSelf): ?>
        <p class="muted" style="font-size:.85rem;margin-top:.75rem">
            Estás editando tu propia cuenta: no puedes desactivarla desde aquí.
        </p>
    <?php elseif (!$isEdit): ?>
        <p class="muted" style="font-size:.85rem;margin-top:.75rem">
            Si dejas la contraseña vacía se usa la por defecto del sistema.
            Tras guardar verás la contraseña en pantalla para compartirla (no se envía por correo).
        </p>
    <?php endif; ?>

    <?php if ($isEdit && !empty($userRow['last_login_at'])): ?>
        <p class="muted" style="font-size:.85rem;margin-top:.5rem">
            Último acceso: <strong><?= e((string) $userRow['last_login_at']) ?></strong>
        </p>
    <?php endif; ?>

    <div style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Crear admin' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/usuarios')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($isEdit): ?>
<form method="post" action="<?= e(url('/admin/usuarios/' . (int) $userRow['id'] . '/reset-password')) ?>"
      class="panel" style="margin-top:1rem;max-width:640px"
      onsubmit="return confirm('Se generará una nueva contraseña temporal. ¿Continuar?');">
    <?= csrf_field() ?>
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Restablecer contraseña</h2>
    <p class="muted" style="margin-top:0">
        Genera una contraseña temporal (o usa la que indiques) y la muestra en pantalla
        para compartirla con <strong><?= e((string) $userRow['email']) ?></strong>. No se envía por correo.
    </p>
    <label class="muted" style="<?= e($labelStyle) ?>;max-width:280px">
        Contraseña temporal (opcional)
        <input type="text" name="password" minlength="8" autocomplete="new-password"
               placeholder="Vacío = por defecto del sistema"
               style="<?= e($inputStyle) ?>">
    </label>
    <div style="margin-top:1rem">
        <button class="btn btn-primary" type="submit">Generar contraseña</button>
    </div>
</form>
<?php endif; ?>
