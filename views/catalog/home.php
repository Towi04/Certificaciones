<?php
/** @var array<string,mixed>|null $partner */
$partner = $partner ?? null;
$user = $user ?? null;
/** @var list<array<string,mixed>> $stars */
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $catalogFilters */
/** @var array{page:int,per_page:int,per_page_param:string,total:int,total_pages:int,offset:int,limit:?int}|null $pagination */
/** @var array<string,string> $paginationPerPageOptions */
/** @var string $filter */
/** @var string $q */
/** @var string $section */
/** @var array{certificaciones:int,cursos:int} $sectionCounts */
/** @var bool $dbOk */
$filter = is_string($filter ?? null) ? $filter : 'all';
$q = is_string($q ?? null) ? $q : '';
$section = is_string($section ?? null) ? $section : 'certificaciones';
if ($section !== 'cursos') {
    $section = 'certificaciones';
}
$sectionCounts = is_array($sectionCounts ?? null) ? $sectionCounts : ['certificaciones' => 0, 'cursos' => 0];
$totalShown = $pagination['total'] ?? count($products);
$qsSection = 'seccion=' . urlencode($section);
$qsExtra = $qsSection . ($q !== '' ? '&q=' . urlencode($q) : '');
$isCourses = $section === 'cursos';
$heroTitle = $isCourses ? 'Catálogo de cursos' : 'Catálogo de certificaciones';
$heroLead = $isCourses
    ? 'Explora los cursos que ofrecemos (propios o de terceros). Revisa fechas, beneficios y adquiere en línea.'
    : 'Elige tu certificación o trámite (propios o de terceros). Revisa fechas, beneficios y adquiere en línea con seguimiento paso a paso.';
$emptyMsg = $isCourses
    ? 'Aún no hay cursos públicos en esta sección. El admin puede cargarlos en <strong>Admin → Productos</strong> (tipo curso).'
    : 'Aún no hay certificaciones públicas en esta sección. El admin puede cargarlas en <strong>Admin → Productos</strong>.';
$seeMoreLabel = $isCourses ? 'Ver más cursos' : 'Ver más certificaciones';
$searchPlaceholder = $isCourses
    ? 'Buscar curso, proveedor…'
    : 'Buscar certificación, proveedor…';
require __DIR__ . '/_card_styles.php';
?>
<section class="hero">
    <div class="hero-banner">
        <h1><?= e($heroTitle) ?></h1>
        <p><?= e($heroLead) ?></p>
    </div>
</section>

<nav class="catalog-section-tabs" role="tablist" aria-label="Secciones del catálogo">
    <?php
    $tabCertQs = http_build_query(array_filter([
        'seccion' => 'certificaciones',
        'q' => $q !== '' ? $q : null,
    ], static fn ($v) => $v !== null && $v !== ''));
    $tabCourseQs = http_build_query(array_filter([
        'seccion' => 'cursos',
        'q' => $q !== '' ? $q : null,
    ], static fn ($v) => $v !== null && $v !== ''));
    ?>
    <a class="catalog-section-tab <?= !$isCourses ? 'active' : '' ?>"
       role="tab"
       aria-selected="<?= !$isCourses ? 'true' : 'false' ?>"
       href="<?= e(url('/catalogo?' . $tabCertQs)) ?>">
        Certificaciones
        <span class="catalog-section-count"><?= (int) ($sectionCounts['certificaciones'] ?? 0) ?></span>
    </a>
    <a class="catalog-section-tab <?= $isCourses ? 'active' : '' ?>"
       role="tab"
       aria-selected="<?= $isCourses ? 'true' : 'false' ?>"
       href="<?= e(url('/catalogo?' . $tabCourseQs)) ?>">
        Cursos
        <span class="catalog-section-count"><?= (int) ($sectionCounts['cursos'] ?? 0) ?></span>
    </a>
</nav>

<?php if (!$dbOk): ?>
    <div class="flash flash-info">
        La base de datos aún no está instalada. En el servidor ejecuta
        <code>php bin/install.php</code> (crea tablas y el admin del .env).
    </div>
<?php endif; ?>

<?php if ($stars !== []): ?>
    <?php require __DIR__ . '/_star_carousel.php'; ?>
<?php endif; ?>

