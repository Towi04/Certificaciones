<?php
/** @var string $contentFile */
$user = $user ?? \App\Auth\Auth::user();
$title = $title ?? app_name();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(app_name()) ?></title>
    <link rel="icon" href="<?= e(asset('/assets/brand/favicon.ico')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="container site-header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="<?= e(app_name()) ?>">
            <div>
                <strong><?= e(app_name()) ?></strong>
                <span>Certificaciones · Cursos · Trámites</span>
            </div>
        </a>
        <nav class="nav">
            <a href="<?= e(url('/catalogo')) ?>">Catálogo</a>
            <?php if ($user): ?>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="<?= e(url('/admin')) ?>">Admin</a>
                <?php elseif ($user['role'] === 'partner'): ?>
                    <a href="<?= e(url('/partner')) ?>">Partner</a>
                <?php else: ?>
                    <a href="<?= e(url('/alumno')) ?>">Mi panel</a>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/logout')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <button class="btn btn-ghost btn-sm" type="submit">Salir</button>
                </form>
            <?php else: ?>
                <a class="btn btn-primary btn-sm" href="<?= e(url('/login')) ?>">Iniciar sesión</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
    <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('info')): ?><div class="flash flash-info"><?= e($msg) ?></div><?php endif; ?>
    <?php require $contentFile; ?>
</main>
<footer class="site-footer">
    <div class="container">🐝 <?= e(app_name()) ?> · Be different, be better</div>
</footer>
</body>
</html>
