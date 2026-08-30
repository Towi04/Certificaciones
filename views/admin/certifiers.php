<?php
/** @var list<array<string,mixed>> $certifiers */
/** @var array<int,int> $counts */
$counts = $counts ?? [];
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Casas certificadoras</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Administra logos, sitios web y portales de las casas certificadoras
            (Cambridge, Pearson, etc.) que se asignan a cada producto.
        </p>
    </div>
    <a class="btn btn-accent" href="<?= e(url('/admin/certificadoras/nueva')) ?>">Nueva certificadora</a>
</div>

<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th></th>
                <th>Código</th>
                <th>Nombre</th>
                <th>Sitio / plataforma</th>
                <th>Productos</th>
                <th>Activo</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($certifiers as $c): ?>
                <?php $cid = (int) $c['id']; ?>
                <tr>
                    <td style="width:52px">
                        <?php if (!empty($c['logo_path'])): ?>
                            <img src="<?= e(asset((string) $c['logo_path'])) ?>" alt=""
                                 style="width:40px;height:40px;object-fit:contain;border:1px solid #e6ebf2;border-radius:8px;background:#fff;padding:2px">
                        <?php else: ?>
                            <span class="muted" style="font-size:.75rem">—</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= e((string) $c['code']) ?></code></td>
                    <td><?= e((string) $c['name']) ?></td>
                    <td style="font-size:.85rem">
                        <?php if (!empty($c['website'])): ?>
                            <a href="<?= e((string) $c['website']) ?>" target="_blank" rel="noopener">Web</a>
                        <?php endif; ?>
                        <?php if (!empty($c['website']) && !empty($c['platform_url'])): ?> · <?php endif; ?>
                        <?php if (!empty($c['platform_url'])): ?>
                            <a href="<?= e((string) $c['platform_url']) ?>" target="_blank" rel="noopener">Plataforma</a>
                        <?php endif; ?>
                        <?php if (empty($c['website']) && empty($c['platform_url'])): ?>—<?php endif; ?>
                    </td>
                    <td><?= (int) ($counts[$cid] ?? 0) ?></td>
                    <td><?= !empty($c['is_active']) ? 'Sí' : 'No' ?></td>
                    <td><a href="<?= e(url('/admin/certificadoras/' . $cid)) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($certifiers === []): ?>
                <tr><td colspan="7" class="muted">Sin certificadoras. Crea una o ejecuta el seed de catálogo.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>
