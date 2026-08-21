<?php /** @var array<string,mixed> $product */ ?>
<article class="panel" style="margin:1.25rem 0 2rem">
    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start">
        <div style="width:min(180px,100%);background:#f4f7fb;border-radius:16px;padding:1rem;text-align:center">
            <img src="<?= e(asset(!empty($product['logo_path']) ? $product['logo_path'] : '/assets/brand/logo.png')) ?>" alt="" style="max-height:120px">
        </div>
        <div style="flex:1;min-width:240px">
            <div class="meta"><?= e($product['certifier_name'] ?? '') ?> · <?= e(category_label((string) $product['category'])) ?></div>
            <h1 style="margin:.25rem 0 .5rem;color:var(--doceo-blue)"><?= e($product['name']) ?></h1>
            <?php if (!empty($product['short_description'])): ?>
                <p class="muted"><?= e($product['short_description']) ?></p>
            <?php endif; ?>
            <p class="price" style="font-size:1.6rem;margin:.5rem 0"><?= money($product['catalog_price']) ?></p>
            <p class="muted" style="font-size:.85rem">Precio de lista en catálogo. Al adquirir podrás aplicar un código promocional o de partner.</p>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem">
                <a class="btn btn-accent" href="<?= e(url('/login')) ?>">Adquirir</a>
                <a class="btn btn-ghost" href="<?= e(url('/catalogo')) ?>">Volver al catálogo</a>
            </div>
        </div>
    </div>
    <?php if (!empty($product['description'])): ?>
        <hr style="border:0;border-top:1px solid #e6ebf2;margin:1.25rem 0">
        <h2 style="color:var(--doceo-blue);font-size:1.05rem">Descripción</h2>
        <div><?= nl2br(e($product['description'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($product['benefits_html'])): ?>
        <h2 style="color:var(--doceo-blue);font-size:1.05rem;margin-top:1rem">Beneficios</h2>
        <div><?= $product['benefits_html'] ?></div>
    <?php endif; ?>
</article>
