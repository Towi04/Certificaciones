<?php
/** @var list<array<string,mixed>> $suppliers */
/** @var array<int,array{products:int,groups:int}> $counts */
/** @var list<array<string,mixed>> $certifiers */
/** @var array<int,int> $certifierCounts */
/** @var string $tab */
$counts = $counts ?? [];
$certifiers = $certifiers ?? [];
$certifierCounts = $certifierCounts ?? [];
$tab = ($tab ?? 'proveedores') === 'certificadoras' ? 'certificadoras' : 'proveedores';
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Proveedores y certificadoras</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            <?php if ($tab === 'certificadoras'): ?>
                Administra logos, sitios y portales de las casas certificadoras
                (Cambridge, Pearson, etc.) que se asignan a cada producto.
            <?php else: ?>
                Crea y edita proveedores. En cada ficha usa las pestañas (Datos, Logos, Contactos…)
                y el botón <strong>Guardar todo</strong> para no perder cambios al cambiar de sección.
                links de plataformas y usuarios/contraseñas cifrados, además de cargar
                certificaciones en lote (CSV).
            <?php endif; ?>
        </p>
    </div>
    <?php if ($tab === 'certificadoras'): ?>
        <a class="btn btn-accent" href="<?= e(url('/admin/certificadoras/nueva')) ?>">Nueva certificadora</a>
    <?php else: ?>
        <a class="btn btn-accent" href="<?= e(url('/admin/proveedores/nuevo')) ?>">Nuevo proveedor</a>
    <?php endif; ?>
</div>

<nav class="ops-tabs" style="margin:1rem 0 .85rem;display:flex;flex-wrap:wrap;gap:.4rem" aria-label="Secciones">
    <a class="ops-tab<?= $tab === 'proveedores' ? ' active' : '' ?>"
       href="<?= e(url('/admin/proveedores')) ?>"
       style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .7rem;border-radius:999px;border:1px solid #d5deea;background:<?= $tab === 'proveedores' ? 'var(--doceo-blue)' : '#fff' ?>;color:<?= $tab === 'proveedores' ? '#fff' : 'var(--doceo-blue)' ?>;text-decoration:none;font-size:.84rem;font-weight:600">
        Proveedores
    </a>
    <a class="ops-tab<?= $tab === 'certificadoras' ? ' active' : '' ?>"
       href="<?= e(url('/admin/proveedores?tab=certificadoras')) ?>"
       style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .7rem;border-radius:999px;border:1px solid #d5deea;background:<?= $tab === 'certificadoras' ? 'var(--doceo-blue)' : '#fff' ?>;color:<?= $tab === 'certificadoras' ? '#fff' : 'var(--doceo-blue)' ?>;text-decoration:none;font-size:.84rem;font-weight:600">
        Certificadoras
    </a>
</nav>

<?php if ($tab === 'certificadoras'): ?>
    <div class="panel" style="margin-top:.25rem">
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
                        <td><?= (int) ($certifierCounts[$cid] ?? 0) ?></td>
                        <td><?= !empty($c['is_active']) ? 'Sí' : 'No' ?></td>
                        <td>
                            <span class="row-actions">
                                <a class="icon-btn" href="<?= e(url('/admin/certificadoras/' . $cid)) ?>"
                                   title="Editar" aria-label="Editar"><?= icon('edit') ?></a>
                                <form class="icon-btn-form" method="post"
                                      action="<?= e(url('/admin/certificadoras/' . $cid . '/eliminar')) ?>"
                                      onsubmit="return confirm('¿Eliminar esta certificadora? Solo si no tiene productos.');">
                                    <?= csrf_field() ?>
                                    <button class="icon-btn" type="submit" title="Eliminar" aria-label="Eliminar"><?= icon('trash') ?></button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($certifiers === []): ?>
                    <tr><td colspan="7" class="muted">Sin certificadoras. Crea una o ejecuta el seed de catálogo.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="panel" style="margin-top:.25rem">
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>Sitio</th>
                    <th>Grupos</th>
                    <th>Productos</th>
                    <th>Activo</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($suppliers as $s): ?>
                    <?php $sid = (int) $s['id']; ?>
                    <tr>
                        <td><code><?= e($s['code']) ?></code></td>
                        <td><?= e($s['name']) ?></td>
                        <td>
                            <?php if (!empty($s['website'])): ?>
                                <a href="<?= e((string) $s['website']) ?>" target="_blank" rel="noopener"><?= e((string) $s['website']) ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= (int) ($counts[$sid]['groups'] ?? 0) ?></td>
                        <td><?= (int) ($counts[$sid]['products'] ?? 0) ?></td>
                        <td><?= (int) $s['is_active'] ? 'Sí' : 'No' ?></td>
                        <td>
                            <span class="row-actions">
                                <a class="icon-btn" href="<?= e(url('/admin/proveedores/' . $sid)) ?>"
                                   title="Editar" aria-label="Editar"><?= icon('edit') ?></a>
                                <form class="icon-btn-form" method="post"
                                      action="<?= e(url('/admin/proveedores/' . $sid . '/eliminar')) ?>"
                                      onsubmit="return confirm('¿Eliminar este proveedor? Solo si no tiene productos ni grupos.');">
                                    <?= csrf_field() ?>
                                    <button class="icon-btn" type="submit" title="Eliminar" aria-label="Eliminar"><?= icon('trash') ?></button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($suppliers === []): ?>
                    <tr><td colspan="7" class="muted">Sin proveedores. Crea uno o ejecuta el seed de catálogo.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>
