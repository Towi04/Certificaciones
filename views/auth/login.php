<?php $layout = 'auth'; ?>
<div class="login-wrap">
    <div class="login-card">
        <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="<?= e(app_name()) ?>">
        <h1 style="text-align:center;color:var(--doceo-blue);font-size:1.35rem;margin:0 0 .25rem">Iniciar sesión</h1>
        <p class="muted" style="text-align:center;margin:0 0 1rem">Admin · Partner · Alumno</p>
        <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
        <form class="form" method="post" action="<?= e(url('/login')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Correo</label>
                <input id="email" type="email" name="email" required autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <button class="btn btn-primary" style="width:100%" type="submit">Entrar</button>
        </form>
        <p style="text-align:center;margin:1rem 0 0">
            <a href="<?= e(url('/recuperar')) ?>">¿Olvidaste tu contraseña?</a><br>
            <a href="<?= e(url('/')) ?>">← Volver al catálogo</a>
        </p>
    </div>
</div>
