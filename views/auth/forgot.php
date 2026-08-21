<?php $layout = 'auth'; ?>
<div class="login-wrap">
    <div class="login-card">
        <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="">
        <h1 style="text-align:center;color:var(--doceo-blue);font-size:1.25rem">Restablecer contraseña</h1>
        <p class="muted">Pronto podrás solicitar un enlace a tu correo. Por ahora contacta a administración.</p>
        <a class="btn btn-primary" style="width:100%" href="<?= e(url('/login')) ?>">Volver al login</a>
    </div>
</div>
