<?php $layout = 'auth'; ?>
<div class="login-wrap">
    <div class="login-card">
        <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="">
        <h1 style="text-align:center;color:var(--doceo-blue);font-size:1.25rem">Restablecer contraseña</h1>
        <p class="muted" style="text-align:center">Te enviaremos un enlace a tu correo si la cuenta existe.</p>
        <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
        <form class="form" method="post" action="<?= e(url('/recuperar')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Correo de tu cuenta</label>
                <input id="email" type="email" name="email" required autocomplete="username"
                       value="<?= e((string) old('email', '')) ?>">
            </div>
            <button class="btn btn-primary" style="width:100%" type="submit">Enviar enlace</button>
        </form>
        <p style="text-align:center;margin:1rem 0 0">
            <a href="<?= e(url('/login')) ?>">Volver al login</a>
        </p>
    </div>
</div>
