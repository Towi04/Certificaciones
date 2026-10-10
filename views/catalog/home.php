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
/** @var string $sort */
/** @var array<string,string> $sortOptions */
/** @var array{certificaciones:int,cursos:int,combos?:int} $sectionCounts */
/** @var bool $dbOk */
$filter = is_string($filter ?? null) ? $filter : 'all';
$q = is_string($q ?? null) ? $q : '';
$sort = is_string($sort ?? null) ? $sort : 'relevantes';
$sortOptions = is_array($sortOptions ?? null) ? $sortOptions : \App\Repositories\ProductRepository::catalogSortOptions();
if (!isset($sortOptions[$sort])) {
    $sort = 'relevantes';
}
$section = \App\Repositories\ProductRepository::normalizeCatalogSection(
    is_string($section ?? null) ? $section : 'certificaciones'
);
if ($section === 'all') {
    $section = 'certificaciones';
}
$sectionCounts = is_array($sectionCounts ?? null)
    ? $sectionCounts
    : ['certificaciones' => 0, 'cursos' => 0, 'combos' => 0];
$totalShown = $pagination['total'] ?? count($products);
$qsSection = 'seccion=' . urlencode($section);
$qsExtra = $qsSection
    . ($q !== '' ? '&q=' . urlencode($q) : '')
    . ($sort !== 'relevantes' ? '&orden=' . urlencode($sort) : '');
$isCourses = $section === 'cursos';
$isCombos = $section === 'combos';
$heroTitle = match (true) {
    $isCombos => 'Catálogo de combos',
    $isCourses => 'Catálogo de cursos',
    default => 'Catálogo de certificaciones',
};
$heroLead = match (true) {
    $isCombos => 'Paquetes con precio preferencial: certificación, preparación y trámites en una sola compra.',
    $isCourses => 'Explora los cursos que ofrecemos (propios o de terceros). Revisa fechas, beneficios y adquiere en línea.',
    default => 'Elige tu certificación o trámite (propios o de terceros). Revisa fechas, beneficios y adquiere en línea con seguimiento paso a paso.',
};
$emptyMsg = match (true) {
    $isCombos => 'Aún no hay combos públicos. El admin puede crearlos en <strong>Admin → Combos</strong> y marcarlos visibles en catálogo.',
    $isCourses => 'Aún no hay cursos públicos en esta sección. El admin puede cargarlos en <strong>Admin → Productos</strong> (tipo curso).',
    default => 'Aún no hay certificaciones públicas en esta sección. El admin puede cargarlas en <strong>Admin → Productos</strong>.',
};
$seeMoreLabel = match (true) {
    $isCombos => 'Ver más combos',
    $isCourses => 'Ver más cursos',
    default => 'Ver más certificaciones',
};
$searchPlaceholder = match (true) {
    $isCombos => 'Buscar combo, paquete…',
    $isCourses => 'Buscar curso, proveedor…',
    default => 'Buscar certificación, proveedor…',
};
$resultNoun = $isCombos ? 'combo' : 'producto';
require __DIR__ . '/_card_styles.php';
/** @var array<string,mixed>|null $referralPartner */
$referralPartner = is_array($referralPartner ?? null) ? $referralPartner : null;
?>
<section class="hero">
    <div class="hero-banner">
        <h1><?= e($heroTitle) ?></h1>
        <p><?= e($heroLead) ?></p>
    </div>
</section>

<?php if ($referralPartner && ($user['role'] ?? '') !== 'partner'): ?>
    <?php
    $refCode = strtoupper((string) ($referralPartner['code'] ?? ''));
    $refName = (string) ($referralPartner['display_name'] ?? $refCode);
    $refUrl = rtrim((string) url('/catalogo'), '/') . '?partner=' . rawurlencode($refCode);
    ?>
    <div class="flash flash-info" style="margin:1rem 0;display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;justify-content:space-between">
        <div>
            Estás usando el código de <strong><?= e($refName) ?></strong>
            (<code><?= e($refCode) ?></code>). Se aplicará al adquirir un producto.
        </div>
        <button type="button" class="btn btn-ghost btn-sm" id="catalog-copy-partner-link"
                data-url="<?= e($refUrl) ?>">Copiar link</button>
    </div>
    <script>
    (function () {
      var btn = document.getElementById('catalog-copy-partner-link');
      if (!btn) return;
      btn.addEventListener('click', function () {
        var u = btn.getAttribute('data-url') || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(u);
        }
        btn.textContent = 'Copiado';
        setTimeout(function () { btn.textContent = 'Copiar link'; }, 1500);
      });
    })();
    </script>
<?php endif; ?>

