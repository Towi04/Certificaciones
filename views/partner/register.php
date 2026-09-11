<?php
/** @var array<string,mixed> $partner */
/** @var array<string,mixed>|null $user */
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $catalogFilters */
/** @var string $filter */
/** @var string $q */
/** @var string $section */
/** @var array{certificaciones:int,cursos:int} $sectionCounts */
$filter = is_string($filter ?? null) ? $filter : 'all';
$q = is_string($q ?? null) ? $q : '';
$section = is_string($section ?? null) ? $section : 'certificaciones';
if ($section !== 'cursos') {
    $section = 'certificaciones';
}
$catalogFilters = is_array($catalogFilters ?? null) ? $catalogFilters : [];
$sectionCounts = is_array($sectionCounts ?? null) ? $sectionCounts : ['certificaciones' => 0, 'cursos' => 0];
$user = $user ?? null;
$isCourses = $section === 'cursos';
$basePath = '/partner/registrar';
$qsSection = 'seccion=' . urlencode($section);
$qsExtra = $qsSection . ($q !== '' ? '&q=' . urlencode($q) : '');
$searchPlaceholder = $isCourses
    ? 'Buscar curso, proveedor…'
    : 'Buscar certificación, proveedor…';
$totalShown = count($products);
?>
<p class="meta"><a href="<?= e(url('/partner')) ?>">← Mis alumnos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">Registrar alumno</h1>
<p class="muted" style="max-width:48rem">
    Elige el producto (se muestra tu precio de nivel
    <strong><?= e(\App\Services\PartnerAdminService::tierLabel($partner['tier'] ?? null)) ?></strong>).
    Completarás el <strong>mismo proceso</strong> que un alumno cualquiera:
    datos obligatorios, reglamento (firma digital en pantalla o PDF escaneado),
    agenda, combos/paquetes y opciones de pago.
    Al terminar seguirás en tu portal con el caso y su progreso.
</p>

<nav class="catalog-section-tabs" role="tablist" aria-label="Secciones del catálogo">
    <?php
    $tabCertQs = http_build_query(array_filter([
        'seccion' => 'certificaciones',
        'q' => $q !== '' ? $q : null,
        'filtro' => ($filter !== 'all' && $section === 'certificaciones') ? $filter : null,
    ], static fn ($v) => $v !== null && $v !== ''));
    $tabCourseQs = http_build_query(array_filter([
        'seccion' => 'cursos',
        'q' => $q !== '' ? $q : null,
        'filtro' => ($filter !== 'all' && $section === 'cursos') ? $filter : null,
    ], static fn ($v) => $v !== null && $v !== ''));
    ?>
    <a class="catalog-section-tab <?= !$isCourses ? 'active' : '' ?>"
       role="tab"
       aria-selected="<?= !$isCourses ? 'true' : 'false' ?>"
       href="<?= e(url($basePath . '?' . $tabCertQs)) ?>">
        Certificaciones
        <span class="catalog-section-count"><?= (int) ($sectionCounts['certificaciones'] ?? 0) ?></span>
    </a>
    <a class="catalog-section-tab <?= $isCourses ? 'active' : '' ?>"
       role="tab"
       aria-selected="<?= $isCourses ? 'true' : 'false' ?>"
       href="<?= e(url($basePath . '?' . $tabCourseQs)) ?>">
        Cursos
        <span class="catalog-section-count"><?= (int) ($sectionCounts['cursos'] ?? 0) ?></span>
    </a>
</nav>

<?php require BASE_PATH . '/views/catalog/_card_styles.php'; ?>

<div class="layout-catalog" style="margin-top:1rem">
    <aside class="sidebar">
        <h3>Filtros</h3>
        <div class="filter-list">
            <a class="<?= $filter === 'all' ? 'active' : '' ?>"
               href="<?= e(url($basePath . '?filtro=all&' . $qsExtra)) ?>">
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
                   href="<?= e(url($basePath . '?filtro=' . urlencode((string) $f['slug']) . '&' . $qsExtra)) ?>">
                    <?= e($f['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:1rem">
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/partner')) ?>">Cancelar</a>
        </div>
    </aside>
    <section>
        <div class="toolbar">
            <form class="search" method="get" action="<?= e(url($basePath)) ?>" id="partner-catalog-search-form">
                <input type="hidden" name="seccion" value="<?= e($section) ?>">
                <input type="hidden" name="filtro" value="<?= e($filter) ?>">
                <input type="search" name="q" id="partner-catalog-search-input" value="<?= e($q) ?>"
                       placeholder="<?= e($searchPlaceholder) ?>"
                       autocomplete="off">
                <button class="btn btn-primary" type="submit">Buscar</button>
            </form>
            <div class="muted" id="partner-catalog-result-count">
                <?= (int) $totalShown ?> producto<?= (int) $totalShown === 1 ? '' : 's' ?>
            </div>
        </div>

        <?php if ($products === []): ?>
            <div class="empty" id="partner-catalog-empty-server">
                No hay productos con esos filtros.
                <a href="<?= e(url($basePath . '?seccion=' . urlencode($section))) ?>">Ver todos</a>
            </div>
        <?php else: ?>
            <div class="product-grid" id="partner-catalog-product-grid">
                <?php foreach ($products as $p): ?>
                    <?php require BASE_PATH . '/views/catalog/_card.php'; ?>
                <?php endforeach; ?>
            </div>
            <div class="empty" id="partner-catalog-empty-filter" hidden>
                No hay productos que coincidan con tu búsqueda.
            </div>
            <script>
            (function () {
              var input = document.getElementById('partner-catalog-search-input');
              var form = document.getElementById('partner-catalog-search-form');
              var grid = document.getElementById('partner-catalog-product-grid');
              var countEl = document.getElementById('partner-catalog-result-count');
              var emptyEl = document.getElementById('partner-catalog-empty-filter');
              if (!input || !form || !grid) return;

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
                  history.replaceState({}, '', url.pathname + url.search + url.hash);
                } catch (e) {}
              }

              function filterClient(raw) {
                var q = normalize(raw);
                var tokens = q ? q.split(/\s+/).filter(Boolean) : [];
                var cards = grid.querySelectorAll('.product-card-link');
                var visible = 0;

                cards.forEach(function (card) {
                  var hay = normalize(card.getAttribute('data-search') || card.textContent || '');
                  var ok = tokens.every(function (t) { return hay.indexOf(t) !== -1; });
                  card.hidden = !ok;
                  if (ok) visible += 1;
                });

                if (emptyEl) emptyEl.hidden = visible > 0;
                if (countEl) {
                  countEl.textContent = visible + ' producto' + (visible === 1 ? '' : 's')
                    + (tokens.length ? ' (filtrados)' : '');
                }
              }

              function onType() {
                var value = input.value;
                clearTimeout(timer);
                filterClient(value);
                timer = setTimeout(function () {
                  updateUrl(value.trim());
                }, 200);
              }

              input.addEventListener('input', onType);
              input.addEventListener('search', onType);

              if (input.value) {
                filterClient(input.value);
              }
            })();
            </script>
        <?php endif; ?>
    </section>
</div>
