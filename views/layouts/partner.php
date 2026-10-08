<?php
/** @var string $contentFile */
$user = \App\Auth\Auth::user();
$title = $title ?? 'Partner';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

$navItems = [
    ['href' => '/partner/alumnos', 'label' => 'Alumnos', 'icon' => 'partners', 'keys' => ['alumnos', 'home', 'caso']],
    ['href' => '/partner/registrar', 'label' => 'Registrar alumno', 'icon' => 'user', 'keys' => ['registrar']],
    ['href' => '/partner/registrar-grupo', 'label' => 'Registrar grupo', 'icon' => 'groups', 'keys' => ['registrar-grupo']],
    ['href' => '/catalogo', 'label' => 'Catálogo', 'icon' => 'catalog', 'keys' => ['catalogo']],
    ['href' => '/partner/calendario', 'label' => 'Calendario', 'icon' => 'calendar', 'keys' => ['calendario']],
    ['href' => '/partner/credito', 'label' => 'Crédito', 'icon' => 'promo', 'keys' => ['credito']],
    ['href' => '/partner/avance', 'label' => 'Avance / niveles', 'icon' => 'advance', 'keys' => ['avance']],
    ['href' => '/partner/perfil', 'label' => 'Mi perfil', 'icon' => 'edit', 'keys' => ['perfil']],
    ['href' => '/partner/mi-escuela', 'label' => 'Mi escuela', 'icon' => 'factory', 'keys' => ['mi-escuela']],
];

$activeKey = match (true) {
    $path === '/partner' || $path === '/partner/' || str_starts_with($path, '/partner/alumnos') || str_starts_with($path, '/partner/caso') => 'alumnos',
    str_starts_with($path, '/partner/registrar-grupo') => 'registrar-grupo',
    str_starts_with($path, '/partner/registrar') || str_starts_with($path, '/adquirir/') => 'registrar',
    str_starts_with($path, '/catalogo') => 'catalogo',
    str_starts_with($path, '/partner/calendario') => 'calendario',
    str_starts_with($path, '/partner/credito') => 'credito',
    str_starts_with($path, '/partner/avance') => 'avance',
    str_starts_with($path, '/partner/perfil') => 'perfil',
    str_starts_with($path, '/partner/mi-escuela') => 'mi-escuela',
    default => '',
};

$isActive = static function (array $item) use ($activeKey): bool {
    return in_array($activeKey, $item['keys'], true);
};

$partnerTourView = \App\Services\PartnerTutorialService::viewFromPath($path);
$partnerTutorial = ['views' => [], 'completed_all' => false];
try {
    $stmt = \App\Database\Connection::get()->prepare(
        'SELECT id FROM partners WHERE user_id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([(int) ($user['id'] ?? 0)]);
    $partnerId = (int) $stmt->fetchColumn();
    if ($partnerId > 0) {
        $partnerTutorial = (new \App\Services\PartnerTutorialService())->stateForPartner($partnerId);
    }
} catch (\Throwable) {
    // tutorial opcional
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Partner</title>
    <link rel="icon" href="<?= e(asset('/assets/brand/favicon.ico')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
    <script>
      try {
        if (localStorage.getItem('doceo-partner-nav-collapsed') === '1') {
          document.documentElement.classList.add('admin-nav-collapsed-boot');
        }
      } catch (e) {}
    </script>
</head>
<body>
<div class="app-shell" id="partner-shell">
    <aside class="side-nav" id="partner-side-nav" aria-label="Menú partner" data-tour="partner-nav">
        <div class="side-nav-top">
            <div class="brand-mini">
                <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="">
                <div class="brand-mini-text">
                    <strong>Portal Partner</strong><br>
                    <small><?= e($user['first_name'] ?? '') ?></small>
                </div>
            </div>
            <button type="button" class="nav-collapse-btn" id="partner-nav-toggle"
                    title="Ocultar menú" aria-label="Ocultar o mostrar el menú lateral" aria-expanded="true"
                    aria-controls="partner-side-nav">
                <?= icon('menu') ?>
            </button>
        </div>

        <nav class="side-nav-links" id="partner-side-nav-links">
            <?php foreach ($navItems as $item): ?>
                <a class="side-nav-link<?= $isActive($item) ? ' active' : '' ?>"
                   href="<?= e(url($item['href'])) ?>"
                   title="<?= e($item['label']) ?>"
                   data-tour="nav-<?= e(ltrim(str_replace('/', '-', $item['href']), '-')) ?>">
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
        <?php if ($msg = flash('warning')): ?><div class="flash flash-warning"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('info')): ?><div class="flash flash-info"><?= e($msg) ?></div><?php endif; ?>
        <?php require $contentFile; ?>
    </div>
</div>
<script>
(function () {
  var shell = document.getElementById('partner-shell');
  var btn = document.getElementById('partner-nav-toggle');
  var key = 'doceo-partner-nav-collapsed';

  function apply(collapsed) {
    if (!shell) return;
    shell.classList.toggle('app-shell--nav-collapsed', !!collapsed);
    document.documentElement.classList.toggle('admin-nav-collapsed-boot', !!collapsed);
    if (btn) {
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      btn.title = collapsed ? 'Mostrar menú' : 'Ocultar menú';
      btn.setAttribute('aria-label', collapsed ? 'Mostrar el menú lateral' : 'Ocultar el menú lateral');
    }
    try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
  }

  try {
    apply(localStorage.getItem(key) === '1');
  } catch (e) {
    apply(false);
  }

  if (btn && shell) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      apply(!shell.classList.contains('app-shell--nav-collapsed'));
    });
  }
})();
</script>
<?php if ($partnerTourView): ?>
<?php
    $tourJsVersion = @filemtime(BASE_PATH . '/views/partner/tour.js.php')
        ?: @filemtime(BASE_PATH . '/src/Services/PartnerTutorialService.php')
        ?: time();
?>
<script src="<?= e(url('/partner/tutorial.js')) ?>?v=<?= (int) $tourJsVersion ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.DoceoPartnerTour) return;
  window.DoceoPartnerTour.configure({
    completeUrl: <?= json_encode(url('/partner/tutorial/completo'), JSON_UNESCAPED_UNICODE) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    steps: <?= json_encode(\App\Services\PartnerTutorialService::tourSteps(), JSON_UNESCAPED_UNICODE) ?>
  });
  window.DoceoPartnerTour.maybeStart(
    <?= json_encode($partnerTourView, JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode($partnerTutorial, JSON_UNESCAPED_UNICODE) ?>
  );
});
</script>
<?php endif; ?>
</body>
</html>
