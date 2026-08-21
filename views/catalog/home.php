<?php
/** @var list<array<string,mixed>> $stars */
/** @var list<array<string,mixed>> $products */
/** @var string $category */
/** @var string $q */
/** @var bool $dbOk */
$categories = [
    'all' => 'Todos',
    'english_adult' => 'Inglés adultos',
    'english_kids' => 'Inglés menores',
    'it' => 'Informática',
    'teaching' => 'Enseñanza',
];
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
    <h2 style="color:var(--doceo-blue);margin:0 0 .75rem">⭐ Productos estrella</h2>
    <div class="product-grid" style="margin-bottom:1.5rem">
        <?php foreach ($stars as $p): ?>
            <?php require __DIR__ . '/_card.php'; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="layout-catalog">
    <aside class="sidebar">
        <h3>Filtros</h3>
        <div class="filter-list">
            <?php foreach ($categories as $key => $label): ?>
                <a class="<?= $category === $key ? 'active' : '' ?>"
                   href="<?= e(url('/catalogo?categoria=' . urlencode($key) . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>
    <section>
        <div class="toolbar">
            <form class="search" method="get" action="<?= e(url('/catalogo')) ?>">
                <input type="hidden" name="categoria" value="<?= e($category) ?>">
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
