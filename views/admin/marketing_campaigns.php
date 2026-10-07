<?php
/** @var list<array<string,mixed>> $campaigns */
$statusLabels = [
    'draft' => 'Borrador',
    'scheduled' => 'Programada',
    'running' => 'Enviando',
    'paused' => 'Pausada',
    'completed' => 'Completada',
    'cancelled' => 'Cancelada',
];
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Publicidad</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Campañas con plantillas de correo hacia compradores, partners o clientes anteriores.
            Los envíos se escalonan (intervalo + ventana de fechas) para reducir riesgo de spam.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= e(url('/admin/publicidad/contactos')) ?>">Clientes anteriores</a>
        <a class="btn btn-accent" href="<?= e(url('/admin/publicidad/nueva')) ?>">Nueva campaña</a>
    </div>
</div>

<div class="panel" style="margin-top:1rem">
    <?php if ($campaigns === []): ?>
        <p class="muted" style="margin:0">Aún no hay campañas. Crea una plantilla en
            <a href="<?= e(url('/admin/correos')) ?>">Plantillas correo</a>
            y luego programa aquí el envío.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Campaña</th>
                        <th>Plantilla</th>
                        <th>Ventana</th>
                        <th>Estado</th>
                        <th>Progreso</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $c): ?>
                        <?php
                        $st = (string) ($c['status'] ?? 'draft');
                        $sent = (int) ($c['recipients_sent'] ?? 0);
                        $total = (int) ($c['recipients_total'] ?? 0);
                        $pending = (int) ($c['recipients_pending'] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $c['name']) ?></strong>
                                <div class="muted" style="font-size:.78rem">#<?= (int) $c['id'] ?></div>
                            </td>
                            <td><code><?= e((string) $c['mail_template_code']) ?></code></td>
                            <td style="font-size:.85rem">
                                <?= e((string) $c['window_start']) ?> → <?= e((string) $c['window_end']) ?>
                                <div class="muted" style="font-size:.75rem">
                                    cada <?= max(1, (int) round(((int) $c['interval_seconds']) / 60)) ?> min
                                    · <?= (int) $c['day_hour_start'] ?>:00–<?= (int) $c['day_hour_end'] ?>:00
                                </div>
                            </td>
                            <td><span class="pill"><?= e($statusLabels[$st] ?? $st) ?></span></td>
                            <td style="font-size:.85rem">
                                <?php if ($total > 0): ?>
                                    <?= $sent ?>/<?= $total ?> enviados
                                    <?php if ($pending > 0): ?>
                                        <span class="muted">· <?= $pending ?> pendientes</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">Sin cola</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/publicidad/' . (int) $c['id'])) ?>">Ver</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="muted" style="margin-top:1rem;font-size:.82rem;max-width:48rem">
    Cron sugerido (mismo que inventario/correos diferidos):
    <code>php bin/process-scheduled-mails.php</code> cada 10–15 minutos.
    Procesa también la cola de publicidad.
</p>
