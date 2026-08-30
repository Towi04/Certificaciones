<?php
/** @var list<array<string,mixed>> $templates */
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <h1 style="margin:0;color:var(--doceo-blue)">Plantillas de correo</h1>
    <a class="btn btn-accent" href="<?= e(url('/admin/correos/nueva')) ?>">Nueva plantilla</a>
</div>
<p class="muted">
    Edita asunto, contenido y destinatarios en cada plantilla. Variables con doble llave, por ejemplo <code>{{matricula}}</code> o <code>{{certificacion}}</code>.
</p>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Plantillas</h2>
  <?php if ($templates === []): ?>
    <p class="muted">No hay plantillas. Abre esta página de nuevo o ejecuta el seed de catálogo.</p>
  <?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Nombre</th><th>Código</th><th>Activa</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($templates as $t): ?>
                <tr>
                    <td><?= e($t['name']) ?></td>
                    <td><code><?= e($t['code']) ?></code></td>
                    <td><?= (int) $t['is_active'] ? 'Sí' : 'No' ?></td>
                    <td><a href="<?= e(url('/admin/correos/' . $t['code'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
  <?php endif; ?>
</div>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>
