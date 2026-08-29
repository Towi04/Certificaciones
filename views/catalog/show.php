<?php
/** @var array<string,mixed> $product */
/** @var list<array<string,mixed>> $media */
$media = $media ?? [];
?>
<article class="panel" style="margin:1.25rem 0 2rem">
    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start">
        <div style="width:min(180px,100%);background:#f4f7fb;border-radius:16px;padding:1rem;text-align:center">
            <img src="<?= e(asset(!empty($product['logo_path']) ? (string) $product['logo_path'] : '/assets/brand/logo.png')) ?>" alt="" style="max-height:120px;max-width:100%;object-fit:contain">
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
                <a class="btn btn-accent" href="<?= e(url('/adquirir/' . $product['slug'])) ?>">Adquirir</a>
                <a class="btn btn-ghost" href="<?= e(url('/catalogo')) ?>">Volver al catálogo</a>
            </div>
        </div>
    </div>

    <?php if (!empty($product['description']) || !empty($product['benefits_html']) || $media !== []): ?>
        <hr style="border:0;border-top:1px solid #e6ebf2;margin:1.25rem 0">
        <div class="product-detail-layout">
            <section>
                <?php if (!empty($product['description'])): ?>
                    <h2 style="color:var(--doceo-blue);font-size:1.05rem;margin-top:0">Descripción</h2>
                    <div><?= nl2br(e($product['description'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($product['benefits_html'])): ?>
                    <h2 style="color:var(--doceo-blue);font-size:1.05rem;margin-top:1rem">Beneficios</h2>
                    <div><?= $product['benefits_html'] ?></div>
                <?php endif; ?>
            </section>

            <?php if ($media !== []): ?>
                <aside class="product-media-gallery" aria-label="Multimedia del producto">
                    <h2>Galería</h2>
                    <p class="muted">Ejemplos de certificados, badges, CENNI o videos del examen.</p>
                    <?php foreach ($media as $item): ?>
                        <article class="product-media-card">
                            <?php if (($item['media_type'] ?? '') === 'video' && !empty($item['external_url'])): ?>
                                <div class="product-video-embed">
                                    <iframe src="<?= e((string) $item['external_url']) ?>" title="<?= e((string) ($item['title'] ?? 'Video')) ?>" allowfullscreen loading="lazy"></iframe>
                                </div>
                            <?php elseif (($item['media_type'] ?? '') === 'video'): ?>
                                <video src="<?= e(asset((string) $item['storage_path'])) ?>" controls preload="metadata"></video>
                            <?php else: ?>
                                <img src="<?= e(asset((string) $item['storage_path'])) ?>" alt="<?= e((string) ($item['title'] ?? '')) ?>">
                            <?php endif; ?>
                            <?php if (!empty($item['title']) || !empty($item['caption'])): ?>
                                <div class="product-media-copy">
                                    <?php if (!empty($item['title'])): ?>
                                        <strong><?= e((string) $item['title']) ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($item['caption'])): ?>
                                        <p class="muted"><?= e((string) $item['caption']) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </aside>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>

<style>
.product-detail-layout {
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(260px,340px);
    gap:1.25rem;
    align-items:start;
}
.product-media-gallery {
    border:1px solid #e6ebf2;
    border-radius:16px;
    padding:1rem;
    background:#f8fafc;
}
.product-media-gallery h2 {
    color:var(--doceo-blue);
    font-size:1.05rem;
    margin:0 0 .25rem;
}
.product-media-card {
    background:#fff;
    border:1px solid #e6ebf2;
    border-radius:14px;
    overflow:hidden;
    margin-top:.85rem;
}
.product-media-card img,
.product-media-card video {
    width:100%;
    max-height:260px;
    object-fit:contain;
    display:block;
    background:#f4f7fb;
}
.product-video-embed {
    position:relative;
    width:100%;
    aspect-ratio:16 / 9;
    background:#f4f7fb;
}
.product-video-embed iframe {
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    border:0;
}
.product-media-copy {
    padding:.75rem;
}
.product-media-copy p {
    margin:.25rem 0 0;
    font-size:.85rem;
}
@media (max-width: 860px) {
    .product-detail-layout {
        grid-template-columns:1fr;
    }
}
</style>
