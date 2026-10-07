<?php
/** @var list<array<string,mixed>> $contacts */
/** @var string $q */
/** @var int $total */
/** @var array<string,mixed> $pagination */
$hints = \App\Services\MarketingContactService::historicalProductHints();
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <p class="meta" style="margin:0"><a href="<?= e(url('/admin/publicidad')) ?>">← Publicidad</a></p>
        <h1 style="margin:.2rem 0;color:var(--doceo-blue)">Clientes anteriores</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Importa una vez las ventas históricas. <strong>No necesitas códigos de producto</strong>:
            en la columna <code>producto</code> escribe el nombre como lo conocías
            (ej. <code>TOEFL ITP</code>, <code>ELET + CENNI</code>, <code>Excel</code>).
            No se crean cuentas; solo quedan para campañas.
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/publicidad/contactos/plantilla.csv')) ?>">Descargar plantilla CSV</a>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin:.1rem 0 .55rem;font-size:1.02rem;color:var(--doceo-blue)">Qué poner en la columna producto</h2>
    <p class="muted" style="margin:0 0 .65rem;font-size:.85rem;max-width:48rem">
        Copia el texto de <strong>Escribe esto</strong>. Si el producto aún existe en el catálogo,
        el sistema lo enlaza solo (para filtrar campañas). Si ya no existe, igual se importa el texto
        y podrás usar <code>{{product_name}}</code> en el correo.
    </p>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Lo que vendían</th>
                    <th>Escribe esto en el CSV</th>
                    <th>Enlace al catálogo actual</th>
                    <th>Nota</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hints as $h): ?>
                    <tr>
                        <td><strong><?= e($h['label']) ?></strong></td>
                        <td><code><?= e($h['write_as']) ?></code></td>
                        <td>
                            <?php if ($h['maps_to']): ?>
                                <code><?= e($h['maps_to']) ?></code>
                            <?php else: ?>
                                <span class="muted">— (solo texto)</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted" style="font-size:.82rem"><?= e($h['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/publicidad/contactos/importar')) ?>" enctype="multipart/form-data"
      class="panel" style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:end">
    <?= csrf_field() ?>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;flex:1;min-width:220px">
        Subir CSV de clientes anteriores
        <input type="file" name="csv" accept=".csv,text/csv" required>
    </label>
    <button class="btn btn-accent" type="submit">Importar</button>
</form>
<p class="muted" style="margin:.45rem 0 0;font-size:.8rem;max-width:48rem">
    Columnas mínimas: <code>email</code>, <code>full_name</code> (o nombre + apellidos),
    <code>phone</code> (opcional), <code>producto</code> (el nombre de arriba),
    <code>purchased_at</code> (opcional, fecha). También acepta encabezados en español
    (<code>correo</code>, <code>nombre_completo</code>, <code>telefono</code>, <code>certificacion</code>).
</p>

<form method="get" action="<?= e(url('/admin/publicidad/contactos')) ?>" class="panel"
      style="margin-top:.85rem;display:flex;gap:.55rem;flex-wrap:wrap;align-items:end">
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;flex:1;min-width:200px">
        Buscar
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Correo, nombre, producto…"
               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
    </label>
    <button class="btn btn-ghost" type="submit">Buscar</button>
    <span class="muted" style="font-size:.82rem"><?= (int) $total ?> contactos</span>
</form>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>

<div class="panel" style="margin-top:.85rem">
    <?php if ($contacts === []): ?>
        <p class="muted" style="margin:0">No hay contactos importados todavía.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Teléfono</th>
                        <th>Certificación</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $c): ?>
                        <tr>
                            <td>
                                <strong><?= e((string) ($c['full_name'] ?: trim(($c['first_name'] ?? '') . ' ' . ($c['last_name_p'] ?? '')))) ?></strong>
                                <?php if (!empty($c['purchased_at'])): ?>
                                    <div class="muted" style="font-size:.75rem">Compra <?= e((string) $c['purchased_at']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $c['email']) ?></td>
                            <td><?= e((string) ($c['phone'] ?? '—')) ?></td>
                            <td style="font-size:.85rem">
                                <?= e((string) ($c['product_name'] ?? $c['matched_product_name'] ?? '—')) ?>
                                <?php if (!empty($c['matched_product_name']) && ($c['product_name'] ?? '') !== ($c['matched_product_name'] ?? '')): ?>
                                    <div class="muted" style="font-size:.75rem">→ <?= e((string) $c['matched_product_name']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($c['supplier_name'])): ?>
                                    <div class="muted" style="font-size:.75rem"><?= e((string) $c['supplier_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= e(url('/admin/publicidad/contactos/' . (int) $c['id'] . '/eliminar')) ?>"
                                      onsubmit="return confirm('¿Eliminar este contacto?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:#b42318">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php require BASE_PATH . '/views/shared/pagination.php'; ?>
    <?php endif; ?>
</div>
