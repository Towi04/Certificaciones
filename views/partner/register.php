<?php
/** @var array<string,mixed> $partner */
/** @var list<array<string,mixed>> $products */
?>
<p class="meta"><a href="<?= e(url('/partner')) ?>">← Mis alumnos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">Registrar alumno</h1>
<p class="muted" style="max-width:48rem">
    Elige el producto en el catálogo (se muestra tu precio de nivel
    <strong><?= e(\App\Services\PartnerAdminService::tierLabel($partner['tier'] ?? null)) ?></strong>).
    Completarás el <strong>mismo proceso</strong> que un alumno cualquiera:
    datos obligatorios, reglamento (firma digital en pantalla o PDF escaneado),
    agenda, combos/paquetes y opciones de pago.
    Al terminar seguirás en tu portal con el caso y su progreso.
</p>

<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin:1rem 0 1.25rem">
    <a class="btn btn-accent" href="<?= e(url('/catalogo')) ?>">Abrir catálogo público</a>
    <a class="btn btn-ghost" href="<?= e(url('/partner')) ?>">Cancelar</a>
</div>

<?php if ($products === []): ?>
    <div class="empty">No hay productos publicados. Contacta a administración.</div>
<?php else: ?>
    <?php require BASE_PATH . '/views/catalog/_card_styles.php'; ?>
    <div class="panel">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Catálogo para tu nivel</h2>
        <p class="muted" style="font-size:.85rem;margin-top:0">
            Si el producto tiene combos, los eliges en el paso “Paquete” del checkout (no en un combo box).
        </p>
        <div class="product-grid" style="margin-top:.85rem">
            <?php foreach ($products as $p): ?>
                <?php
                $productUrl = url('/adquirir/' . $p['slug']);
                $price = (float) ($p['partner_price'] ?? $p['public_price'] ?? $p['catalog_price'] ?? 0);
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
                        <div class="meta"><?= e($p['certifier_name'] ?? category_label((string) ($p['category'] ?? ''))) ?></div>
                        <h3><?= e($p['name']) ?></h3>
                        <?php if (!empty($p['short_description'])): ?>
                            <div class="meta product-richtext"><?= rich_text((string) $p['short_description']) ?></div>
                        <?php endif; ?>
                        <div class="price">
                            <?= money($price) ?>
                            <span class="muted" style="font-size:.72rem;font-weight:600"> · tu nivel</span>
                        </div>
                        <div class="actions">
                            <span class="btn btn-primary btn-sm">Registrar alumno</span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
