<?php
/** @var list<array<string,mixed>> $comboList */
/** @var list<array<string,mixed>> $comboAddons */
/** @var bool $comboStepIntro */
$comboStepIntro = $comboStepIntro ?? false;
?>
<div class="combo-upsell<?= $comboStepIntro ? ' combo-upsell--step' : '' ?>">
    <?php if ($comboStepIntro): ?>
        <div class="combo-step-intro">
            <span class="combo-step-badge">Paso opcional</span>
            <h2 class="step-title" style="margin:.35rem 0">¿Quieres completar tu paquete?</h2>
            <p class="muted" style="margin:0;font-size:.9rem;max-width:40rem">
                Ya registraste tus datos. Antes de pagar, puedes agregar el curso y/o trámite relacionado
                con <strong>precio de combo</strong> y ver el ahorro frente a comprar cada producto por separado.
            </p>
        </div>
    <?php else: ?>
        <h2 style="margin:0 0 .35rem;font-size:1.05rem;color:var(--doceo-blue)">Convertir en combo</h2>
        <p class="muted" style="margin:0 0 .75rem;font-size:.85rem">
            Agrega curso y/o trámite con tarifa de paquete (no es la suma suelta de cada producto).
        </p>
    <?php endif; ?>

    <?php if ($comboList !== []): ?>
        <div class="combo-preset-list">
            <?php foreach ($comboList as $c): ?>
                <?php
                $cid = (int) $c['id'];
                $addonIds = array_map('intval', $c['addon_ids'] ?? []);
                $itemNames = array_map(static fn ($i) => (string) $i['name'], $c['items'] ?? []);
                $savings = !empty($c['solo_sum']) ? (float) $c['solo_sum'] - (float) $c['public_price'] : 0;
                ?>
                <label class="combo-preset-card">
                    <input type="radio" name="combo_preset" value="<?= $cid ?>"
                           data-addon-ids="<?= e(implode(',', $addonIds)) ?>"
                           data-combo-name="<?= e((string) $c['name']) ?>"
                           data-combo-price="<?= e((string) $c['public_price']) ?>">
                    <span class="combo-preset-body">
                        <strong><?= e((string) $c['name']) ?></strong>
                        <span class="muted combo-preset-includes">
                            Incluye: <?= e(implode(' + ', $itemNames)) ?>
                        </span>
                        <span class="combo-preset-prices">
                            Combo <?= money($c['public_price']) ?>
                            <?php if ($savings > 0.009): ?>
                                <span class="combo-solo-strike"><?= money($c['solo_sum']) ?></span>
                                <span class="combo-save-pill">Ahorras <?= money($savings) ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($c['items'])): ?>
                            <ul class="combo-item-prices muted">
                                <?php foreach ($c['items'] as $it): ?>
                                    <?php
                                    $solo = (float) ($it['public_price'] ?? 0) > 0
                                        ? (float) $it['public_price']
                                        : (float) ($it['catalog_price'] ?? 0);
                                    ?>
                                    <li><?= e((string) $it['name']) ?> · <?= money($solo) ?> suelto</li>
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

    <?php if ($comboAddons !== []): ?>
        <div class="combo-addon-section">
            <div class="combo-addon-title">Armar a la carta</div>
            <?php foreach ($comboAddons as $addon): ?>
                <?php
                $type = (string) ($addon['type'] ?? '');
                $typeLabel = match ($type) {
                    'course' => 'Curso',
                    'certification' => 'Certificación',
                    'procedure' => 'Trámite',
                    default => 'Extra',
                };
                ?>
                <label class="combo-addon-row">
                    <input type="checkbox" class="combo-addon" value="<?= (int) $addon['id'] ?>"
                           data-type="<?= e($type) ?>">
                    <span>
                        <strong><?= e($typeLabel) ?>:</strong> <?= e((string) $addon['name']) ?>
                        <span class="muted" style="font-size:.8rem"> (<?= money($addon['public_price']) ?> suelto)</span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="muted" id="combo-match-hint" style="font-size:.82rem;margin:.65rem 0 0"></p>
    <?php endif; ?>
</div>
