<?php
/** @var array<string,mixed> $product */
/** @var array<string,int> $stock */
/** @var list<array<string,mixed>> $lots */
/** @var list<array<string,mixed>> $codes */
/** @var array<string,mixed> $inventoryConfig */
/** @var list<array<string,mixed>> $pendingRestock */
$stock = $stock ?? ['available' => 0, 'assigned' => 0, 'total' => 0];
$lots = $lots ?? [];
$codes = $codes ?? [];
$pendingRestock = $pendingRestock ?? [];
$cfg = $inventoryConfig ?? [];
?>
<p class="meta"><a href="<?= e(url('/admin/inventario')) ?>">← Inventario</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e((string) ($product['name'] ?? 'Producto')) ?></h1>
<p class="muted"><code><?= e((string) ($product['code'] ?? '')) ?></code>
    · disponibles <strong><?= (int) ($stock['available'] ?? 0) ?></strong>
    · asignados <?= (int) ($stock['assigned'] ?? 0) ?>
    · umbral alerta <?= (int) ($cfg['low_stock_threshold'] ?? 5) ?>
</p>

<div class="panel" style="margin-top:1rem;max-width:820px">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Subir lote (folio + clave)</h2>
    <p class="muted" style="font-size:.85rem">
        Una línea por código: <code>folio,clave</code> (también acepta tabulador o <code>|</code>).
        Ejemplo de compra de 10:
    </p>
    <form method="post" action="<?= e(url('/admin/inventario/' . (int) $product['id'] . '/lote')) ?>">
        <?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:.75rem">
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Etiqueta del lote
                <input type="text" name="label" placeholder="iTEP marzo 2026 · lote 10"
                       style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Fecha de compra
                <input type="date" name="purchased_at" value="<?= e(date('Y-m-d')) ?>"
                       style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Costo total (opcional)
                <input type="number" step="0.01" min="0" name="cost_total"
                       style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Alerta stock ≤
                <input type="number" min="0" name="low_stock_threshold"
                       value="<?= e((string) ($cfg['low_stock_threshold'] ?? 5)) ?>"
                       style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
        </div>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Códigos
            <textarea name="codes_text" rows="10" required
                      placeholder="FOLIO001,CLAVE001&#10;FOLIO002,CLAVE002"
                      style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem"></textarea>
        </label>
        <button class="btn btn-accent" type="submit" style="margin-top:.75rem">Importar lote</button>
    </form>
</div>

<?php if ($pendingRestock !== []): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:#9a3412">Esperando reposición</h2>
    <p class="muted" style="font-size:.85rem">Alumnos a los que se les quitó el código por una venta urgente. Al importar un lote se reasignan solos.</p>
    <ul>
        <?php foreach ($pendingRestock as $row): ?>
            <li>
                <a href="<?= e(url('/admin/seguimientos/' . (int) $row['id'])) ?>">
                    <?= e((string) ($row['matricula'] ?? ('#' . $row['id']))) ?>
                </a>
                · examen <?= e((string) ($row['exam_date'] ?? '—')) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Lotes</h2>
    <?php if ($lots === []): ?>
        <p class="muted">Aún no hay lotes.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>ID</th><th>Etiqueta</th><th>Compra</th><th>Disp.</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($lots as $lot): ?>
                    <tr>
                        <td><?= (int) $lot['id'] ?></td>
                        <td><?= e((string) ($lot['label'] ?? '')) ?></td>
                        <td><?= e((string) ($lot['purchased_at'] ?? '—')) ?></td>
                        <td><?= (int) ($lot['codes_available'] ?? 0) ?></td>
                        <td><?= (int) ($lot['codes_total'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Códigos recientes</h2>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr><th>Folio</th><th>Clave</th><th>Estado</th><th>Asignado a</th><th>Examen</th><th>Caduca</th></tr>
            </thead>
            <tbody>
            <?php foreach ($codes as $c): ?>
                <tr>
                    <td><code><?= e((string) ($c['code_primary'] ?? '')) ?></code></td>
                    <td><code><?= e((string) ($c['code_secondary'] ?? '')) ?></code></td>
                    <td><?= e((string) ($c['status'] ?? '')) ?></td>
                    <td>
                        <?php if (!empty($c['assigned_tracking_id'])): ?>
                            <a href="<?= e(url('/admin/seguimientos/' . (int) $c['assigned_tracking_id'])) ?>">
                                <?= e((string) ($c['matricula'] ?? ('#' . $c['assigned_tracking_id']))) ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($c['tracking_exam_date'] ?? '—')) ?></td>
                    <td><?= e((string) ($c['expires_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($codes === []): ?>
                <tr><td colspan="6" class="muted">Sin códigos.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
