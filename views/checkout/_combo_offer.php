<?php
/** @var list<array<string,mixed>> $comboList */
/** @var bool $comboStepIntro */
/** @var array<string,mixed> $product */
$comboStepIntro = $comboStepIntro ?? false;
$whatsappUrl = \App\Support\Settings::schoolWhatsappPromoUrl((string) ($product['name'] ?? ''));
?>
<div class="combo-upsell<?= $comboStepIntro ? ' combo-upsell--step' : '' ?>">
    <?php if ($comboStepIntro): ?>
        <div class="combo-step-intro">
            <span class="combo-step-badge">Paso opcional</span>
            <h2 class="step-title" style="margin:.35rem 0">¿Quieres completar tu paquete?</h2>
            <p class="muted" style="margin:0;font-size:.9rem;max-width:40rem">
                Ya registraste tus datos. Antes de pagar, puedes elegir un paquete armado con
                <strong>precio de combo</strong> y ver el ahorro frente a comprar cada producto
                por separado a <strong>precio de lista</strong>.
            </p>
        </div>
    <?php else: ?>
        <h2 style="margin:0 0 .35rem;font-size:1.05rem;color:var(--doceo-blue)">Convertir en combo</h2>
        <p class="muted" style="margin:0 0 .75rem;font-size:.85rem">
            Elige un paquete armado con tarifa de combo (comparado contra el precio de lista de cada uno).
        </p>
    <?php endif; ?>

    <?php if ($comboList !== []): ?>
        <div class="combo-preset-list">
            <?php foreach ($comboList as $c): ?>
                <?php
                $cid = (int) $c['id'];
                $addonIds = array_map('intval', $c['addon_ids'] ?? []);
                $itemNames = array_map(static fn ($i) => (string) $i['name'], $c['items'] ?? []);
                $listPrice = (float) ($c['list_price'] ?? $c['catalog_price'] ?? 0);
                $publicPrice = (float) ($c['public_price'] ?? 0);
                if ($listPrice <= 0) {
                    $listPrice = $publicPrice;
                }
                $soloSum = (float) ($c['solo_sum'] ?? 0);
                $savings = $soloSum > $listPrice ? $soloSum - $listPrice : 0;
                $showAdvisorCta = $publicPrice > 0 && $listPrice > $publicPrice + 0.009;
                ?>
                <label class="combo-preset-card">
                    <input type="radio" name="combo_preset" value="<?= $cid ?>"
                           data-addon-ids="<?= e(implode(',', $addonIds)) ?>"
                           data-combo-name="<?= e((string) $c['name']) ?>"
                           data-combo-price="<?= e((string) $listPrice) ?>"
                           data-combo-public="<?= e((string) $publicPrice) ?>">
                    <span class="combo-preset-body">
                        <strong><?= e((string) $c['name']) ?></strong>
                        <span class="muted combo-preset-includes">
                            Incluye: <?= e(implode(' + ', $itemNames)) ?>
                        </span>
                        <span class="combo-preset-prices">
                            Paquete <?= money($listPrice) ?>
                            <?php if ($savings > 0.009): ?>
                                <span class="combo-solo-strike"><?= money($soloSum) ?></span>
                                <span class="combo-save-pill">Ahorras <?= money($savings) ?> vs lista</span>
                            <?php endif; ?>
                        </span>
                        <?php if ($showAdvisorCta): ?>
                            <?php if ($whatsappUrl !== null): ?>
                                <a class="combo-advisor-link" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer"
                                   onclick="event.stopPropagation()">
                                    Contacta a un asesor para ver si existe algún código promocional vigente
                                </a>
                            <?php else: ?>
                                <span class="muted" style="font-size:.78rem">
                                    Contacta a un asesor para ver si existe algún código promocional vigente.
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($c['items'])): ?>
                            <ul class="combo-item-prices muted">
                                <?php foreach ($c['items'] as $it): ?>
                                    <?php
                                    $solo = (float) ($it['list_price'] ?? 0);
                                    if ($solo <= 0) {
                                        $solo = \App\Services\ComboAdminService::listPriceForItem($it);
                                    }
                                    ?>
                                    <li><?= e((string) $it['name']) ?> · <?= money($solo) ?> lista</li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
            <label class="combo-preset-card combo-preset-card--solo">
                <input type="radio" name="combo_preset" value="" checked data-addon-ids="" data-combo-name="">
                <span class="combo-preset-body">
                    <strong>Solo <?= e($product['name'] ?? 'este producto') ?></strong>
                    <span class="muted" style="font-size:.82rem">Continuar sin agregar extras al paquete.</span>
                </span>
            </label>
        </div>
    <?php endif; ?>
</div>
