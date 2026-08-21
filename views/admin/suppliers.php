<h1 style="margin-top:0;color:var(--doceo-blue)">Proveedores</h1>
<p class="muted">Ficha con contactos y cuentas de plataforma (claves encriptadas) en siguientes iteraciones.</p>
<div class="panel">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Código</th><th>Nombre</th><th>Sitio</th><th>Activo</th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
                <tr>
                    <td><?= e($s['code']) ?></td>
                    <td><?= e($s['name']) ?></td>
                    <td><?= e($s['website'] ?? '—') ?></td>
                    <td><?= (int) $s['is_active'] ? 'Sí' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($suppliers === []): ?>
                <tr><td colspan="4" class="muted">Sin proveedores. El seed crea los principales.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
