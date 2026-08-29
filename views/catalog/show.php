<?php
/** @var array<string,mixed> $product */
/** @var list<array<string,mixed>> $media */
$media = $media ?? [];
$youtubeThumb = static function (array $item): ?string {
    $url = (string) ($item['external_url'] ?? '');
    if ($url !== '' && preg_match('#/embed/([A-Za-z0-9_-]+)#', $url, $m)) {
        return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
    }

    return null;
};
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
                    <div class="product-media-thumb-grid">
                        <?php foreach ($media as $item): ?>
                            <?php
                            $isVideo = (string) ($item['media_type'] ?? '') === 'video';
                            $externalUrl = (string) ($item['external_url'] ?? '');
                            $mediaSrc = $externalUrl !== '' ? $externalUrl : asset((string) $item['storage_path']);
                            $thumbSrc = $isVideo
                                ? ($youtubeThumb($item) ?? '')
                                : asset((string) $item['storage_path']);
                            ?>
                            <button
                                type="button"
                                class="product-media-thumb<?= $isVideo ? ' is-video' : '' ?>"
                                data-media-type="<?= $isVideo ? 'video' : 'image' ?>"
                                data-media-src="<?= e($mediaSrc) ?>"
                                data-media-title="<?= e((string) ($item['title'] ?? '')) ?>"
                                data-media-caption="<?= e((string) ($item['caption'] ?? '')) ?>"
                            >
                                <?php if ($thumbSrc !== ''): ?>
                                    <img src="<?= e($thumbSrc) ?>" alt="<?= e((string) ($item['title'] ?? '')) ?>">
                                <?php else: ?>
                                    <span class="product-media-video-placeholder">Video</span>
                                <?php endif; ?>
                                <?php if ($isVideo): ?><span class="product-media-play">▶</span><?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </aside>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>

<?php if ($media !== []): ?>
<div class="product-media-modal" id="product-media-modal" hidden role="dialog" aria-modal="true" aria-label="Multimedia del producto">
    <button type="button" class="product-media-modal-backdrop" data-media-close aria-label="Cerrar"></button>
    <div class="product-media-modal-card">
        <button type="button" class="product-media-modal-close" data-media-close aria-label="Cerrar">×</button>
        <button type="button" class="product-media-modal-zoom" data-media-zoom aria-label="Zoom" hidden>&#128269;</button>
        <button type="button" class="product-media-modal-nav product-media-modal-prev" data-media-prev aria-label="Anterior">&lt;</button>
        <button type="button" class="product-media-modal-nav product-media-modal-next" data-media-next aria-label="Siguiente">&gt;</button>
        <div class="product-media-modal-body" id="product-media-modal-body"></div>
        <h3 id="product-media-modal-title"></h3>
        <p class="muted" id="product-media-modal-caption"></p>
    </div>
