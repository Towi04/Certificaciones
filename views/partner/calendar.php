<?php
/** @var array<string,mixed> $partner */
/** @var int $days */
/** @var array<string, list<array<string,mixed>>> $grouped */
$days = in_array((int) ($days ?? 30), [30, 60], true) ? (int) $days : 30;
$grouped = is_array($grouped ?? null) ? $grouped : [];
$total = 0;
foreach ($grouped as $list) {
    $total += count($list);
}
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center" data-tour="calendar-header">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Calendario de exámenes</h1>
        <p class="muted" style="margin:.25rem 0 0">
            Próximos exámenes de tus alumnos (<?= (int) $total ?> en los siguientes <?= (int) $days ?> días).
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/partner/alumnos')) ?>">← Alumnos</a>
</div>

<div class="panel" style="margin-top:1rem" data-tour="calendar-range">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <span class="muted" style="font-size:.88rem;font-weight:600">Mostrar:</span>
        <a class="btn btn-sm <?= $days === 30 ? 'btn-accent' : 'btn-ghost' ?>"
           href="<?= e(url('/partner/calendario?dias=30')) ?>">30 días</a>
        <a class="btn btn-sm <?= $days === 60 ? 'btn-accent' : 'btn-ghost' ?>"
           href="<?= e(url('/partner/calendario?dias=60')) ?>">60 días</a>
    </div>
</div>

<div class="panel" style="margin-top:1rem" data-tour="calendar-list">
    <?php if ($grouped === []): ?>
        <div class="empty">
            No hay exámenes programados en los próximos <?= (int) $days ?> días.
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $date => $rows): ?>
            <h2 style="margin:1.1rem 0 .5rem;font-size:1.05rem;color:var(--doceo-blue)">
                <?= e((string) $date) ?>
                <span class="muted" style="font-size:.85rem;font-weight:500">
                    · <?= count($rows) ?> alumno<?= count($rows) === 1 ? '' : 's' ?>
                </span>
            </h2>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Alumno</th>
                        <th>Matrícula</th>
                        <th>Producto</th>
                        <th>Estatus</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr data-tour="calendar-row">
                            <td><?= !empty($row['exam_time']) ? e(substr((string) $row['exam_time'], 0, 5)) : '—' ?></td>
                            <td>
                                <?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''))) ?>
                                <br><span class="muted"><?= e((string) ($row['email'] ?? '')) ?></span>
                            </td>
                            <td><?= e((string) ($row['matricula'] ?? '')) ?></td>
                            <td><?= e((string) ($row['product_name'] ?? '')) ?></td>
                            <td><span class="pill"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                            <td>
                                <a href="<?= e(url('/partner/caso/' . (int) ($row['id'] ?? 0))) ?>">Abrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
