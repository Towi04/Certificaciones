<?php
/** @var list<array<string,mixed>> $partners */
/** @var string $q */
/** @var array<string,string> $tierLabels */
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;color:var(--doceo-blue)">Partners</h1>
    <a class="btn btn-accent" href="<?= e(url('/admin/partners/nuevo')) ?>">Nuevo partner</a>
</div>
<p class="muted">Crea cuentas de partner y asigna el nivel de precio (CNCM / A / B / C).</p>

<form method="get" style="margin:1rem 0;display:flex;gap:.5rem;flex-wrap:wrap;max-width:420px">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Código, nombre o correo…"
           style="flex:1;padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
    <button class="btn btn-primary" type="submit">Buscar</button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Nivel</th>
                <th>Crédito</th>
                <th>Activo</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($partners as $p): ?>
                <tr>
                    <td><code><?= e($p['code']) ?></code></td>
                    <td><?= e($p['display_name']) ?></td>
                    <td><?= e($p['email']) ?></td>
                    <td><span class="pill"><?= e($tierLabels[$p['tier']] ?? strtoupper((string) $p['tier'])) ?></span></td>
                    <td><?= money($p['credit_balance']) ?></td>
                    <td><?= (int) $p['is_active'] ? 'Sí' : 'No' ?></td>
                    <td><a href="<?= e(url('/admin/partners/' . $p['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($partners === []): ?>
                <tr>
                    <td colspan="7" class="muted">
                        Aún no hay partners.
                        <a href="<?= e(url('/admin/partners/nuevo')) ?>">Registra el primero</a>.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
