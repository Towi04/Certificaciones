<?php /** @var array<string,mixed> $p */ ?>
<article class="product-card">
    <div class="thumb">
        <?php if (!empty($p['is_star'])): ?><span class="badge-star">Estrella</span><?php endif; ?>
        <?php if (!empty($p['logo_path'])): ?>
            <img src="<?= e(asset($p['logo_path'])) ?>" alt="">
        <?php else: ?>
            <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="" style="opacity:.55">
        <?php endif; ?>
    </div>
    <div class="body">
        <div class="meta"><?= e($p['certifier_name'] ?? category_label((string) $p['category'])) ?></div>
        <h3><?= e($p['name']) ?></h3>
        <?php if (!empty($p['short_description'])): ?>
            <div class="meta"><?= e($p['short_description']) ?></div>
        <?php endif; ?>
        <div class="price"><?= money($p['catalog_price'] ?? $p['public_price'] ?? 0) ?></div>
        <div class="actions">
            <a class="btn btn-primary btn-sm" href="<?= e(url('/producto/' . $p['slug'])) ?>">Ver más</a>
        </div>
    </div>
</article>