</div>
<?php endif; ?>

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
.product-media-thumb-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(92px,1fr));
    gap:.65rem;
    margin-top:.75rem;
}
.product-media-thumb {
    position:relative;
    aspect-ratio:1 / 1;
    border:1px solid #e6ebf2;
    border-radius:12px;
    background:#fff;
    padding:.35rem;
    cursor:pointer;
    overflow:hidden;
}
.product-media-thumb img {
    width:100%;
    height:100%;
    object-fit:cover;
    border-radius:9px;
    display:block;
}
.product-media-thumb:hover {
    border-color:var(--doceo-blue);
}
.product-media-video-placeholder {
    display:flex;
    width:100%;
    height:100%;
    align-items:center;
    justify-content:center;
    color:var(--doceo-blue);
    font-weight:700;
    background:#eef4fc;
    border-radius:9px;
}
.product-media-play {
    position:absolute;
    inset:auto .45rem .45rem auto;
    width:2rem;
    height:2rem;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(0,0,0,.72);
    color:#fff;
    font-size:.8rem;
}
.product-media-modal[hidden] {
    display:none;
}
.product-media-modal {
    position:fixed;
    inset:0;
    z-index:1000;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:1.25rem;
}
.product-media-modal-backdrop {
    position:absolute;
    inset:0;
    border:0;
    background:rgba(5,18,38,.72);
    cursor:pointer;
}
.product-media-modal-card {
    position:relative;
    width:min(960px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:18px;
    padding:1rem;
    box-shadow:0 24px 80px rgba(0,0,0,.28);
}
.product-media-modal-close {
    position:absolute;
    top:.65rem;
    right:.65rem;
    z-index:2;
    border:0;
    border-radius:50%;
    width:2rem;
    height:2rem;
    cursor:pointer;
    background:#fff;
    box-shadow:0 2px 10px rgba(0,0,0,.15);
    font-size:1.35rem;
    line-height:1;
}
.product-media-modal-zoom {
    position:absolute;
    top:.65rem;
    right:3rem;
    z-index:2;
    border:0;
    border-radius:999px;
    min-width:2rem;
    height:2rem;
    cursor:pointer;
    background:#fff;
    box-shadow:0 2px 10px rgba(0,0,0,.15);
}
.product-media-modal-nav {
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    z-index:2;
    width:2.5rem;
    height:2.5rem;
    border:0;
    border-radius:50%;
    background:rgba(255,255,255,.94);
    box-shadow:0 2px 12px rgba(0,0,0,.18);
    cursor:pointer;
    color:var(--doceo-blue);
    font-size:1.25rem;
    font-weight:800;
}
.product-media-modal-prev {
    left:.75rem;
}
.product-media-modal-next {
    right:.75rem;
}
.product-media-modal-body img,
.product-media-modal-body video {
    width:100%;
    max-height:72vh;
    object-fit:contain;
    display:block;
    background:#f4f7fb;
    border-radius:12px;
}
.product-media-modal-body.is-zoomed {
    max-height:72vh;
    overflow:auto;
    background:#f4f7fb;
    border-radius:12px;
}
.product-media-modal-body.is-zoomed img {
    width:auto;
    max-width:none;
    max-height:none;
    min-width:150%;
    cursor:zoom-out;
}
.product-media-modal-body iframe {
    width:100%;
    aspect-ratio:16 / 9;
    border:0;
    display:block;
    border-radius:12px;
    background:#000;
}
.product-media-modal-card h3 {
    margin:.85rem 0 .25rem;
    color:var(--doceo-blue);
}
.product-media-modal-card p {
    margin:0;
}
@media (max-width: 860px) {
    .product-detail-layout {
        grid-template-columns:1fr;
    }
}
</style>

<?php if ($media !== []): ?>
<script>
(function () {
  const modal = document.getElementById('product-media-modal');
  const body = document.getElementById('product-media-modal-body');
  const title = document.getElementById('product-media-modal-title');
  const caption = document.getElementById('product-media-modal-caption');
  const zoomBtn = modal ? modal.querySelector('[data-media-zoom]') : null;
  const prevBtn = modal ? modal.querySelector('[data-media-prev]') : null;
  const nextBtn = modal ? modal.querySelector('[data-media-next]') : null;
  if (!modal || !body || !title || !caption) return;
  const thumbs = Array.from(document.querySelectorAll('.product-media-thumb'));
  let currentIndex = 0;

  function openMedia(index) {
    currentIndex = (index + thumbs.length) % thumbs.length;
    const btn = thumbs[currentIndex];
    const type = btn.getAttribute('data-media-type') || 'image';
    const src = btn.getAttribute('data-media-src') || '';
    const mediaTitle = btn.getAttribute('data-media-title') || '';
    const mediaCaption = btn.getAttribute('data-media-caption') || '';
    body.innerHTML = '';
    body.classList.remove('is-zoomed');
    if (zoomBtn) {
      zoomBtn.hidden = type === 'video';
      zoomBtn.setAttribute('aria-pressed', 'false');
    }
    if (type === 'video') {
      if (src.indexOf('youtube.com/embed/') !== -1) {
        const iframe = document.createElement('iframe');
        iframe.src = src;
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        iframe.allowFullscreen = true;
        body.appendChild(iframe);
      } else {
        const video = document.createElement('video');
        video.src = src;
        video.controls = true;
        video.autoplay = true;
        body.appendChild(video);
      }
    } else {
      const img = document.createElement('img');
      img.src = src;
      img.alt = mediaTitle;
      img.addEventListener('click', toggleZoom);
      body.appendChild(img);
    }
    title.textContent = mediaTitle;
    title.hidden = mediaTitle === '';
    caption.textContent = mediaCaption;
    caption.hidden = mediaCaption === '';
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    if (prevBtn) prevBtn.hidden = thumbs.length < 2;
    if (nextBtn) nextBtn.hidden = thumbs.length < 2;
  }

  function closeMedia() {
    modal.hidden = true;
    body.innerHTML = '';
    body.classList.remove('is-zoomed');
    document.body.style.overflow = '';
  }

  function toggleZoom() {
    const on = !body.classList.contains('is-zoomed');
    body.classList.toggle('is-zoomed', on);
    if (zoomBtn) zoomBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
  }

  thumbs.forEach((btn, index) => {
    btn.addEventListener('click', () => openMedia(index));
  });
  modal.querySelectorAll('[data-media-close]').forEach(btn => {
    btn.addEventListener('click', closeMedia);
  });
  if (prevBtn) {
    prevBtn.addEventListener('click', () => openMedia(currentIndex - 1));
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', () => openMedia(currentIndex + 1));
  }
  if (zoomBtn) {
    zoomBtn.addEventListener('click', toggleZoom);
  }
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modal.hidden) closeMedia();
    if (e.key === 'ArrowLeft' && !modal.hidden && thumbs.length > 1) openMedia(currentIndex - 1);
    if (e.key === 'ArrowRight' && !modal.hidden && thumbs.length > 1) openMedia(currentIndex + 1);
  });
})();
</script>
<?php endif; ?>
