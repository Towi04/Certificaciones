<?php
$layout = 'auth';
/** @var bool $forced */
$forced = !empty($forced);
?>
<div class="login-wrap">
    <div class="login-card">
        <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="">
        <h1 style="text-align:center;color:var(--doceo-blue);font-size:1.25rem">Cambiar contraseña</h1>
        <?php if ($forced): ?>
            <p class="muted" style="text-align:center">
                Tu cuenta tiene una contraseña temporal. Elige una nueva para continuar.
            </p>
        <?php endif; ?>
        <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
        <form class="form" method="post" action="<?= e(url('/cuenta/cambiar-contrasena')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="current_password">Contraseña actual</label>
                <input id="current_password" type="password" name="current_password" required
                       autocomplete="current-password">
            </div>
            <div class="field">
                <label for="password">Nueva contraseña</label>
                <input id="password" type="password" name="password" required minlength="8"
                       autocomplete="new-password">
                <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">Mínimo 8 caracteres, sin espacios.</p>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirmar nueva contraseña</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8"
                       autocomplete="new-password">
            </div>
            <button class="btn btn-primary" style="width:100%" type="submit">Actualizar contraseña</button>
        </form>
        <div style="text-align:center;margin:1rem 0 0;display:flex;gap:.75rem;justify-content:center;align-items:center;flex-wrap:wrap">
            <?php if (!$forced): ?>
                <a href="<?= e(url('/')) ?>">Cancelar</a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/logout')) ?>" style="margin:0">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm">Cerrar sesión</button>
            </form>
        </div>
    </div>
</div>
