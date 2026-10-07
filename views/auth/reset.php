<?php
$layout = 'auth';
/** @var string $token */
/** @var string $email */
?>
<div class="login-wrap">
    <div class="login-card">
        <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="">
        <h1 style="text-align:center;color:var(--doceo-blue);font-size:1.25rem">Nueva contraseña</h1>
        <p class="muted" style="text-align:center">Cuenta: <?= e($email) ?></p>
        <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
        <form class="form" method="post" action="<?= e(url('/recuperar/' . $token)) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="password">Nueva contraseña</label>
                <input id="password" type="password" name="password" required minlength="8"
                       autocomplete="new-password">
                <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">Mínimo 8 caracteres, sin espacios.</p>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirmar contraseña</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8"
                       autocomplete="new-password">
            </div>
            <button class="btn btn-primary" style="width:100%" type="submit">Guardar contraseña</button>
        </form>
        <p style="text-align:center;margin:1rem 0 0">
            <a href="<?= e(url('/login')) ?>">Volver al login</a>
        </p>
    </div>
</div>
