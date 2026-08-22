<?php
/** @var list<array<string,mixed>> $templates */
/** @var string $uksEmail */
$uksCode = \App\Services\MailTemplateService::UKS_SOLICITUD;
?>
<h1 style="margin-top:0;color:var(--doceo-blue)">Plantillas de correo</h1>
<p class="muted">
    Edita el asunto y el contenido HTML. Variables con doble llave, por ejemplo <code>{{matricula}}</code> o <code>{{certificacion}}</code>.
</p>

<div class="panel" style="margin-top:1rem;max-width:560px;border:2px solid #dbeafe">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Destinatario UKS (solicitud examen)</h2>
    <p class="muted" style="margin-top:0;font-size:.88rem">
        Correo por defecto para solicitudes a UKS (todas las certificaciones). Pon tu correo para pruebas.
    </p>
    <form method="post" action="<?= e(url('/admin/correos/destinatarios')) ?>">
        <?= csrf_field() ?>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Correo solicitud UKS
            <input type="email" name="uks_elet_request_email" value="<?= e($uksEmail) ?>"
                placeholder="ej. pruebas@tudominio.com"
                style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%">
        </label>
        <p class="muted" style="font-size:.82rem;margin:.5rem 0 1rem">
            Si está vacío, se usa el contacto del proveedor UKS en Proveedores.
        </p>
        <button class="btn btn-accent" type="submit">Guardar destinatario</button>
    </form>
</div>

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
                    <td>
                        <a href="<?= e(url('/admin/correos/' . $t['code'])) ?>">Editar</a>
                        <?php if ($t['code'] === $uksCode): ?>
                            · <a href="<?= e(url('/admin/correos/' . $uksCode)) ?>#prueba">Probar</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
  <?php endif; ?>
</div>
