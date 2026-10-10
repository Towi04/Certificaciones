<?php
/** @var array<string,mixed> $combo */
/** @var array<string,mixed>|null $partner */
/** @var array<string,mixed>|null $user */
/** @var float|null $partnerPrice */
/** @var string $ctaUrl */
$partner = $partner ?? null;
$user = $user ?? null;
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
?>
<p class="meta">
    <a href="<?= e(url('/catalogo?seccion=combos')) ?>">← Combos</a>
    <?php if ($isAdminViewer): ?>
        · <a href="<?= e(url('/admin/combos/' . (int) ($combo['id'] ?? 0) . '#catalogo')) ?>">Editar combo</a>
    <?php endif; ?>
</p>

<section class="hero" style="margin-top:.5rem">
    <div class="hero-banner" style="display:flex;gap:1.25rem;align-items:center;flex-wrap:wrap">
        <div style="width:120px;height:90px;background:#fff;border-radius:14px;display:flex;align-items:center;justify-content:center;padding:.6rem;border:1px solid #e6ebf2">
            <?php if (!empty($combo['logo_path'])): ?>
                <img src="<?= e(asset((string) $combo['logo_path'])) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
            <?php else: ?>
                <img src="<?= e(asset('/assets/brand/logo.png')) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain;opacity:.55">
            <?php endif; ?>
        </div>
        <div style="flex:1;min-width:220px">
            <p class="muted" style="margin:0 0 .25rem;font-size:.85rem;font-weight:700;letter-spacing:.02em">COMBO / PAQUETE</p>
            <h1 style="margin:0;color:var(--doceo-blue)"><?= e((string) $combo['name']) ?></h1>
            <?php if (!empty($combo['short_description'])): ?>
                <div class="product-richtext" style="margin:.55rem 0 0;max-width:40rem">
                    <?= rich_text((string) $combo['short_description']) ?>
                </div>
            <?php endif; ?>
            <div style="margin-top:.85rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
                <div style="font-size:1.45rem;font-weight:800;color:var(--doceo-blue)">
                    <?= money($displayPrice) ?>
                    <?php if ($hasPartnerPrice): ?>
                        <span class="muted" style="font-size:.75rem;font-weight:600"> · tu nivel</span>
                    <?php endif; ?>
                </div>
                <a class="btn btn-accent" href="<?= e($ctaUrl) ?>">
                    <?= $isPartner ? 'Registrar alumno con este combo' : 'Adquirir paquete' ?>
                </a>
            </div>
        </div>
    </div>
</section>

<div class="panel" style="margin-top:1.25rem">
    <h2 style="margin-top:0;font-size:1.1rem;color:var(--doceo-blue)">Incluye</h2>
    <?php if ($items === []): ?>
        <p class="muted">Este paquete aún no tiene productos asociados.</p>
    <?php else: ?>
        <ul style="margin:.35rem 0 0;padding-left:1.15rem;line-height:1.55">
            <?php foreach ($items as $item): ?>
                <li>
                    <strong><?= e((string) ($item['name'] ?? '')) ?></strong>
                    <span class="muted"> · <?= e($typeLabels[(string) ($item['type'] ?? '')] ?? (string) ($item['type'] ?? '')) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if (!empty($combo['description'])): ?>
    <div class="panel" style="margin-top:1rem">
        <h2 style="margin-top:0;font-size:1.1rem;color:var(--doceo-blue)">Descripción</h2>
        <div class="product-richtext">
            <?= rich_text((string) $combo['description']) ?>
        </div>
    </div>
<?php endif; ?>

<div style="margin-top:1.25rem">
    <a class="btn btn-accent" href="<?= e($ctaUrl) ?>">
        <?= $isPartner ? 'Registrar alumno con este combo' : 'Adquirir paquete' ?>
    </a>
    <a class="btn btn-ghost" href="<?= e(url('/catalogo?seccion=combos')) ?>" style="margin-left:.35rem">Volver a combos</a>
</div>
