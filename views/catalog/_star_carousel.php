<?php
/** @var list<array<string,mixed>> $stars */
?>
<section class="star-carousel-section" aria-label="Productos estrella">
    <div class="star-carousel-header">
        <h2 style="color:var(--doceo-blue);margin:0">⭐ Productos estrella</h2>
    </div>
    <div class="star-carousel-wrap" data-star-carousel>
        <button type="button" class="star-carousel-btn prev" aria-label="Ver anteriores" data-carousel-prev disabled>‹</button>
        <div class="star-carousel-viewport">
            <div class="star-carousel-track" data-carousel-track>
                <?php foreach ($stars as $p): ?>
                    <a class="star-carousel-item" href="<?= e(url('/producto/' . $p['slug'])) ?>">
                        <div class="star-carousel-logo">
                            <?php if (!empty($p['logo_path'])): ?>
                                <img src="<?= e(asset($p['logo_path'])) ?>" alt="">
                            <?php else: ?>
                                <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="" style="opacity:.45">
                            <?php endif; ?>
                            <span class="badge-star" aria-hidden="true">⭐</span>
                        </div>
                        <span class="star-carousel-name"><?= e($p['name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="star-carousel-btn next" aria-label="Ver siguientes" data-carousel-next>›</button>
    </div>
</section>
<script>
(function () {
    var wrap = document.querySelector('[data-star-carousel]');
    if (!wrap) return;
    var track = wrap.querySelector('[data-carousel-track]');
    var viewport = wrap.querySelector('.star-carousel-viewport');
    var prev = wrap.querySelector('[data-carousel-prev]');
    var next = wrap.querySelector('[data-carousel-next]');
    if (!track || !viewport || !prev || !next) return;

    function updateButtons() {
        var maxScroll = track.scrollWidth - viewport.clientWidth;
        prev.disabled = viewport.scrollLeft <= 4;
        next.disabled = viewport.scrollLeft >= maxScroll - 4;
        wrap.classList.toggle('can-scroll', maxScroll > 8);
    }

    function scrollByDir(dir) {
        var item = track.querySelector('.star-carousel-item');
        var step = item ? item.offsetWidth + 12 : 160;
        viewport.scrollBy({ left: dir * step, behavior: 'smooth' });
    }

    prev.addEventListener('click', function () { scrollByDir(-1); });
    next.addEventListener('click', function () { scrollByDir(1); });
    viewport.addEventListener('scroll', updateButtons, { passive: true });
    window.addEventListener('resize', updateButtons);
    updateButtons();
})();
</script>
