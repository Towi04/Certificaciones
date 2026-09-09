<?php
/** @var list<array<string,mixed>> $users */
/** @var string $q */
/** @var int|null $currentUserId */
$currentUserId = $currentUserId ?? \App\Auth\Auth::id();
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;color:var(--doceo-blue)">Usuarios admin</h1>
    <a class="btn btn-accent" href="<?= e(url('/admin/usuarios/nuevo')) ?>">Nuevo admin</a>
</div>
<p class="muted">Crea y administra cuentas con acceso al panel de administración. Puedes resetear contraseñas y activar/desactivar usuarios.</p>

<form method="get" style="margin:1rem 0;display:flex;gap:.5rem;flex-wrap:wrap;max-width:420px">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre o correo…"
           style="flex:1;padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
    <button class="btn btn-primary" type="submit">Buscar</button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Activo</th>
                <th>Último acceso</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                $fullName = trim(
                    (string) ($u['first_name'] ?? '') . ' '
                    . (string) ($u['last_name_p'] ?? '') . ' '
                    . (string) ($u['last_name_m'] ?? '')
                );
                $isSelf = $currentUserId !== null && (int) $u['id'] === (int) $currentUserId;
                ?>
                <tr>
                    <td>
                        <?= e($fullName !== '' ? $fullName : '—') ?>
                        <?php if ($isSelf): ?>
                            <span class="pill" style="margin-left:.35rem">Tú</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($u['email'] ?? '')) ?></td>
                    <td><?= !empty($u['is_active']) ? 'Sí' : 'No' ?></td>
                    <td class="muted">
                        <?= !empty($u['last_login_at'])
                            ? e((string) $u['last_login_at'])
                            : '—' ?>
                    </td>
                    <td><a href="<?= e(url('/admin/usuarios/' . (int) $u['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($users === []): ?>
                <tr>
                    <td colspan="5" class="muted">
                        No hay usuarios admin con ese criterio.
                        <a href="<?= e(url('/admin/usuarios/nuevo')) ?>">Crear uno</a>.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>
