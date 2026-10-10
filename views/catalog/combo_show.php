<?php
/** @var array<string,mixed> $combo */
/** @var array<string,mixed>|null $partner */
/** @var array<string,mixed>|null $user */
/** @var float|null $partnerPrice */
/** @var string $ctaUrl */
/** @var array<string,mixed>|null $breakdown */
$partner = $partner ?? null;
$user = $user ?? null;
$breakdown = is_array($breakdown ?? null) ? $breakdown : null;
$isPartner = !empty($partner) || (($user['role'] ?? '') === 'partner');
$isAdminViewer = (\App\Auth\Auth::role() === 'admin');
$hasPartnerPrice = $isPartner && $partnerPrice !== null;
$displayPrice = $hasPartnerPrice
    ? (float) $partnerPrice
    : (float) ($combo['catalog_price'] ?? $combo['public_price'] ?? 0);
$items = is_array($combo['items'] ?? null) ? $combo['items'] : [];
$typeLabels = [
    'certification' => 'Certificación',
    'course' => 'Curso',
    'procedure' => 'Trámite',
    'shipping' => 'Envío',
    'extension' => 'Extensión',
    'other' => 'Otro',
];
$adminEdit = $isAdminViewer
    ? url('/admin/combos/' . (int) ($combo['id'] ?? 0) . '#catalogo')
    : '';
$soloSum = (float) ($breakdown['solo_sum'] ?? 0);
$savings = (float) ($breakdown['savings'] ?? 0);
$savingsPct = (float) ($breakdown['savings_percent'] ?? 0);
require __DIR__ . '/_card_styles.php';
?>
<p class="meta">
    <a href="<?= e(url('/catalogo?seccion=combos')) ?>">← Combos</a>
</p>

