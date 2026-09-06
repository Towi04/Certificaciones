<?php
/** @var string $contentFile */
$user = \App\Auth\Auth::user();
$title = $title ?? 'Admin';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

$navItems = [
    ['href' => '/admin', 'label' => 'Dashboard', 'icon' => 'dashboard', 'active' => $path === '/admin'],
    ['href' => '/admin/maestra', 'label' => 'Tabla maestra', 'icon' => 'table', 'active' => str_contains($path, '/maestra')],
    ['href' => '/admin/pagos', 'label' => 'Pagos', 'icon' => 'payments', 'active' => str_contains($path, '/pagos') || str_contains($path, '/compras')],
    ['href' => '/admin/productos', 'label' => 'Productos', 'icon' => 'products', 'active' => str_contains($path, '/productos')],
    ['href' => '/admin/filtros-catalogo', 'label' => 'Filtros catálogo', 'icon' => 'filters', 'active' => str_contains($path, '/filtros-catalogo')],
    ['href' => '/admin/combos', 'label' => 'Combos', 'icon' => 'combos', 'active' => str_contains($path, '/combos')],
    ['href' => '/admin/precios', 'label' => 'Precios', 'icon' => 'prices', 'active' => str_contains($path, '/precios')],
    ['href' => '/admin/grupos', 'label' => 'Grupos', 'icon' => 'groups', 'active' => str_contains($path, '/grupos')],
    ['href' => '/admin/vacaciones', 'label' => 'Vacaciones', 'icon' => 'calendar', 'active' => str_contains($path, '/vacaciones')],
    ['href' => '/admin/promo', 'label' => 'Promo DOCEO', 'icon' => 'promo', 'active' => str_contains($path, '/promo')],
    ['href' => '/admin/correos', 'label' => 'Correos', 'icon' => 'mail', 'active' => str_contains($path, '/correos')],
    ['href' => '/admin/partners', 'label' => 'Partners', 'icon' => 'partners', 'active' => str_contains($path, '/partners')],
    ['href' => '/admin/proveedores', 'label' => 'Proveedores', 'icon' => 'suppliers', 'active' => str_contains($path, '/proveedores')],
    ['href' => '/admin/certificadoras', 'label' => 'Certificadoras', 'icon' => 'certifiers', 'active' => str_contains($path, '/certificadoras')],
    ['href' => '/admin/exportaciones', 'label' => 'UKS', 'icon' => 'export', 'active' => str_contains($path, '/exportaciones')],
    ['href' => '/admin/salud', 'label' => 'Salud', 'icon' => 'health', 'active' => str_contains($path, '/salud')],
    ['href' => '/catalogo', 'label' => 'Ver catálogo', 'icon' => 'catalog', 'active' => false],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Admin</title>
    <link rel="icon" href="<?= e(asset('/assets/brand/favicon.ico')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
    <script>
      try {
        if (localStorage.getItem('doceo-admin-nav-collapsed') === '1') {
          document.documentElement.classList.add('admin-nav-collapsed-boot');
        }
      } catch (e) {}
    </script>
</head>
<body>
<div class="app-shell" id="admin-shell">
    <aside class="side-nav" id="admin-side-nav" aria-label="Menú de administración">
        <div class="side-nav-top">
            <div class="brand-mini">
                <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="">
                <div class="brand-mini-text">
                    <strong>Admin DOCEO</strong><br>
                    <small><?= e($user['first_name'] ?? '') ?></small>
                </div>
            </div>
            <button type="button" class="nav-collapse-btn" id="admin-nav-toggle"
                    title="Ocultar menú" aria-label="Ocultar o mostrar textos del menú" aria-expanded="true">
                <?= icon('panel') ?>
            </button>
        </div>

        <nav class="side-nav-links">
            <?php foreach ($navItems as $item): ?>
                <a class="side-nav-link<?= !empty($item['active']) ? ' active' : '' ?>"
                   href="<?= e(url($item['href'])) ?>"
                   title="<?= e($item['label']) ?>">
                    <span class="side-nav-icon"><?= icon($item['icon']) ?></span>
                    <span class="side-nav-label"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="post" action="<?= e(url('/logout')) ?>" class="side-nav-logout">
            <?= csrf_field() ?>
            <button class="side-nav-link side-nav-logout-btn" type="submit" title="Cerrar sesión">
                <span class="side-nav-icon"><?= icon('logout') ?></span>
                <span class="side-nav-label">Cerrar sesión</span>
            </button>
        </form>
    </aside>
    <div class="app-main">
        <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('info')): ?><div class="flash flash-info"><?= e($msg) ?></div><?php endif; ?>
        <?php require $contentFile; ?>
    </div>
</div>
<script>
(function () {
  var shell = document.getElementById('admin-shell');
  var btn = document.getElementById('admin-nav-toggle');
  if (!shell || !btn) return;
  var key = 'doceo-admin-nav-collapsed';

  function apply(collapsed) {
    shell.classList.toggle('app-shell--nav-collapsed', collapsed);
    document.documentElement.classList.remove('admin-nav-collapsed-boot');
    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    btn.title = collapsed ? 'Mostrar menú' : 'Ocultar menú';
    try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
  }

  try {
    apply(localStorage.getItem(key) === '1');
  } catch (e) {
    apply(false);
  }

  btn.addEventListener('click', function () {
    apply(!shell.classList.contains('app-shell--nav-collapsed'));
  });
})();
</script>
</body>
</html>
