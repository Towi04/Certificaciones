<?php
/** @var list<array<string,mixed>> $mailCards */
/** @var array<string,string> $mailCardDefaults */
/** @var list<array<string,mixed>> $mailCardProducts */
$mailCards = is_array($mailCards ?? null) ? $mailCards : [];
$mailCardDefaults = is_array($mailCardDefaults ?? null)
    ? $mailCardDefaults
    : \App\Services\MailProductCardService::defaults();
$mailCardProducts = is_array($mailCardProducts ?? null) ? $mailCardProducts : [];
$cardSvc = new \App\Services\MailProductCardService();
$inputStyle = 'padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;box-sizing:border-box';
$labelStyle = 'display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600';
?>
<div class="mail-panel" data-panel="cards" hidden id="mail-cards">
    <div class="panel" style="margin-top:.75rem">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Tarjetas de catálogo para correos</h2>
        <p class="muted" style="margin:0 0 1rem;font-size:.88rem;max-width:52rem">
            Define placeholders como <code>{{elet}}</code> que insertan automáticamente una tarjeta
            con logo, nombre y enlace al producto del catálogo.
            También puedes armar un grid: <code>{{cards:elet,toefl,excel}}</code>.
            El % de descuento se calcula con precio lista (catálogo) vs precio público.
        </p>

        <form method="post" action="<?= e(url('/admin/correos/tarjetas/defaults')) ?>"
              style="display:grid;gap:.75rem;padding:1rem;border:1px solid #e6ebf2;border-radius:12px;background:#f8fafc;margin-bottom:1.1rem">
            <?= csrf_field() ?>
            <h3 style="margin:0;font-size:.95rem;color:var(--doceo-blue)">Diseño por defecto</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.65rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Layout
                    <select name="default_layout" style="<?= e($inputStyle) ?>">
                        <option value="wide" <?= ($mailCardDefaults['default_layout'] ?? '') === 'wide' ? 'selected' : '' ?>>Rectangular grande</option>
                        <option value="square" <?= ($mailCardDefaults['default_layout'] ?? '') === 'square' ? 'selected' : '' ?>>Cuadro pequeño</option>
                        <option value="row" <?= ($mailCardDefaults['default_layout'] ?? '') === 'row' ? 'selected' : '' ?>>Horizontal + descripción</option>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Badge
                    <select name="default_badge" style="<?= e($inputStyle) ?>">
                        <option value="discount" <?= ($mailCardDefaults['default_badge'] ?? '') === 'discount' ? 'selected' : '' ?>>% de descuento</option>
                        <option value="banner" <?= ($mailCardDefaults['default_badge'] ?? '') === 'banner' ? 'selected' : '' ?>>Franja / bubble de texto</option>
                        <option value="both" <?= ($mailCardDefaults['default_badge'] ?? '') === 'both' ? 'selected' : '' ?>>Ambos</option>
                        <option value="none" <?= ($mailCardDefaults['default_badge'] ?? '') === 'none' ? 'selected' : '' ?>>Sin badge</option>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Texto de franja
                    <input type="text" name="banner_text" maxlength="80" style="<?= e($inputStyle) ?>"
                           value="<?= e((string) ($mailCardDefaults['banner_text'] ?? 'Solicita tu descuento')) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Color acento
                    <input type="color" name="accent_color" style="height:40px;<?= e($inputStyle) ?>"
                           value="<?= e((string) ($mailCardDefaults['accent_color'] ?? '#315285')) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Texto del botón
                    <input type="text" name="cta_label" maxlength="40" style="<?= e($inputStyle) ?>"
                           value="<?= e((string) ($mailCardDefaults['cta_label'] ?? 'Ver en catálogo')) ?>">
                </label>
            </div>
            <div>
                <button type="submit" class="btn btn-ghost btn-sm">Guardar diseño</button>
            </div>
        </form>

        <form method="post" action="<?= e(url('/admin/correos/tarjetas')) ?>"
              style="display:grid;gap:.75rem;padding:1rem;border:1px solid #dbeafe;border-radius:12px;background:#f4f7fb;margin-bottom:1.1rem">
            <?= csrf_field() ?>
            <h3 style="margin:0;font-size:.95rem;color:var(--doceo-blue)">Nueva tarjeta</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.65rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Placeholder *
                    <input type="text" name="placeholder" required pattern="[a-zA-Z][a-zA-Z0-9_\-]{1,40}"
                           placeholder="elet" style="<?= e($inputStyle) ?>">
                    <span style="font-weight:500;font-size:.75rem">Se usará como <code>{{elet}}</code></span>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Producto *
                    <select name="product_id" required style="<?= e($inputStyle) ?>">
                        <option value="">— elige —</option>
                        <?php foreach ($mailCardProducts as $p): ?>
                            <option value="<?= (int) $p['id'] ?>">
                                <?= e((string) $p['name']) ?>
                                <?= !empty($p['code']) ? ' · ' . e((string) $p['code']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Layout
                    <select name="layout" style="<?= e($inputStyle) ?>">
                        <option value="wide">Rectangular grande</option>
                        <option value="square">Cuadro pequeño</option>
                        <option value="row">Horizontal + descripción</option>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Badge
                    <select name="badge_mode" style="<?= e($inputStyle) ?>">
                        <option value="discount">% de descuento</option>
                        <option value="banner">Franja de texto</option>
                        <option value="both">Ambos</option>
                        <option value="none">Ninguno</option>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Texto franja (opcional)
                    <input type="text" name="badge_text" maxlength="80" style="<?= e($inputStyle) ?>"
                           placeholder="Solicita tu descuento">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Orden
                    <input type="number" name="sort_order" min="0" max="9999" value="0" style="<?= e($inputStyle) ?>">
                </label>
            </div>
            <label style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                <input type="checkbox" name="show_description" value="1">
                Mostrar descripción breve
            </label>
            <label style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                <input type="checkbox" name="is_active" value="1" checked>
                Activa
            </label>
            <div>
                <button type="submit" class="btn btn-accent btn-sm">Crear tarjeta</button>
            </div>
        </form>

        <?php if ($mailCards === []): ?>
            <p class="muted" style="margin:0">Aún no hay tarjetas. Crea una (ej. placeholder <code>elet</code>) y úsala en una plantilla de publicidad.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-bottom:1rem">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Placeholder</th>
                            <th>Producto</th>
                            <th>Layout</th>
                            <th>Badge</th>
                            <th>%</th>
                            <th>Activa</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mailCards as $card): ?>
                            <?php
                            $pct = \App\Services\MailProductCardService::discountPercent(
                                (float) ($card['catalog_price'] ?? 0),
                                (float) ($card['public_price'] ?? 0)
                            );
                            $cid = (int) $card['id'];
                            ?>
                            <tr>
                                <td><code>{{<?= e((string) $card['placeholder']) ?>}}</code></td>
                                <td>
                                    <strong><?= e((string) ($card['product_name'] ?? '')) ?></strong>
                                    <div class="muted" style="font-size:.75rem"><?= e((string) ($card['product_code'] ?? '')) ?></div>
                                </td>
                                <td><?= e((string) $card['layout']) ?></td>
                                <td><?= e((string) $card['badge_mode']) ?></td>
                                <td><?= $pct > 0 ? ('-' . $pct . '%') : '—' ?></td>
                                <td><?= !empty($card['is_active']) ? 'Sí' : 'No' ?></td>
                                <td>
                                    <details>
                                        <summary class="btn btn-ghost btn-sm" style="cursor:pointer;list-style:none">Editar</summary>
                                        <form method="post" action="<?= e(url('/admin/correos/tarjetas/' . $cid)) ?>"
                                              style="display:grid;gap:.55rem;margin-top:.65rem;padding:.75rem;border:1px solid #e6ebf2;border-radius:10px;background:#fff;min-width:260px">
                                            <?= csrf_field() ?>
                                            <label class="muted" style="<?= e($labelStyle) ?>">
                                                Placeholder
                                                <input type="text" name="placeholder" required
                                                       value="<?= e((string) $card['placeholder']) ?>" style="<?= e($inputStyle) ?>">
                                            </label>
                                            <label class="muted" style="<?= e($labelStyle) ?>">
                                                Producto
                                                <select name="product_id" required style="<?= e($inputStyle) ?>">
                                                    <?php foreach ($mailCardProducts as $p): ?>
                                                        <option value="<?= (int) $p['id'] ?>"
                                                            <?= ((int) $card['product_id'] === (int) $p['id']) ? 'selected' : '' ?>>
                                                            <?= e((string) $p['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="muted" style="<?= e($labelStyle) ?>">
                                                Layout
                                                <select name="layout" style="<?= e($inputStyle) ?>">
                                                    <?php foreach (['wide' => 'Rectangular', 'square' => 'Cuadro', 'row' => 'Horizontal'] as $val => $lab): ?>
                                                        <option value="<?= e($val) ?>" <?= ($card['layout'] ?? '') === $val ? 'selected' : '' ?>><?= e($lab) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="muted" style="<?= e($labelStyle) ?>">
                                                Badge
                                                <select name="badge_mode" style="<?= e($inputStyle) ?>">
                                                    <?php foreach (['discount' => '% descuento', 'banner' => 'Franja', 'both' => 'Ambos', 'none' => 'Ninguno'] as $val => $lab): ?>
                                                        <option value="<?= e($val) ?>" <?= ($card['badge_mode'] ?? '') === $val ? 'selected' : '' ?>><?= e($lab) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="muted" style="<?= e($labelStyle) ?>">
                                                Texto franja
                                                <input type="text" name="badge_text" maxlength="80" style="<?= e($inputStyle) ?>"
                                                       value="<?= e((string) ($card['badge_text'] ?? '')) ?>">
                                            </label>
                                            <label class="muted" style="<?= e($labelStyle) ?>">
                                                Orden
                                                <input type="number" name="sort_order" min="0" max="9999"
                                                       value="<?= (int) ($card['sort_order'] ?? 0) ?>" style="<?= e($inputStyle) ?>">
                                            </label>
                                            <label style="display:flex;gap:.4rem;align-items:center;font-size:.85rem">
                                                <input type="checkbox" name="show_description" value="1"
                                                    <?= !empty($card['show_description']) ? 'checked' : '' ?>>
                                                Mostrar descripción
                                            </label>
                                            <label style="display:flex;gap:.4rem;align-items:center;font-size:.85rem">
                                                <input type="checkbox" name="is_active" value="1"
                                                    <?= !empty($card['is_active']) ? 'checked' : '' ?>>
                                                Activa
                                            </label>
                                            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                                                <button type="submit" class="btn btn-accent btn-sm">Guardar</button>
                                            </div>
                                        </form>
                                        <form method="post" action="<?= e(url('/admin/correos/tarjetas/' . $cid . '/eliminar')) ?>"
                                              style="margin-top:.4rem"
                                              onsubmit="return confirm('¿Eliminar esta tarjeta?');">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-ghost btn-sm" style="color:#b42318">Eliminar</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="7" style="background:#f8fafc;padding:.75rem 1rem">
                                    <div class="muted" style="font-size:.75rem;margin-bottom:.35rem">Vista previa</div>
                                    <div style="max-width:420px;background:#fff;padding:.5rem;border-radius:10px;border:1px dashed #cfd8e6">
                                        <?= $cardSvc->renderCard($card) ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