<article class="panel combo-show" style="margin:1.25rem 0 2rem">
    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start">
        <div style="width:min(180px,100%);background:#f4f7fb;border-radius:16px;padding:1rem;text-align:center;position:relative">
            <img src="<?= e(asset(!empty($combo['logo_path']) ? (string) $combo['logo_path'] : '/assets/brand/logo.png')) ?>"
                 alt="<?= e((string) ($combo['name'] ?? 'Combo')) ?>"
                 style="max-height:120px;max-width:100%;object-fit:contain<?= empty($combo['logo_path']) ? ';opacity:.55' : '' ?>">
            <?php if (!empty($combo['is_star'])): ?>
                <span class="badge-star" style="position:absolute;top:.55rem;left:.55rem" aria-label="Combo destacado">⭐</span>
            <?php endif; ?>
            <?php if ($adminEdit !== ''): ?>
                <a class="catalog-admin-edit" href="<?= e($adminEdit) ?>"
                   title="Editar imagen / contenido" aria-label="Editar imagen / contenido"><?= icon('edit') ?></a>
            <?php endif; ?>
        </div>
        <div style="flex:1;min-width:240px">
            <p class="muted" style="margin:0 0 .2rem;font-size:.8rem;font-weight:800;letter-spacing:.04em">COMBO / PAQUETE</p>
            <h1 style="margin:.15rem 0 .5rem;color:var(--doceo-blue);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <span><?= e((string) $combo['name']) ?></span>
                <?php if ($adminEdit !== ''): ?>
                    <a class="catalog-admin-edit catalog-admin-edit--inline" href="<?= e($adminEdit) ?>"
                       title="Editar combo" aria-label="Editar combo"><?= icon('edit') ?></a>
                <?php endif; ?>
            </h1>
            <?php if (!empty($combo['short_description'])): ?>
                <div class="muted product-richtext" style="margin:.25rem 0 .5rem;position:relative;padding-right:<?= $adminEdit !== '' ? '2rem' : '0' ?>">
                    <?php if ($adminEdit !== ''): ?>
                        <a class="catalog-admin-edit catalog-admin-edit--inline" href="<?= e($adminEdit) ?>"
                           title="Editar resumen" aria-label="Editar resumen"
                           style="position:absolute;top:0;right:0"><?= icon('edit') ?></a>
                    <?php endif; ?>
                    <?= rich_text((string) $combo['short_description']) ?>
                </div>
            <?php elseif ($adminEdit !== ''): ?>
                <p class="muted" style="margin:.25rem 0 .5rem;font-size:.85rem">
                    Sin resumen propio (se usa el de las certificaciones).
                    <a href="<?= e($adminEdit) ?>"><?= icon('edit') ?> Editar</a>
                </p>
            <?php endif; ?>

            <p class="price" style="font-size:1.6rem;margin:.5rem 0">
                <?= money($displayPrice) ?>
                <?php if ($hasPartnerPrice): ?>
                    <span class="muted" style="font-size:.85rem;font-weight:600">
                        precio partner (<?= e(\App\Services\PartnerAdminService::tierLabel($partner['tier'] ?? null)) ?>)
                    </span>
                <?php endif; ?>
            </p>
            <?php if ($savings > 0.009 && $soloSum > 0): ?>
                <p class="combo-savings-pill" style="margin:.15rem 0 .75rem">
                    Ahorras <?= money($savings) ?>
                    <?php if ($savingsPct > 0): ?>
                        <span>(<?= e(rtrim(rtrim(number_format($savingsPct, 1, '.', ''), '0'), '.')) ?>%)</span>
                    <?php endif; ?>
                    vs comprar por separado (<?= money($soloSum) ?>)
                </p>
            <?php endif; ?>

            <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem">
                <a class="btn btn-accent" href="<?= e($ctaUrl) ?>">
                    <?= $isPartner ? 'Registrar alumno' : 'Adquirir paquete' ?>
                </a>
                <a class="btn btn-ghost" href="<?= e(url('/catalogo?seccion=combos')) ?>">Volver a combos</a>
                <?php if ($adminEdit !== ''): ?>
                    <a class="btn btn-ghost" href="<?= e($adminEdit) ?>" title="Editar combo">
                        <?= icon('edit') ?> Editar contenido
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <hr style="border:0;border-top:1px solid #e6ebf2;margin:1.25rem 0">

    <div class="product-detail-layout combo-detail-layout">
        <section>
            <h2 style="color:var(--doceo-blue);font-size:1.05rem;margin-top:0;display:flex;align-items:center;gap:.45rem">
                Incluye
                <?php if ($adminEdit !== ''): ?>
                    <a class="catalog-admin-edit catalog-admin-edit--inline"
                       href="<?= e(url('/admin/combos/' . (int) ($combo['id'] ?? 0))) ?>"
                       title="Editar productos del combo" aria-label="Editar productos del combo"><?= icon('edit') ?></a>
                <?php endif; ?>
            </h2>
            <?php if ($items === []): ?>
                <p class="muted">Este paquete aún no tiene productos asociados.</p>
            <?php else: ?>
                <div class="combo-includes-grid">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemSlug = trim((string) ($item['slug'] ?? ''));
                        $itemUrl = $itemSlug !== '' ? url('/producto/' . $itemSlug) : '';
                        $itemType = (string) ($item['type'] ?? '');
                        $itemLogo = trim((string) ($item['logo_path'] ?? ''));
                        $solo = null;
                        if (is_array($breakdown['items'] ?? null)) {
                            foreach ($breakdown['items'] as $br) {
                                if ((int) ($br['id'] ?? 0) === (int) ($item['id'] ?? 0)) {
                                    $solo = (float) ($br['solo_price'] ?? 0);
                                    break;
                                }
                            }
                        }
                        ?>
                        <div class="combo-include-card">
                            <div class="combo-include-logo">
                                <img src="<?= e(asset($itemLogo !== '' ? $itemLogo : '/assets/brand/logo.png')) ?>"
                                     alt="" style="<?= $itemLogo === '' ? 'opacity:.45' : '' ?>">
                            </div>
                            <div class="combo-include-body">
                                <div class="muted" style="font-size:.75rem;font-weight:700">
                                    <?= e($typeLabels[$itemType] ?? $itemType) ?>
                                </div>
                                <?php if ($itemUrl !== ''): ?>
                                    <a href="<?= e($itemUrl) ?>" class="combo-include-name"><?= e((string) ($item['name'] ?? '')) ?></a>
                                <?php else: ?>
                                    <strong class="combo-include-name"><?= e((string) ($item['name'] ?? '')) ?></strong>
                                <?php endif; ?>
                                <?php if ($solo !== null && $solo > 0): ?>
                                    <div class="muted" style="font-size:.8rem;margin-top:.2rem">Lista <?= money($solo) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($combo['description'])): ?>
                <h2 style="color:var(--doceo-blue);font-size:1.05rem;margin-top:1.35rem;display:flex;align-items:center;gap:.45rem">
                    Descripción
                    <?php if ($adminEdit !== ''): ?>
                        <a class="catalog-admin-edit catalog-admin-edit--inline" href="<?= e($adminEdit) ?>"
                           title="Editar descripción" aria-label="Editar descripción"><?= icon('edit') ?></a>
                    <?php endif; ?>
                </h2>
                <div class="product-richtext"><?= rich_text((string) $combo['description']) ?></div>
            <?php elseif ($adminEdit !== ''): ?>
                <h2 style="color:var(--doceo-blue);font-size:1.05rem;margin-top:1.35rem;display:flex;align-items:center;gap:.45rem">
                    Descripción
                    <a class="catalog-admin-edit catalog-admin-edit--inline" href="<?= e($adminEdit) ?>"
                       title="Agregar descripción" aria-label="Agregar descripción"><?= icon('edit') ?></a>
                </h2>
                <p class="muted" style="font-size:.88rem">Sin descripción propia; se muestra el fallback de certificaciones cuando exista.</p>
            <?php endif; ?>
        </section>

        <aside class="combo-buy-aside" aria-label="Resumen de compra">
            <h2>Tu paquete</h2>
            <div class="combo-buy-price"><?= money($displayPrice) ?></div>
            <?php if ($hasPartnerPrice): ?>
                <p class="muted" style="margin:.25rem 0 0;font-size:.82rem">Precio de tu nivel partner</p>
            <?php elseif ($soloSum > 0): ?>
                <p class="muted" style="margin:.25rem 0 0;font-size:.82rem;text-decoration:line-through">
                    Por separado <?= money($soloSum) ?>
                </p>
            <?php endif; ?>
            <?php if ($savings > 0.009): ?>
                <p class="combo-savings-pill" style="margin:.75rem 0 0">
                    Ahorro <?= money($savings) ?>
                    <?php if ($savingsPct > 0): ?>
                        (<?= e(rtrim(rtrim(number_format($savingsPct, 1, '.', ''), '0'), '.')) ?>%)
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <a class="btn btn-accent" style="width:100%;margin-top:1rem;text-align:center" href="<?= e($ctaUrl) ?>">
                <?= $isPartner ? 'Registrar alumno' : 'Adquirir paquete' ?>
            </a>
            <p class="muted" style="margin:.75rem 0 0;font-size:.78rem;line-height:1.4">
                Un solo pago. Se crean los casos de cada producto incluido en el combo.
            </p>
        </aside>
    </div>
