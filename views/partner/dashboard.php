<?php /** @var array<string,mixed>|null $partner */ ?>
<h1 style="margin-top:0;color:var(--doceo-blue)">
    <?= e($partner['display_name'] ?? 'Partner') ?>
</h1>
<?php if ($partner): ?>
    <div class="stats" style="margin-bottom:1rem">
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
        <div class="empty">Aún no hay alumnos registrados con tu código.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Matrícula</th><th>Alumno</th><th>Producto</th><th>Estatus</th></tr></thead>
                <tbody>
                <?php foreach ($trackings as $t): ?>
                    <tr>
                        <td><?= e($t['matricula']) ?></td>
                        <td><?= e(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name_p'] ?? ''))) ?><br><span class="muted"><?= e($t['email']) ?></span></td>
                        <td><?= e($t['product_name']) ?></td>
                        <td><span class="pill"><?= e($t['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
