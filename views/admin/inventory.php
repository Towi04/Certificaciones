<?php
/** @var list<array<string,mixed>> $products */
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <h1 style="margin:0;color:var(--doceo-blue)">Inventario de códigos</h1>
</div>
<p class="muted">
    Sube lotes de folio/clave (p. ej. iTEP de 10 en 10). El sistema asigna automáticamente según la fecha del examen,
    reasigna códigos de fechas lejanas si hay urgencia, y programa el correo de acceso.
</p>

<div class="panel" style="margin-top:1rem">
    <?php if ($products === []): ?>
        <p class="muted">Ningún producto tiene inventario activo. Actívalo en <strong>Admin → Grupos → Fechas</strong> (sección Inventario) o en el JSON del grupo.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Producto</th>
                    <th>Disponibles</th>
                    <th>Asignados</th>
                    <th>Total</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <?php
                    $stock = is_array($p['stock'] ?? null) ? $p['stock'] : [];
                    $available = (int) ($stock['available'] ?? 0);
                    $threshold = (int) ($p['low_stock_threshold'] ?? 5);
                    $low = $available <= $threshold;
                    ?>
                    <tr>
                        <td>
                            <strong><?= e((string) ($p['name'] ?? '')) ?></strong>
                            <div class="muted" style="font-size:.78rem"><code><?= e((string) ($p['code'] ?? '')) ?></code></div>
                        </td>
                        <td>
                            <strong style="<?= $low ? 'color:#b45309' : '' ?>"><?= $available ?></strong>
                            <?php if ($low): ?>
                                <span class="pill" style="margin-left:.35rem">stock bajo</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) ($stock['assigned'] ?? 0) ?></td>
                        <td><?= (int) ($stock['total'] ?? 0) ?></td>
                        <td>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/inventario/' . (int) $p['id'])) ?>">Gestionar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<p class="muted" style="font-size:.8rem;margin-top:.75rem">
    Cron sugerido: <code>php bin/process-scheduled-mails.php</code> cada 10–15 minutos
    (asignación + correo 3 días antes del examen).
</p>
