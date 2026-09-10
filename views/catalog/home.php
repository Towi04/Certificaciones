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
/** @var bool $dbOk */
$filter = is_string($filter ?? null) ? $filter : 'all';
$q = is_string($q ?? null) ? $q : '';
$totalShown = $pagination['total'] ?? count($products);
$qsExtra = ($q !== '' ? '&q=' . urlencode($q) : '');
require __DIR__ . '/_card_styles.php';
?>
<section class="hero">
    <div class="hero-banner">
        <h1>Catálogo de certificaciones</h1>
        <p>Elige tu certificación, curso o trámite. Revisa fechas, beneficios y adquiere en línea con seguimiento paso a paso.</p>
    </div>
</section>

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
               href="<?= e(url('/catalogo?filtro=all' . $qsExtra)) ?>">
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
                   href="<?= e(url('/catalogo?filtro=' . urlencode((string) $f['slug']) . $qsExtra)) ?>">
                    <?= e($f['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>
    <section>
        <div class="toolbar">
            <form class="search" method="get" action="<?= e(url('/catalogo')) ?>">
                <input type="hidden" name="filtro" value="<?= e($filter) ?>">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar certificación, proveedor…">
                <button class="btn btn-primary" type="submit">Buscar</button>
            </form>
            <div class="muted"><?= (int) $totalShown ?> producto<?= (int) $totalShown === 1 ? '' : 's' ?></div>
        </div>
        <?php if ($products === []): ?>
            <div class="empty">Aún no hay productos públicos. El admin puede cargarlos en <strong>Admin → Productos</strong>.</div>
        <?php else: ?>
            <?php
            $remaining = 0;
            $hasMorePages = false;
            if ($pagination !== null && (string) ($pagination['per_page_param'] ?? '') !== 'all') {
                $remaining = max(0, (int) $pagination['total'] - ((int) $pagination['offset'] + count($products)));
                $hasMorePages = $remaining > 0 || (int) $pagination['page'] < (int) $pagination['total_pages'];
            }
            $seeMoreQs = http_build_query(array_filter([
                'filtro' => $filter !== 'all' ? $filter : null,
                'q' => $q !== '' ? $q : null,
                'per_page' => 'all',
            ], static fn ($v) => $v !== null && $v !== ''));
            ?>
            <div class="product-grid">
                <?php foreach ($products as $p): ?>
                    <?php require __DIR__ . '/_card.php'; ?>
                <?php endforeach; ?>
                <?php if ($hasMorePages): ?>
                    <a class="product-card product-card-link product-card-more"
                       href="<?= e(url('/catalogo' . ($seeMoreQs !== '' ? '?' . $seeMoreQs : '?per_page=all'))) ?>">
                        <div class="body">
                            <div class="product-card-more-icon" aria-hidden="true">＋</div>
                            <h3>Ver más certificaciones</h3>
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
            <?php if ($pagination !== null): ?>
                <?php
                $basePath = '/catalogo';
                $paginationPerPageOptions = $paginationPerPageOptions ?? ['all' => 'Todas', '20' => '20', '40' => '40'];
                require BASE_PATH . '/views/shared/pagination.php';
                ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