</article>

<style>
.catalog-admin-edit {
    position:absolute; top:.45rem; right:.45rem; width:1.85rem; height:1.85rem;
    display:inline-flex; align-items:center; justify-content:center;
    border-radius:999px; background:#fff; border:1px solid #d9e4f5; color:var(--doceo-blue);
    text-decoration:none; box-shadow:0 2px 8px rgba(15,23,42,.08);
}
.catalog-admin-edit:hover { background:#eef4ff; text-decoration:none; }
.catalog-admin-edit--inline { position:static; width:1.55rem; height:1.55rem; flex:0 0 auto; }
.catalog-admin-edit svg { width:14px; height:14px; display:block; }
.product-richtext { line-height:1.55; color:#243247; }
.product-richtext p { margin:.55rem 0; }
.product-richtext ul, .product-richtext ol { margin:.55rem 0 .55rem 1.1rem; padding:0; }
.product-richtext li { margin:.2rem 0; }
.product-richtext strong { color:#1f3b66; }
.product-detail-layout, .combo-detail-layout {
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(240px,300px);
    gap:1.25rem;
    align-items:start;
}
.combo-includes-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:.75rem;
    margin-top:.65rem;
}
.combo-include-card {
    display:flex;
    gap:.7rem;
    align-items:center;
    border:1px solid #e6ebf2;
    border-radius:14px;
    padding:.7rem .8rem;
    background:#fbfcfe;
}
.combo-include-logo {
    width:52px; height:52px; flex:0 0 auto;
    border-radius:12px; background:#fff; border:1px solid #e6ebf2;
    display:flex; align-items:center; justify-content:center; padding:.35rem;
}
.combo-include-logo img { max-width:100%; max-height:100%; object-fit:contain; }
.combo-include-name {
    display:block;
    color:var(--doceo-blue);
    font-weight:700;
    text-decoration:none;
    line-height:1.3;
}
a.combo-include-name:hover { text-decoration:underline; }
.combo-buy-aside {
    border:1px solid #e6ebf2;
    border-radius:16px;
    padding:1rem 1.1rem;
    background:#f8fafc;
    position:sticky;
    top:1rem;
}
.combo-buy-aside h2 {
    color:var(--doceo-blue);
    font-size:1.05rem;
    margin:0 0 .35rem;
}
.combo-buy-price {
    font-size:1.55rem;
    font-weight:800;
    color:var(--doceo-blue);
}
.combo-savings-pill {
    display:inline-block;
    background:#e8f7ee;
    color:#146c2e;
    border:1px solid #b7e4c7;
    border-radius:999px;
    padding:.28rem .7rem;
    font-size:.8rem;
    font-weight:700;
}
@media (max-width: 820px) {
    .product-detail-layout, .combo-detail-layout {
        grid-template-columns:1fr;
    }
    .combo-buy-aside { position:static; }
}
</style>
