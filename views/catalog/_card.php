<?php /** @var array<string,mixed> $p */ ?>
<?php
$isPartner = !empty($partner) || (($user['role'] ?? '') === 'partner');
$productUrl = $isPartner
    ? url('/adquirir/' . $p['slug'])
    : url('/producto/' . $p['slug']);
// Preferir precio de nivel; si falta anotación, no mostrar lista como si fuera partner price.
$hasPartnerPrice = $isPartner && array_key_exists('partner_price', $p) && $p['partner_price'] !== null && $p['partner_price'] !== '';
$displayPrice = $hasPartnerPrice
    ? (float) $p['partner_price']
    : (float) ($p['catalog_price'] ?? $p['public_price'] ?? 0);
?>
<a class="product-card product-card-link" href="<?= e($productUrl) ?>">
    <div class="thumb">
        <?php if (!empty($p['is_star'])): ?><span class="badge-star" aria-label="Producto estrella">⭐</span><?php endif; ?>
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
        <div class="price">
            <?= money($displayPrice) ?>
            <?php if ($hasPartnerPrice): ?>
                <span class="muted" style="font-size:.72rem;font-weight:600"> · tu nivel</span>
            <?php endif; ?>
        </div>
        <div class="actions">
            <span class="btn btn-primary btn-sm"><?= $isPartner ? 'Registrar alumno' : 'Ver más' ?></span>
        </div>
    </div>
</a>
