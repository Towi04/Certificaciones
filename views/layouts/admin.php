<?php
/** @var string $contentFile */
$user = \App\Auth\Auth::user();
$title = $title ?? 'Admin';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Admin</title>
    <link rel="icon" href="<?= e(asset('/assets/brand/favicon.ico')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
</head>
<body>
<div class="app-shell">
    <aside class="side-nav">
        <div class="brand-mini">
            <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="">
            <div>
                <strong>Admin DOCEO</strong><br>
                <small><?= e($user['first_name'] ?? '') ?></small>
            </div>
        </div>
        <a class="<?= str_starts_with($path, '/admin') && $path === '/admin' ? 'active' : '' ?>" href="<?= e(url('/admin')) ?>">Dashboard</a>
        <a class="<?= str_contains($path, '/maestra') ? 'active' : '' ?>" href="<?= e(url('/admin/maestra')) ?>">Tabla maestra</a>
        <a class="<?= str_contains($path, '/pagos') || str_contains($path, '/compras') ? 'active' : '' ?>" href="<?= e(url('/admin/pagos')) ?>">Pagos</a>
        <a class="<?= str_contains($path, '/productos') ? 'active' : '' ?>" href="<?= e(url('/admin/productos')) ?>">Productos</a>
        <a class="<?= str_contains($path, '/combos') ? 'active' : '' ?>" href="<?= e(url('/admin/combos')) ?>">Combos</a>
        <a class="<?= str_contains($path, '/precios') ? 'active' : '' ?>" href="<?= e(url('/admin/precios')) ?>">Precios</a>
        <a class="<?= str_contains($path, '/grupos') ? 'active' : '' ?>" href="<?= e(url('/admin/grupos')) ?>">Grupos</a>
        <a class="<?= str_contains($path, '/vacaciones') ? 'active' : '' ?>" href="<?= e(url('/admin/vacaciones')) ?>">Vacaciones</a>
        <a class="<?= str_contains($path, '/promo') ? 'active' : '' ?>" href="<?= e(url('/admin/promo')) ?>">Promo DOCEO</a>
        <a class="<?= str_contains($path, '/correos') ? 'active' : '' ?>" href="<?= e(url('/admin/correos')) ?>">Correos</a>
        <a class="<?= str_contains($path, '/partners') ? 'active' : '' ?>" href="<?= e(url('/admin/partners')) ?>">Partners</a>
        <a class="<?= str_contains($path, '/proveedores') ? 'active' : '' ?>" href="<?= e(url('/admin/proveedores')) ?>">Proveedores</a>
        <a class="<?= str_contains($path, '/certificadoras') ? 'active' : '' ?>" href="<?= e(url('/admin/certificadoras')) ?>">Certificadoras</a>
        <a class="<?= str_contains($path, '/exportaciones') ? 'active' : '' ?>" href="<?= e(url('/admin/exportaciones')) ?>">UKS</a>
        <a class="<?= str_contains($path, '/salud') ? 'active' : '' ?>" href="<?= e(url('/admin/salud')) ?>">Salud</a>
        <a href="<?= e(url('/catalogo')) ?>">Ver catálogo</a>
        <form method="post" action="<?= e(url('/logout')) ?>" style="margin-top:1rem">
            <?= csrf_field() ?>
            <button class="btn btn-accent btn-sm" type="submit">Cerrar sesión</button>
        </form>
    </aside>
    <div class="app-main">
        <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('info')): ?><div class="flash flash-info"><?= e($msg) ?></div><?php endif; ?>
        <?php require $contentFile; ?>
    </div>
</div>
</body>
</html>
