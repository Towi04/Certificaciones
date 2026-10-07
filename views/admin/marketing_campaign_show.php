<?php
/** @var array<string,mixed> $campaign */
/** @var array<string,mixed> $audience */
/** @var array{count:int,samples:list<array<string,mixed>>} $preview */
/** @var array<string,int> $counts */
/** @var list<array<string,mixed>> $recipients */
/** @var string|null $statusFilter */
/** @var array<string,mixed> $pagination */
$statusLabels = [
    'draft' => 'Borrador',
    'scheduled' => 'Programada',
    'running' => 'Enviando',
    'paused' => 'Pausada',
    'completed' => 'Completada',
    'cancelled' => 'Cancelada',
];
$st = (string) ($campaign['status'] ?? 'draft');
$cid = (int) $campaign['id'];
?>
<p class="meta"><a href="<?= e(url('/admin/publicidad')) ?>">← Publicidad</a></p>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e((string) $campaign['name']) ?></h1>
        <p class="muted" style="margin:.2rem 0 0">
            Estado: <span class="pill"><?= e($statusLabels[$st] ?? $st) ?></span>
            · Plantilla <code><?= e((string) $campaign['mail_template_code']) ?></code>
            <?php if (!empty($campaign['promo_code'])): ?>
                · Promo <code><?= e((string) $campaign['promo_code']) ?></code>
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:.45rem;flex-wrap:wrap">
        <?php if (in_array($st, ['draft', 'paused'], true)): ?>
            <a class="btn btn-ghost" href="<?= e(url('/admin/publicidad/' . $cid . '/editar')) ?>">Editar</a>
            <form method="post" action="<?= e(url('/admin/publicidad/' . $cid . '/iniciar')) ?>"
                  onsubmit="return confirm('¿Programar el envío escalonado a la audiencia actual?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-accent">Iniciar envío</button>
            </form>
        <?php endif; ?>
        <?php if (in_array($st, ['running', 'scheduled'], true)): ?>
            <form method="post" action="<?= e(url('/admin/publicidad/' . $cid . '/pausar')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost">Pausar</button>
            </form>
        <?php endif; ?>
        <?php if ($st === 'paused'): ?>
            <form method="post" action="<?= e(url('/admin/publicidad/' . $cid . '/reanudar')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost">Reanudar</button>
            </form>
        <?php endif; ?>
        <?php if (!in_array($st, ['completed', 'cancelled'], true)): ?>
            <form method="post" action="<?= e(url('/admin/publicidad/' . $cid . '/cancelar')) ?>"
                  onsubmit="return confirm('¿Cancelar la campaña y los envíos pendientes?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost" style="color:#b42318">Cancelar</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (($counts['total'] ?? 0) > 0 || in_array($st, ['running', 'scheduled', 'paused', 'completed', 'cancelled'], true)): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin:.2rem 0 .75rem;font-size:1.05rem;color:var(--doceo-blue)">Progreso del envío</h2>
    <?php
    $compact = false;
    $status = $st;
    require __DIR__ . '/_marketing_progress.php';
    ?>
    <div style="display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;margin-top:.75rem;font-size:.85rem">
        <span><strong style="color:#16a34a"><?= (int) ($counts['sent'] ?? 0) ?></strong> enviados</span>
        <span><strong style="color:#2563eb"><?= (int) ($counts['pending'] ?? 0) ?></strong> pendientes</span>
        <span><strong style="color:#dc2626"><?= (int) ($counts['failed'] ?? 0) ?></strong> fallidos</span>
        <?php if ((int) ($counts['cancelled'] ?? 0) > 0): ?>
            <span><strong><?= (int) $counts['cancelled'] ?></strong> cancelados</span>
        <?php endif; ?>
        <span class="muted">Total cola: <?= (int) ($counts['total'] ?? 0) ?></span>
    </div>
    <?php if (in_array($st, ['running', 'scheduled', 'paused'], true)): ?>
        <p class="muted" style="margin:.65rem 0 0;font-size:.8rem">
            El progreso se actualiza cuando corre el cron
            (<code>php bin/process-scheduled-mails.php</code>). Recarga esta página para ver el avance.
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin:.2rem 0 .75rem;font-size:1.05rem;color:var(--doceo-blue)">Programación</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;font-size:.9rem">
        <div><span class="muted">Ventana</span><br>
            <strong><?= e((string) $campaign['window_start']) ?></strong>
            → <strong><?= e((string) $campaign['window_end']) ?></strong>
        </div>
        <div><span class="muted">Intervalo</span><br>
            <strong><?= max(1, (int) round(((int) $campaign['interval_seconds']) / 60)) ?> min</strong>
            entre correos
        </div>
        <div><span class="muted">Horario diario</span><br>
            <strong><?= (int) $campaign['day_hour_start'] ?>:00 – <?= (int) $campaign['day_hour_end'] ?>:00</strong>
        </div>
        <div><span class="muted">Máx. por cron</span><br>
            <strong><?= (int) $campaign['max_per_run'] ?></strong> correos
        </div>
    </div>
    <?php if (!empty($campaign['notes'])): ?>
        <p class="muted" style="margin:.85rem 0 0;font-size:.85rem"><?= e((string) $campaign['notes']) ?></p>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:.85rem">
    <h2 style="margin:.2rem 0 .75rem;font-size:1.05rem;color:var(--doceo-blue)">Audiencia</h2>
    <ul class="muted" style="margin:0;padding-left:1.1rem;font-size:.88rem;line-height:1.5">
        <li><?= !empty($audience['include_students']) ? 'Incluye' : 'No incluye' ?> compradores del sistema</li>
        <li><?= !empty($audience['include_partners']) ? 'Incluye' : 'No incluye' ?> partners</li>
        <li><?= !empty($audience['include_legacy']) ? 'Incluye' : 'No incluye' ?> clientes anteriores (CSV)</li>
        <?php if (!empty($audience['product_id'])): ?>
            <li>Filtro producto #<?= (int) $audience['product_id'] ?></li>
        <?php endif; ?>
        <?php if (!empty($audience['supplier_id'])): ?>
            <li>Filtro proveedor #<?= (int) $audience['supplier_id'] ?></li>
        <?php endif; ?>
        <?php if (!empty($audience['certifier_id'])): ?>
            <li>Filtro certificadora #<?= (int) $audience['certifier_id'] ?></li>
        <?php endif; ?>
    </ul>
    <?php if (in_array($st, ['draft', 'paused'], true)): ?>
        <p style="margin:.75rem 0 0;font-weight:600">
            Estimado actual: <?= (int) ($preview['count'] ?? 0) ?> destinatarios únicos
        </p>
        <?php if (!empty($preview['samples'])): ?>
            <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">
                Muestra:
                <?php
                $bits = [];
                foreach (array_slice($preview['samples'], 0, 6) as $s) {
                    $bits[] = e((string) ($s['email'] ?? ''));
                }
                echo implode(', ', $bits);
                ?>
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p style="margin:.75rem 0 0;font-weight:600">
            Cola: <?= (int) ($counts['total'] ?? 0) ?> ·
            enviados <?= (int) ($counts['sent'] ?? 0) ?> ·
            pendientes <?= (int) ($counts['pending'] ?? 0) ?> ·
            fallidos <?= (int) ($counts['failed'] ?? 0) ?>
        </p>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:.85rem">
    <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:.65rem">
        <h2 style="margin:0;font-size:1.05rem;color:var(--doceo-blue)">Destinatarios</h2>
        <form method="get" action="<?= e(url('/admin/publicidad/' . $cid)) ?>" style="display:flex;gap:.4rem;align-items:center">
            <select name="status" style="padding:.4rem .55rem;border:1px solid #cfd8e6;border-radius:8px">
                <option value="">Todos</option>
                <?php foreach (['pending','sent','failed','cancelled','skipped'] as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">Filtrar</button>
        </form>
    </div>
    <?php if ($recipients === []): ?>
        <p class="muted" style="margin:0">
            <?= in_array($st, ['draft', 'paused'], true)
                ? 'Aún no hay cola. Pulsa «Iniciar envío» para generar la programación.'
                : 'Sin destinatarios en este filtro.' ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Correo</th>
                        <th>Nombre</th>
                        <th>Origen</th>
                        <th>Programado</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recipients as $r): ?>
                        <tr>
                            <td><?= e((string) $r['email']) ?></td>
                            <td><?= e((string) ($r['full_name'] ?: $r['first_name'] ?: '—')) ?></td>
                            <td class="muted" style="font-size:.8rem">
                                <?= e((string) ($r['source'] ?? '')) ?>
                                <?php if (!empty($r['product_label'])): ?>
                                    · <?= e((string) $r['product_label']) ?>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.82rem"><?= e((string) ($r['scheduled_at'] ?? '')) ?></td>
                            <td>
                                <span class="pill"><?= e((string) ($r['status'] ?? '')) ?></span>
                                <?php if (!empty($r['last_error'])): ?>
                                    <div class="muted" style="font-size:.72rem;max-width:16rem"><?= e((string) $r['last_error']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
            <p class="muted" style="margin:.75rem 0 0;font-size:.82rem">
                Página <?= (int) ($pagination['page'] ?? 1) ?> de <?= (int) $pagination['total_pages'] ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
