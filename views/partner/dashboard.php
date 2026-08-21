<?php /** @var array<string,mixed>|null $partner */ ?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
    <h1 style="margin:0;color:var(--doceo-blue)">
        <?= e($partner['display_name'] ?? 'Partner') ?>
    </h1>
    <?php if ($partner): ?>
        <a class="btn btn-accent" href="<?= e(url('/partner/registrar')) ?>">Registrar alumno</a>
    <?php endif; ?>
</div>
<?php if ($partner): ?>
    <div class="stats" style="margin:1rem 0">
        <div class="stat">
            <div class="label">Tu código</div>
            <div class="value" style="font-size:1.2rem"><?= e($partner['code']) ?></div>
        </div>
        <div class="stat">
            <div class="label">Nivel</div>
            <div class="value" style="font-size:1.2rem;text-transform:uppercase"><?= e($partner['tier']) ?></div>
        </div>
        <div class="stat">
            <div class="label">Saldo a favor</div>
            <div class="value" style="font-size:1.2rem"><?= money($partner['credit_balance']) ?></div>
        </div>
    </div>
<?php else: ?>
    <div class="flash flash-error">Tu usuario partner no tiene ficha ligada. Contacta a admin.</div>
<?php endif; ?>

<div class="panel">
    <h2>Alumnos / seguimientos</h2>
    <?php if ($trackings === []): ?>
        <div class="empty">Aún no hay alumnos. <a href="<?= e(url('/partner/registrar')) ?>">Registra el primero</a>.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Matrícula</th><th>Alumno</th><th>Producto</th><th>Examen</th><th>Estatus</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($trackings as $t): ?>
                    <tr>
                        <td><?= e($t['matricula']) ?></td>
                        <td><?= e(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name_p'] ?? ''))) ?><br><span class="muted"><?= e($t['email']) ?></span></td>
                        <td><?= e($t['product_name']) ?></td>
                        <td><?= e($t['exam_date'] ?? '—') ?><?php if (!empty($t['exam_time'])): ?> <?= e(substr((string) $t['exam_time'], 0, 5)) ?><?php endif; ?></td>
                        <td><span class="pill"><?= e($t['status']) ?></span></td>
                        <td><a href="<?= e(url('/partner/caso/' . $t['id'])) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