<nav class="catalog-section-tabs" role="tablist" aria-label="Secciones del catálogo">
    <?php
    $tabQs = static function (string $sec) use ($q, $sort, $filter): string {
        return http_build_query(array_filter([
            'seccion' => $sec,
            'q' => $q !== '' ? $q : null,
            'orden' => $sort !== 'relevantes' ? $sort : null,
            'filtro' => ($filter !== 'all' && $sec !== 'combos') ? $filter : null,
        ], static fn ($v) => $v !== null && $v !== ''));
    };
    ?>
    <a class="catalog-section-tab <?= (!$isCourses && !$isCombos) ? 'active' : '' ?>"
       role="tab"
       aria-selected="<?= (!$isCourses && !$isCombos) ? 'true' : 'false' ?>"
       href="<?= e(url('/catalogo?' . $tabQs('certificaciones'))) ?>">
        Certificaciones
        <span class="catalog-section-count"><?= (int) ($sectionCounts['certificaciones'] ?? 0) ?></span>
    </a>
    <a class="catalog-section-tab <?= $isCourses ? 'active' : '' ?>"
       role="tab"
       aria-selected="<?= $isCourses ? 'true' : 'false' ?>"
       href="<?= e(url('/catalogo?' . $tabQs('cursos'))) ?>">
        Cursos
        <span class="catalog-section-count"><?= (int) ($sectionCounts['cursos'] ?? 0) ?></span>
    </a>
    <a class="catalog-section-tab <?= $isCombos ? 'active' : '' ?>"
       role="tab"
       aria-selected="<?= $isCombos ? 'true' : 'false' ?>"
       href="<?= e(url('/catalogo?' . $tabQs('combos'))) ?>">
        Combos
        <span class="catalog-section-count"><?= (int) ($sectionCounts['combos'] ?? 0) ?></span>
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
                <?php if ($sort !== 'relevantes'): ?>
                    <input type="hidden" name="orden" value="<?= e($sort) ?>">
                <?php endif; ?>
                <?php if (($pagination['per_page_param'] ?? 'all') !== 'all'): ?>
                    <input type="hidden" name="per_page" value="<?= e((string) ($pagination['per_page_param'] ?? 'all')) ?>">
                <?php endif; ?>
                <input type="search" name="q" id="catalog-search-input" value="<?= e($q) ?>"
                       placeholder="<?= e($searchPlaceholder) ?>"
                       autocomplete="off">
                <button class="btn btn-primary" type="submit">Buscar</button>
            </form>
            <form class="catalog-sort" method="get" action="<?= e(url('/catalogo')) ?>" id="catalog-sort-form">
                <input type="hidden" name="seccion" value="<?= e($section) ?>">
                <input type="hidden" name="filtro" value="<?= e($filter) ?>">
                <?php if ($q !== ''): ?>
                    <input type="hidden" name="q" value="<?= e($q) ?>">
                <?php endif; ?>
                <?php if (($pagination['per_page_param'] ?? 'all') !== 'all'): ?>
                    <input type="hidden" name="per_page" value="<?= e((string) ($pagination['per_page_param'] ?? 'all')) ?>">
                <?php endif; ?>
                <label class="catalog-sort-label" for="catalog-sort-select">Ordenar</label>
                <select name="orden" id="catalog-sort-select" onchange="this.form.submit()">
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="muted" id="catalog-result-count"><?= (int) $totalShown ?> <?= e($resultNoun) ?><?= (int) $totalShown === 1 ? '' : 's' ?></div>
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
                'orden' => $sort !== 'relevantes' ? $sort : null,
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

<?php
/** @var list<array<string,mixed>> $distributors */
$distributors = is_array($distributors ?? null) ? $distributors : [];
?>
<?php if ($distributors !== []): ?>
<section class="panel" style="margin:1.5rem 0 2rem" id="distribuidores-autorizados">
    <h2 style="margin:0 0 .35rem;color:var(--doceo-blue)">Distribuidores autorizados</h2>
    <p class="muted" style="margin:0 0 1rem;font-size:.9rem">
        Escuelas y partners autorizados por Instituto DOCEO.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem">
        <?php foreach ($distributors as $d): ?>
            <article style="border:1px solid #e6ebf2;border-radius:14px;padding:1rem;background:#fff;display:flex;flex-direction:column;gap:.55rem">
                <div style="display:flex;gap:.75rem;align-items:center">
                    <div style="width:56px;height:56px;border-radius:12px;border:1px solid #e6ebf2;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f8fafc;flex:0 0 auto">
                        <?php if (!empty($d['logo_path'])): ?>
                            <img src="<?= e(asset((string) $d['logo_path'])) ?>" alt=""
                                 style="max-width:100%;max-height:100%;object-fit:contain">
                        <?php else: ?>
                            <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt=""
                                 style="max-width:70%;max-height:70%;object-fit:contain;opacity:.7">
                        <?php endif; ?>
                    </div>
                    <strong style="color:var(--doceo-blue)"><?= e((string) ($d['display_name'] ?? '')) ?></strong>
                </div>
                <?php if (!empty($d['description'])): ?>
                    <p style="margin:0;font-size:.88rem;line-height:1.4"><?= e((string) $d['description']) ?></p>
                <?php endif; ?>
                <?php if (!empty($d['phone'])): ?>
                    <p style="margin:0;font-size:.88rem">
                        Tel:
                        <a href="tel:<?= e(preg_replace('/\s+/', '', (string) $d['phone']) ?? '') ?>">
                            <?= e((string) $d['phone']) ?>
                        </a>
                    </p>
                <?php endif; ?>
                <?php if (!empty($d['address'])): ?>
                    <p class="muted" style="margin:0;font-size:.82rem"><?= e((string) $d['address']) ?></p>
                <?php endif; ?>
                <?php if (!empty($d['maps_url'])): ?>
                    <p style="margin:0">
                        <a class="btn btn-ghost btn-sm" href="<?= e((string) $d['maps_url']) ?>" target="_blank" rel="noopener">
                            Ver en Maps
                        </a>
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
