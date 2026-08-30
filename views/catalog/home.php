<?php
/** @var list<array<string,mixed>> $stars */
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $catalogFilters */
/** @var string $filter */
/** @var string $q */
/** @var bool $dbOk */
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
               href="<?= e(url('/catalogo?filtro=all' . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>">
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
                   href="<?= e(url('/catalogo?filtro=' . urlencode((string) $f['slug']) . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>">
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
            <div class="muted"><?= count($products) ?> productos</div>
        </div>
        <?php if ($products === []): ?>
            <div class="empty">Aún no hay productos públicos. El admin puede cargarlos en <strong>Admin → Productos</strong>.</div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $p): ?>
                    <?php require __DIR__ . '/_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
