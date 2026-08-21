<?php
/** @var string $contentFile */
$title = $title ?? 'Panel';
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
            <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="">
            <div><strong><?= e(app_name()) ?></strong><span>Portal alumno</span></div>
        </a>
        <nav class="nav">
            <a href="<?= e(url('/catalogo')) ?>">Catálogo</a>
            <a href="<?= e(url('/alumno')) ?>">Mis seguimientos</a>
            <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?>
                <button class="btn btn-ghost btn-sm" type="submit">Salir</button>
            </form>
        </nav>
    </div>
</header>
<main class="container" style="padding:1.25rem 0 3rem">
    <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
    <?php require $contentFile; ?>
</main>
</body>
</html>
