<?php
/** @var string $contentFile */
$user = \App\Auth\Auth::user();
$title = $title ?? 'Admin';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

$isOps = $path === '/admin' || str_starts_with($path, '/admin/operacion')
    || str_starts_with($path, '/admin/seguimientos')
    || str_starts_with($path, '/admin/compras')
    || str_starts_with($path, '/admin/pagos')
    || str_starts_with($path, '/admin/maestra')
    || str_starts_with($path, '/admin/documentos');

$configGroups = [
    [
        'label' => 'Catálogo',
        'items' => [
            ['href' => '/admin/productos', 'label' => 'Productos', 'icon' => 'products', 'match' => '/productos'],
            ['href' => '/admin/grupos', 'label' => 'Grupos', 'icon' => 'groups', 'match' => '/grupos'],
            ['href' => '/admin/combos', 'label' => 'Combos', 'icon' => 'combos', 'match' => '/combos'],
            ['href' => '/admin/precios', 'label' => 'Precios', 'icon' => 'prices', 'match' => '/precios'],
            ['href' => '/admin/filtros-catalogo', 'label' => 'Filtros', 'icon' => 'filters', 'match' => '/filtros-catalogo'],
        ],
    ],
    [
        'label' => 'Operadores',
        'items' => [
            ['href' => '/admin/partners', 'label' => 'Partners', 'icon' => 'partners', 'match' => '/partners'],
            ['href' => '/admin/proveedores', 'label' => 'Proveedores', 'icon' => 'suppliers', 'match' => '/proveedores'],
            ['href' => '/admin/certificadoras', 'label' => 'Certificadoras', 'icon' => 'certifiers', 'match' => '/certificadoras'],
        ],
    ],
    [
        'label' => 'Automatización',
        'items' => [
            ['href' => '/admin/correos', 'label' => 'Plantillas correo', 'icon' => 'mail', 'match' => '/correos'],
            ['href' => '/admin/vacaciones', 'label' => 'Vacaciones', 'icon' => 'calendar', 'match' => '/vacaciones'],
            ['href' => '/admin/promo', 'label' => 'Promo DOCEO', 'icon' => 'promo', 'match' => '/promo'],
            ['href' => '/admin/exportaciones', 'label' => 'UKS import/export', 'icon' => 'export', 'match' => '/exportaciones'],
            ['href' => '/admin/salud', 'label' => 'Salud', 'icon' => 'health', 'match' => '/salud'],
        ],
    ],
];

$configOpen = false;
foreach ($configGroups as $group) {
    foreach ($group['items'] as $item) {
        if (str_contains($path, $item['match'])) {
            $configOpen = true;
            break 2;
        }
    }
}
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
                    title="Ocultar menú" aria-label="Ocultar o mostrar el menú lateral" aria-expanded="true"
                    aria-controls="admin-side-nav">
                <?= icon('menu') ?>
            </button>
        </div>

        <nav class="side-nav-links" id="admin-side-nav-links">
            <a class="side-nav-link<?= $isOps ? ' active' : '' ?>"
               href="<?= e(url('/admin')) ?>"
               title="Operación">
                <span class="side-nav-icon"><?= icon('table') ?></span>
                <span class="side-nav-label">Operación</span>
            </a>

            <div class="side-nav-section<?= $configOpen ? ' is-open' : '' ?>" id="admin-config-section">
                <button type="button" class="side-nav-section-toggle" id="admin-config-toggle"
                        aria-expanded="<?= $configOpen ? 'true' : 'false' ?>"
                        aria-controls="admin-config-links">
                    <span class="side-nav-icon"><?= icon('groups') ?></span>
                    <span class="side-nav-label">Configuración</span>
                    <span class="side-nav-caret" aria-hidden="true">▾</span>
                </button>
                <div class="side-nav-section-links" id="admin-config-links"<?= $configOpen ? '' : ' hidden' ?>>
                    <?php foreach ($configGroups as $group): ?>
                        <div class="side-nav-group-label"><?= e($group['label']) ?></div>
                        <?php foreach ($group['items'] as $item): ?>
                            <?php $active = str_contains($path, $item['match']); ?>
                            <a class="side-nav-link side-nav-link--nested<?= $active ? ' active' : '' ?>"
                               href="<?= e(url($item['href'])) ?>"
                               title="<?= e($item['label']) ?>">
                                <span class="side-nav-icon"><?= icon($item['icon']) ?></span>
                                <span class="side-nav-label"><?= e($item['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <a class="side-nav-link" href="<?= e(url('/catalogo')) ?>" title="Ver catálogo">
                <span class="side-nav-icon"><?= icon('catalog') ?></span>
                <span class="side-nav-label">Ver catálogo</span>
            </a>
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
    shell.classList.toggle('app-shell--nav-collapsed', !!collapsed);
    document.documentElement.classList.toggle('admin-nav-collapsed-boot', !!collapsed);
    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    btn.title = collapsed ? 'Mostrar menú' : 'Ocultar menú';
    btn.setAttribute('aria-label', collapsed ? 'Mostrar el menú lateral' : 'Ocultar el menú lateral');
    try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
  }

  try {
    apply(localStorage.getItem(key) === '1');
  } catch (e) {
    apply(false);
  }

  btn.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    apply(!shell.classList.contains('app-shell--nav-collapsed'));
  });

  var cfgBtn = document.getElementById('admin-config-toggle');
  var cfgLinks = document.getElementById('admin-config-links');
  var cfgSection = document.getElementById('admin-config-section');
  if (cfgBtn && cfgLinks && cfgSection) {
    cfgBtn.addEventListener('click', function () {
      var open = cfgLinks.hasAttribute('hidden');
      if (open) {
        cfgLinks.removeAttribute('hidden');
        cfgSection.classList.add('is-open');
        cfgBtn.setAttribute('aria-expanded', 'true');
      } else {
        cfgLinks.setAttribute('hidden', '');
        cfgSection.classList.remove('is-open');
        cfgBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }
})();
</script>
</body>
</html>
