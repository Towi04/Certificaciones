<?php
/** @var list<array<string,mixed>> $comboList */
/** @var bool $comboStepIntro */
/** @var array<string,mixed> $product */
$comboList = $comboList ?? [];
$comboStepIntro = $comboStepIntro ?? false;
$productName = (string) ($product['name'] ?? 'este producto');
$whatsappUrl = \App\Support\Settings::schoolWhatsappPromoUrl($productName);
?>
<div class="combo-upsell<?= $comboStepIntro ? ' combo-upsell--step' : '' ?>">
    <?php if ($comboStepIntro): ?>
        <div class="combo-step-intro">
            <span class="combo-step-badge">Paso opcional</span>
            <h2 class="step-title" style="margin:.35rem 0">¿Quieres completar tu paquete?</h2>
            <p class="muted" style="margin:0;font-size:.9rem;max-width:40rem">
                Elige un paquete armado o continúa solo con
                <strong><?= e($productName) ?></strong>.
                El detalle y el ahorro se muestran a la derecha.
            </p>
        </div>
    <?php else: ?>
        <h2 style="margin:0 0 .35rem;font-size:1.05rem;color:var(--doceo-blue)">Convertir en combo</h2>
        <p class="muted" style="margin:0 0 .75rem;font-size:.85rem">
            Elige un paquete o continúa solo con este producto.
        </p>
    <?php endif; ?>

    <?php if ($comboList !== []): ?>
        <div class="combo-tiles" role="group" aria-label="Paquetes disponibles">
            <?php foreach ($comboList as $c): ?>
                <?php
                $cid = (int) $c['id'];
                $addonIds = array_map('intval', $c['addon_ids'] ?? []);
                $listPrice = (float) ($c['list_price'] ?? $c['catalog_price'] ?? 0);
                $publicPrice = (float) ($c['public_price'] ?? 0);
                if ($listPrice <= 0) {
                    $listPrice = $publicPrice;
                }
                $comboName = (string) ($c['name'] ?? 'Combo');
                ?>
                <label class="combo-tile">
                    <input type="radio" name="combo_preset" value="<?= $cid ?>"
                           data-addon-ids="<?= e(implode(',', $addonIds)) ?>"
                           data-combo-name="<?= e($comboName) ?>"
                           data-combo-price="<?= e((string) $listPrice) ?>"
                           data-combo-public="<?= e((string) $publicPrice) ?>">
                    <span class="combo-tile-label"><?= e($comboName) ?></span>
                </label>
            <?php endforeach; ?>
            <label class="combo-tile combo-tile--solo">
                <input type="radio" name="combo_preset" value="" checked data-addon-ids="" data-combo-name="">
                <span class="combo-tile-label">Solo <?= e($productName) ?></span>
            </label>
        </div>
        <?php if ($whatsappUrl !== null): ?>
            <p class="combo-advisor-once">
                <a class="combo-advisor-link" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer">
                    Contacta a un asesor por WhatsApp para ver si existe algún código promocional vigente
                </a>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
