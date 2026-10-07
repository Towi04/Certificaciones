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
$statusClass = [
    'draft' => 'mkt-status--draft',
    'scheduled' => 'mkt-status--scheduled',
    'running' => 'mkt-status--running',
    'paused' => 'mkt-status--paused',
    'completed' => 'mkt-status--completed',
    'cancelled' => 'mkt-status--cancelled',
];
?>
<div class="mkt-page">
    <div class="mkt-header">
        <div>
            <h1 style="margin:0;color:var(--doceo-blue)">Publicidad</h1>
            <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
                Campañas con plantillas de correo hacia clientes o partners.
                El sistema reparte los envíos en el rango de fechas para reducir riesgo de spam.
            </p>
        </div>
        <div class="mkt-header-actions">
            <a class="btn btn-ghost" href="<?= e(url('/admin/publicidad/contactos')) ?>">Clientes anteriores</a>
            <a class="btn btn-accent" href="<?= e(url('/admin/publicidad/nueva')) ?>">Nueva campaña</a>
        </div>
    </div>

    <div class="mkt-legend" aria-label="Leyenda de estados">
        <?php foreach ($statusLabels as $key => $label): ?>
            <span class="mkt-legend-item">
                <span class="mkt-status <?= e($statusClass[$key] ?? '') ?>"><?= e($label) ?></span>
            </span>
        <?php endforeach; ?>
    </div>

    <div class="panel mkt-panel">
        <?php if ($campaigns === []): ?>
            <div class="mkt-empty">
                <p class="muted" style="margin:0">Aún no hay campañas. Crea una plantilla en
                    <a href="<?= e(url('/admin/correos')) ?>">Plantillas correo</a>
                    y luego programa aquí el envío.
                </p>
            </div>
        <?php else: ?>
            <div class="table-wrap mkt-table-wrap">
                <table class="data mkt-table">
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
                            $failed = (int) ($c['recipients_failed'] ?? 0);
                            $rowClass = 'mkt-row--' . preg_replace('/[^a-z]/', '', $st);
                            ?>
                            <tr class="<?= e($rowClass) ?>">
                                <td>
                                    <a class="mkt-campaign-name" href="<?= e(url('/admin/publicidad/' . (int) $c['id'])) ?>">
                                        <?= e((string) $c['name']) ?>
                                    </a>
                                    <div class="muted" style="font-size:.78rem">#<?= (int) $c['id'] ?></div>
                                </td>
                                <td><code class="mkt-code"><?= e((string) $c['mail_template_code']) ?></code></td>
                                <td style="font-size:.85rem;white-space:nowrap">
                                    <strong><?= e((string) $c['window_start']) ?></strong>
                                    <span class="muted">→</span>
                                    <strong><?= e((string) $c['window_end']) ?></strong>
                                    <?php if (in_array($st, ['running', 'scheduled', 'paused', 'completed'], true)): ?>
                                        <div class="muted" style="font-size:.75rem">
                                            auto · <?= max(1, (int) round(((int) $c['interval_seconds']) / 60)) ?> min
                                            · <?= (int) $c['day_hour_start'] ?>:00–<?= (int) $c['day_hour_end'] ?>:00
                                        </div>
                                    <?php else: ?>
                                        <div class="muted" style="font-size:.75rem">ritmo automático al iniciar</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="mkt-status <?= e($statusClass[$st] ?? '') ?>">
                                        <?= e($statusLabels[$st] ?? $st) ?>
                                    </span>
                                </td>
                                <td style="min-width:200px">
                                    <?php
                                    $counts = [
                                        'total' => $total,
                                        'sent' => $sent,
                                        'pending' => $pending,
                                        'failed' => $failed,
                                        'cancelled' => (int) ($c['recipients_cancelled'] ?? 0),
                                        'skipped' => 0,
                                    ];
                                    $status = $st;
                                    $compact = true;
                                    require __DIR__ . '/_marketing_progress.php';
                                    ?>
                                    <?php if ($total > 0): ?>
                                        <div class="mkt-progress-meta">
                                            <span class="mkt-meta-ok"><?= $sent ?></span> enviados
                                            · <span class="mkt-meta-pending"><?= $pending ?></span> pend.
                                            <?php if ($failed > 0): ?>
                                                · <span class="mkt-meta-fail"><?= $failed ?></span> fallidos
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="mkt-actions">
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
</div>

<style>
.mkt-page { display:grid; gap:1rem; }
.mkt-header {
  display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap;
}
.mkt-header-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
.mkt-legend {
  display:flex; flex-wrap:wrap; gap:.55rem .85rem; align-items:center;
  padding:.65rem .9rem; border-radius:12px; border:1px solid #e6ebf2; background:#f8fafc;
}
.mkt-legend-item { display:inline-flex; align-items:center; gap:.35rem; font-size:.8rem; color:#64748b; }
.mkt-panel { padding:0; overflow:hidden; border:1px solid #e6ebf2; box-shadow:0 8px 24px rgba(15,23,42,.05); }
.mkt-empty { padding:1.25rem 1.1rem; }
.mkt-table-wrap { max-height:min(70vh, 820px); overflow:auto; }
.mkt-table { margin:0; border-collapse:separate; border-spacing:0; min-width:860px; }
.mkt-table th {
  position:sticky; top:0; z-index:2; background:#f7fafc;
  box-shadow:inset 0 -1px #e6ebf2; font-size:.78rem; text-transform:uppercase;
  letter-spacing:.03em; color:#64748b; font-weight:800;
}
.mkt-table td, .mkt-table th { vertical-align:middle; padding:.75rem .85rem; }
.mkt-table tbody tr { transition:background .12s ease; }
.mkt-table tbody tr:hover { background:#f8fafc; }
.mkt-table tr.mkt-row--running { background:#f0f7ff; }
.mkt-table tr.mkt-row--scheduled { background:#f5f3ff; }
.mkt-table tr.mkt-row--paused { background:#fffbeb; }
.mkt-table tr.mkt-row--completed { background:#f0fdf4; }
.mkt-table tr.mkt-row--cancelled { background:#fef2f2; }
.mkt-campaign-name {
  font-weight:800; color:var(--doceo-blue); text-decoration:none;
}
.mkt-campaign-name:hover { text-decoration:underline; }
.mkt-code {
  display:inline-block; padding:.15rem .4rem; border-radius:6px;
  background:#eef2ff; color:#3730a3; font-size:.78rem;
}
.mkt-status {
  display:inline-flex; align-items:center; padding:.22rem .55rem; border-radius:999px;
  font-size:.75rem; font-weight:800; border:1px solid transparent; line-height:1.2;
}
.mkt-status--draft { background:#f1f5f9; color:#475569; border-color:#e2e8f0; }
.mkt-status--scheduled { background:#ede9fe; color:#5b21b6; border-color:#ddd6fe; }
.mkt-status--running { background:#dbeafe; color:#1d4ed8; border-color:#bfdbfe; }
.mkt-status--paused { background:#fef3c7; color:#92400e; border-color:#fde68a; }
.mkt-status--completed { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
.mkt-status--cancelled { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
.mkt-progress-meta { margin-top:.35rem; font-size:.75rem; color:#64748b; }
.mkt-meta-ok { color:#16a34a; font-weight:700; }
.mkt-meta-pending { color:#2563eb; font-weight:700; }
.mkt-meta-fail { color:#dc2626; font-weight:700; }
.mkt-actions { white-space:nowrap; }
</style>