<div class="layout-catalog">
    <aside class="sidebar">
        <h3>Filtros</h3>
        <div class="filter-list">
            <a class="<?= $filter === 'all' ? 'active' : '' ?>"
               href="<?= e(url('/catalogo?filtro=all&' . $qsExtra)) ?>">
                Todos
            </a>
            <?php
            $lastGroup = null;
            foreach ($catalogFilters as $f):
                $group = trim((string) ($f['filter_group'] ?? ''));
                if ($group !== '' && $group !== $lastGroup):
                    $lastGroup = $group;
                    ?>
                    <div class="filter-group-label"><?= e($group) ?></div>
                <?php endif; ?>
                <a class="<?= $filter === $f['slug'] ? 'active' : '' ?>"
                   href="<?= e(url('/catalogo?filtro=' . urlencode((string) $f['slug']) . '&' . $qsExtra)) ?>">
                    <?= e($f['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>
    <section>
        <div class="toolbar">
            <form class="search" method="get" action="<?= e(url('/catalogo')) ?>" id="catalog-search-form">
                <input type="hidden" name="seccion" value="<?= e($section) ?>">
                <input type="hidden" name="filtro" value="<?= e($filter) ?>">
                <?php if (($pagination['per_page_param'] ?? 'all') !== 'all'): ?>
                    <input type="hidden" name="per_page" value="<?= e((string) ($pagination['per_page_param'] ?? 'all')) ?>">
                <?php endif; ?>
                <input type="search" name="q" id="catalog-search-input" value="<?= e($q) ?>"
                       placeholder="<?= e($searchPlaceholder) ?>"
                       autocomplete="off">
                <button class="btn btn-primary" type="submit">Buscar</button>
            </form>
            <div class="muted" id="catalog-result-count"><?= (int) $totalShown ?> producto<?= (int) $totalShown === 1 ? '' : 's' ?></div>
        </div>
        <?php if ($products === []): ?>
            <div class="empty" id="catalog-empty-server"><?= $emptyMsg ?></div>
        <?php else: ?>
            <?php
            $remaining = 0;
            $hasMorePages = false;
            if ($pagination !== null && (string) ($pagination['per_page_param'] ?? '') !== 'all') {
                $remaining = max(0, (int) $pagination['total'] - ((int) $pagination['offset'] + count($products)));
                $hasMorePages = $remaining > 0 || (int) $pagination['page'] < (int) $pagination['total_pages'];
            }
            $seeMoreQs = http_build_query(array_filter([
                'seccion' => $section,
                'filtro' => $filter !== 'all' ? $filter : null,
                'q' => $q !== '' ? $q : null,
                'per_page' => 'all',
            ], static fn ($v) => $v !== null && $v !== ''));
            $liveClientSide = $pagination === null
                || (string) ($pagination['per_page_param'] ?? 'all') === 'all'
                || (int) ($pagination['total_pages'] ?? 1) <= 1;
            ?>
            <div class="product-grid" id="catalog-product-grid">
                <?php foreach ($products as $p): ?>
                    <?php require __DIR__ . '/_card.php'; ?>
                <?php endforeach; ?>
                <?php if ($hasMorePages): ?>
                    <a class="product-card product-card-link product-card-more"
                       href="<?= e(url('/catalogo' . ($seeMoreQs !== '' ? '?' . $seeMoreQs : '?per_page=all'))) ?>"
                       data-catalog-more="1">
                        <div class="body">
                            <div class="product-card-more-icon" aria-hidden="true">＋</div>
                            <h3><?= e($seeMoreLabel) ?></h3>
                            <p class="meta">
                                <?php if ($remaining > 0): ?>
                                    Quedan <?= (int) $remaining ?> producto<?= $remaining === 1 ? '' : 's' ?> por mostrar
                                <?php else: ?>
                                    Ver el catálogo completo
                                <?php endif; ?>
                            </p>
                            <div class="actions">
                                <span class="btn btn-accent btn-sm">Ver todas</span>
                            </div>
                        </div>
                    </a>
                <?php endif; ?>
            </div>
            <div class="empty" id="catalog-empty-filter" hidden>No hay productos que coincidan con tu búsqueda.</div>
            <?php if ($pagination !== null): ?>
                <?php
                $basePath = '/catalogo';
                $paginationPerPageOptions = $paginationPerPageOptions ?? ['all' => 'Todas', '20' => '20', '40' => '40'];
                require BASE_PATH . '/views/shared/pagination.php';
                ?>
            <?php endif; ?>
            <script>
            (function () {
              var input = document.getElementById('catalog-search-input');
              var form = document.getElementById('catalog-search-form');
              var grid = document.getElementById('catalog-product-grid');
              var countEl = document.getElementById('catalog-result-count');
              var emptyEl = document.getElementById('catalog-empty-filter');
              if (!input || !form || !grid) return;

              var liveClient = <?= $liveClientSide ? 'true' : 'false' ?>;
              var totalServer = <?= (int) $totalShown ?>;
              var timer = null;

              function normalize(text) {
                return String(text || '')
                  .toLowerCase()
                  .normalize('NFD')
                  .replace(/[\u0300-\u036f]/g, '')
                  .trim();
              }

              function updateUrl(q) {
                try {
                  var url = new URL(window.location.href);
                  if (q) url.searchParams.set('q', q);
                  else url.searchParams.delete('q');
                  url.searchParams.delete('page');
                  history.replaceState({}, '', url.pathname + url.search + url.hash);
                } catch (e) {}
              }

              function filterClient(raw) {
                var q = normalize(raw);
                var tokens = q ? q.split(/\s+/).filter(Boolean) : [];
                var cards = grid.querySelectorAll('.product-card-link:not([data-catalog-more])');
                var more = grid.querySelector('[data-catalog-more]');
                var visible = 0;

                cards.forEach(function (card) {
                  var hay = normalize(card.getAttribute('data-search') || card.textContent || '');
                  var ok = tokens.every(function (t) { return hay.indexOf(t) !== -1; });
                  card.hidden = !ok;
                  if (ok) visible += 1;
                });

                if (more) more.hidden = tokens.length > 0;
                if (emptyEl) emptyEl.hidden = visible > 0;
                if (countEl) {
                  countEl.textContent = visible + ' producto' + (visible === 1 ? '' : 's')
                    + (tokens.length ? ' (filtrados)' : '');
                }
              }

              function onType() {
                var value = input.value;
                clearTimeout(timer);
                if (liveClient) {
                  filterClient(value);
                  timer = setTimeout(function () {
                    updateUrl(value.trim());
                  }, 200);
                  return;
                }
                // Con paginación: recargar en servidor tras una pausa breve.
                timer = setTimeout(function () {
                  form.requestSubmit ? form.requestSubmit() : form.submit();
                }, 350);
              }

              input.addEventListener('input', onType);
              input.addEventListener('search', onType); // limpia en algunos navegadores

              if (liveClient && input.value) {
                filterClient(input.value);
              } else if (countEl && !liveClient) {
                countEl.textContent = totalServer + ' producto' + (totalServer === 1 ? '' : 's');
              }
            })();
            </script>
        <?php endif; ?>
    </section>
</div>
