<?php
/** @var array<string,mixed> $partner */
/** @var list<array<string,mixed>> $items */
/** @var int $unreadCount */
/** @var string $typeFilter */
$items = is_array($items ?? null) ? $items : [];
$unreadCount = (int) ($unreadCount ?? 0);
$typeFilter = in_array(($typeFilter ?? 'all'), ['all', 'access', 'results'], true)
    ? (string) $typeFilter
    : 'all';
$typeLabels = [
    'access' => 'Accesos enviados',
    'results' => 'Resultados listos',
];
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center" data-tour="notifications-header">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Notificaciones</h1>
        <p class="muted" style="margin:.25rem 0 0">
            Accesos enviados a tus alumnos y resultados listos (últimos 60 días).
            <?php if ($unreadCount > 0): ?>
                <strong style="color:var(--doceo-blue)"><?= (int) $unreadCount ?> sin leer</strong> en esta visita.
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <form method="post" action="<?= e(url('/partner/notificaciones/leidas')) ?>" data-tour="notifications-mark-read">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost">Marcar como leídas</button>
        </form>
        <a class="btn btn-ghost" href="<?= e(url('/partner/alumnos')) ?>">← Alumnos</a>
    </div>
</div>

<div class="panel" style="margin-top:1rem" data-tour="notifications-filters">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn <?= $typeFilter === 'all' ? 'btn-accent' : 'btn-ghost' ?>"
           href="<?= e(url('/partner/notificaciones')) ?>">Todas</a>
        <a class="btn <?= $typeFilter === 'access' ? 'btn-accent' : 'btn-ghost' ?>"
           href="<?= e(url('/partner/notificaciones?tipo=access')) ?>">Accesos</a>
        <a class="btn <?= $typeFilter === 'results' ? 'btn-accent' : 'btn-ghost' ?>"
           href="<?= e(url('/partner/notificaciones?tipo=results')) ?>">Resultados</a>
    </div>
</div>

<div class="panel" style="margin-top:1rem" data-tour="notifications-list">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Actividad</h2>
    <?php if ($items === []): ?>
        <div class="empty">
            No hay notificaciones<?= $typeFilter !== 'all' ? ' de este tipo' : '' ?> en los últimos 60 días.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Detalle</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $isUnread = !empty($item['unread']);
                    $type = (string) ($item['type'] ?? '');
                    $tid = (int) ($item['tracking_id'] ?? 0);
                    ?>
                    <tr<?= $isUnread ? ' style="background:rgba(37,99,235,.06)"' : '' ?>
                        data-tour="notifications-row">
                        <td style="white-space:nowrap<?= $isUnread ? ';font-weight:700' : '' ?>">
                            <?= e((string) ($item['at'] ?? '')) ?>
                            <?php if ($isUnread): ?>
                                <br><span class="pill" style="background:#2563eb;color:#fff;font-size:.7rem">Nueva</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill"><?= e($typeLabels[$type] ?? $type) ?></span>
                        </td>
                        <td>
                            <strong><?= e((string) ($item['title'] ?? '')) ?></strong>
                            <br><span class="muted" style="font-size:.9rem"><?= e((string) ($item['body'] ?? '')) ?></span>
                        </td>
                        <td>
                            <?php if ($tid > 0): ?>
                                <a href="<?= e(url('/partner/caso/' . $tid)) ?>">Abrir</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
