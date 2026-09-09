<?php
/** @var array<string,mixed> $supplier */
/** @var list<array<string,mixed>> $groups */
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $contacts */
/** @var list<array<string,mixed>> $accounts */
/** @var array<int,string> $revealedPasswords */
/** @var int $productCount */
/** @var int $groupCount */
$sid = (int) $supplier['id'];
$contacts = $contacts ?? [];
$accounts = $accounts ?? [];
$revealedPasswords = $revealedPasswords ?? [];
$contactRoles = \App\Services\SupplierAdminService::CONTACT_ROLES;
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$logoMark = trim((string) ($supplier['logo_path'] ?? ''));
$logoWord = trim((string) ($supplier['logo_wordmark_path'] ?? ''));
?>
<p class="meta"><a href="<?= e(url('/admin/proveedores')) ?>">← Proveedores</a></p>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
        <?php
        $headerLogo = \App\Services\SupplierAdminService::logoPath($supplier, \App\Services\SupplierAdminService::LOGO_MARK)
            ?? \App\Services\SupplierAdminService::logoPath($supplier, \App\Services\SupplierAdminService::LOGO_WORDMARK);
        ?>
        <div style="width:88px;height:88px;border:1px solid #e6ebf2;border-radius:16px;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:.5rem">
            <?php if ($headerLogo): ?>
                <img src="<?= e(asset($headerLogo)) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
            <?php else: ?>
                <span class="muted" style="font-size:.75rem;text-align:center">Sin logo</span>
            <?php endif; ?>
        </div>
        <div>
            <h1 style="margin:0;color:var(--doceo-blue)"><?= e((string) $supplier['name']) ?></h1>
            <p class="muted" style="margin:.35rem 0 0">
                Código <code><?= e((string) $supplier['code']) ?></code>
                · <?= !empty($supplier['is_active']) ? 'Activo' : 'Inactivo' ?>
                · <?= (int) $groupCount ?> grupo(s) · <?= (int) $productCount ?> producto(s)
            </p>
        </div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <button class="btn btn-accent" type="submit" form="supplier-main-form" id="supplier-save-top">
            Guardar todo
        </button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos/nuevo?supplier_id=' . $sid)) ?>">Nuevo grupo</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/precios?supplier_id=' . $sid)) ?>">Precios</a>
    </div>
</div>

<p class="muted" style="font-size:.85rem;margin:.75rem 0 0;max-width:48rem">
    Usa las pestañas para editar. El botón <strong>Guardar todo</strong> (arriba o abajo) guarda
    datos, logos y, si los llenaste, el contacto o acceso nuevo — sin importar en qué pestaña estés.
</p>

<nav class="group-tabs" style="margin-top:1rem" role="tablist" aria-label="Secciones del proveedor">
    <button type="button" class="group-tab active" data-tab="general">Datos</button>
    <button type="button" class="group-tab" data-tab="logos">Logos</button>
    <button type="button" class="group-tab" data-tab="contacts">Contactos</button>
    <button type="button" class="group-tab" data-tab="accounts">Accesos</button>
    <button type="button" class="group-tab" data-tab="groups">Grupos</button>
    <button type="button" class="group-tab" data-tab="bulk">Lote CSV</button>
</nav>

<form method="post" action="<?= e(url('/admin/proveedores/' . $sid)) ?>"
      enctype="multipart/form-data" id="supplier-main-form" class="supplier-main-form">
    <?= csrf_field() ?>
    <input type="hidden" name="return_tab" id="supplier-return-tab" value="general">

    <div class="supplier-panel" data-panel="general">
        <div class="panel" style="margin-top:.75rem;max-width:860px">
            <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos del proveedor</h2>
            <p class="muted" style="font-size:.82rem;margin-top:0">
                Los enlaces a portales, usuarios y notas van en <strong>Accesos</strong>
                (solo si el proveedor te da acceso).
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Nombre *
                    <input type="text" name="name" required value="<?= e((string) $supplier['name']) ?>" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Código
                    <input type="text" name="code" readonly value="<?= e((string) $supplier['code']) ?>"
                           style="<?= e($inputStyle) ?>;background:#f4f7fb">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Sitio web
                    <input type="url" name="website" value="<?= e((string) ($supplier['website'] ?? '')) ?>"
                           placeholder="https://..." style="<?= e($inputStyle) ?>">
                </label>
            </div>
            <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.85rem">
                <input type="checkbox" name="is_active" value="1" <?= !empty($supplier['is_active']) ? 'checked' : '' ?>>
                Proveedor activo
            </label>
        </div>
    </div>

    <div class="supplier-panel" data-panel="logos" hidden>
        <div class="panel" style="margin-top:.75rem;max-width:860px">
            <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Logos del proveedor</h2>
            <p class="muted" style="font-size:.82rem;margin-top:0">
                Sube dos variantes: <strong>sin denominación</strong> (solo símbolo) y
                <strong>con denominación</strong> (símbolo + nombre). Se guardan con «Guardar todo».
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem">
                <div style="border:1px solid #e6ebf2;border-radius:14px;padding:.9rem 1rem;background:#f8fafc">
                    <h3 style="margin:0 0 .5rem;font-size:.92rem;color:var(--doceo-blue)">Sin denominación</h3>
                    <div style="width:100%;height:96px;border:1px dashed #cfd8e6;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:.5rem;margin-bottom:.65rem">
                        <?php if ($logoMark !== ''): ?>
                            <img src="<?= e(asset($logoMark)) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
                        <?php else: ?>
                            <span class="muted" style="font-size:.78rem">Sin archivo</span>
                        <?php endif; ?>
                    </div>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Imagen (JPG, PNG, WEBP, SVG · máx. 5 MB)
                        <input type="file" name="logo_mark" accept=".jpg,.jpeg,.png,.webp,.gif,.svg,image/*">
                    </label>
                    <?php if ($logoMark !== ''): ?>
                        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:600;margin-top:.65rem">
                            <input type="checkbox" name="remove_logo_mark" value="1">
                            Quitar este logo
                        </label>
                    <?php endif; ?>
                </div>
                <div style="border:1px solid #e6ebf2;border-radius:14px;padding:.9rem 1rem;background:#f8fafc">
                    <h3 style="margin:0 0 .5rem;font-size:.92rem;color:var(--doceo-blue)">Con denominación</h3>
                    <div style="width:100%;height:96px;border:1px dashed #cfd8e6;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:.5rem;margin-bottom:.65rem">
                        <?php if ($logoWord !== ''): ?>
                            <img src="<?= e(asset($logoWord)) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
                        <?php else: ?>
                            <span class="muted" style="font-size:.78rem">Sin archivo</span>
                        <?php endif; ?>
                    </div>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Imagen (JPG, PNG, WEBP, SVG · máx. 5 MB)
                        <input type="file" name="logo_wordmark" accept=".jpg,.jpeg,.png,.webp,.gif,.svg,image/*">
                    </label>
                    <?php if ($logoWord !== ''): ?>
                        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:600;margin-top:.65rem">
                            <input type="checkbox" name="remove_logo_wordmark" value="1">
                            Quitar este logo
                        </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="supplier-panel" data-panel="contacts" hidden>
        <div class="panel" style="margin-top:.75rem;max-width:960px">
            <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Contactos</h2>
            <p class="muted" style="font-size:.85rem;margin-top:0">
                Lista actual abajo. Para agregar uno, llena el bloque y pulsa <strong>Guardar todo</strong>
                (también guarda datos y logos si los cambiaste).
            </p>
            <?php if ($contacts !== []): ?>
                <div class="table-wrap" style="margin-bottom:1rem">
                    <table class="data">
                        <thead>
                        <tr><th>Área</th><th>Nombre</th><th>Teléfono</th><th>Correo</th><th>Notas</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contacts as $c): ?>
                            <tr>
                                <td><?= e((string) $c['role_label']) ?></td>
                                <td><?= e((string) ($c['name'] ?: '—')) ?></td>
                                <td><?= e((string) ($c['phone'] ?: '—')) ?></td>
                                <td>
                                    <?php if (!empty($c['email'])): ?>
                                        <a href="mailto:<?= e((string) $c['email']) ?>"><?= e((string) $c['email']) ?></a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td class="muted" style="font-size:.82rem"><?= e((string) ($c['notes'] ?: '—')) ?></td>
                                <td>
                                    <button class="btn btn-ghost btn-sm" type="submit"
                                            form="supplier-contact-delete-<?= (int) $c['id'] ?>"
                                            onclick="return confirm('¿Eliminar este contacto?')">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted">Aún no hay contactos.</p>
            <?php endif; ?>

            <h3 style="font-size:.95rem;color:var(--doceo-blue)">Agregar contacto</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Área / rol
                    <input type="text" name="contact_role_label" list="contact-roles" placeholder="Ventas"
                           style="<?= e($inputStyle) ?>">
                    <datalist id="contact-roles">
                        <?php foreach ($contactRoles as $role): ?>
                            <option value="<?= e($role) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Nombre
                    <input type="text" name="contact_name" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Teléfono
                    <input type="text" name="contact_phone" placeholder="+52 ..." style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Correo
                    <input type="email" name="contact_email" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Notas
                    <input type="text" name="contact_notes" style="<?= e($inputStyle) ?>">
                </label>
            </div>
            <p class="muted" style="font-size:.8rem;margin:.75rem 0 0">
                Si dejas «Área / rol» vacío, no se agrega contacto al guardar.
            </p>
        </div>
    </div>

    <div class="supplier-panel" data-panel="accounts" hidden>
        <div class="panel" style="margin-top:.75rem;max-width:960px">
            <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Accesos a plataformas</h2>
            <p class="muted" style="font-size:.85rem;margin-top:0">
                Opcional: portales, formularios de registro o sitios sin login.
                Usuario y contraseña son opcionales. Las contraseñas, si las capturas, se cifran con
                <code>APP_KEY</code>. Editar/eliminar un acceso existente usa sus propios botones;
                el acceso <em>nuevo</em> se crea con «Guardar todo».
            </p>

            <?php if ($accounts !== []): ?>
                <div style="display:grid;gap:.85rem;margin-bottom:1.25rem">
                    <?php foreach ($accounts as $a): ?>
                        <?php $aid = (int) $a['id']; ?>
                        <article style="border:1px solid #e6ebf2;border-radius:14px;padding:.9rem 1rem;background:#fff">
                            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                                <div>
                                    <strong><?= e((string) $a['label']) ?></strong>
                                    <p class="muted" style="margin:.25rem 0;font-size:.85rem">
                                        Usuario: <code><?= e((string) ($a['username'] ?: '—')) ?></code>
                                        <?php if (!empty($a['login_url'])): ?>
                                            · <a href="<?= e((string) $a['login_url']) ?>" target="_blank" rel="noopener">Abrir plataforma</a>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($a['notes'])): ?>
                                        <p class="muted" style="margin:0;font-size:.82rem"><?= e((string) $a['notes']) ?></p>
                                    <?php endif; ?>
                                    <?php if (isset($revealedPasswords[$aid])): ?>
                                        <p style="margin:.45rem 0 0;font-size:.88rem">
                                            Contraseña:
                                            <code style="user-select:all"><?= e($revealedPasswords[$aid] !== '' ? $revealedPasswords[$aid] : '(vacía)') ?></code>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:flex-start">
                                    <button class="btn btn-ghost btn-sm" type="submit" form="supplier-account-reveal-<?= $aid ?>">
                                        Ver contraseña
                                    </button>
                                    <button class="btn btn-ghost btn-sm" type="submit" form="supplier-account-delete-<?= $aid ?>"
                                            style="color:#b42318"
                                            onclick="return confirm('¿Eliminar este acceso?')">
                                        Eliminar
                                    </button>
                                </div>
                            </div>
                            <details style="margin-top:.65rem">
                                <summary style="cursor:pointer;font-weight:600;color:var(--doceo-blue)">Editar acceso</summary>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.65rem;margin-top:.65rem">
                                    <label class="muted" style="<?= e($labelStyle) ?>">
                                        Nombre *
                                        <input type="text" name="label" form="supplier-account-edit-<?= $aid ?>" required
                                               value="<?= e((string) $a['label']) ?>" style="<?= e($inputStyle) ?>">
                                    </label>
                                    <label class="muted" style="<?= e($labelStyle) ?>">
                                        URL de acceso
                                        <input type="url" name="login_url" form="supplier-account-edit-<?= $aid ?>"
                                               value="<?= e((string) ($a['login_url'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
                                    </label>
                                    <label class="muted" style="<?= e($labelStyle) ?>">
                                        Usuario
                                        <input type="text" name="username" form="supplier-account-edit-<?= $aid ?>"
                                               value="<?= e((string) ($a['username'] ?? '')) ?>" style="<?= e($inputStyle) ?>" autocomplete="off">
                                    </label>
                                    <label class="muted" style="<?= e($labelStyle) ?>">
                                        Nueva contraseña
                                        <div class="pwd-toggle-wrap">
                                            <input type="password" name="password" form="supplier-account-edit-<?= $aid ?>"
                                                   class="pwd-toggle-input"
                                                   placeholder="Vacío = no cambiar" style="<?= e($inputStyle) ?>" autocomplete="new-password">
                                            <button type="button" class="icon-btn pwd-toggle-btn" title="Mostrar contraseña"
                                                    aria-label="Mostrar contraseña" data-eye="<?= e(icon('eye')) ?>"
                                                    data-eye-off="<?= e(icon('eye-off')) ?>"><?= icon('eye') ?></button>
                                        </div>
                                    </label>
                                    <label class="muted" style="<?= e($labelStyle) ?>">
                                        Notas
                                        <input type="text" name="notes" form="supplier-account-edit-<?= $aid ?>"
                                               value="<?= e((string) ($a['notes'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
                                    </label>
                                    <div style="display:flex;align-items:end">
                                        <button class="btn btn-accent btn-sm" type="submit" form="supplier-account-edit-<?= $aid ?>">
                                            Guardar este acceso
                                        </button>
                                    </div>
                                </div>
                            </details>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted">Aún no hay accesos guardados.</p>
            <?php endif; ?>

            <h3 style="font-size:.95rem;color:var(--doceo-blue)">Agregar acceso</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Nombre del acceso
                    <input type="text" name="account_label" placeholder="Portal admin / Moodle / ..." style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    URL de la plataforma
                    <input type="url" name="account_login_url" placeholder="https://..." style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Usuario <span style="font-weight:500;opacity:.75">(opcional)</span>
                    <input type="text" name="account_username" style="<?= e($inputStyle) ?>" autocomplete="off">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Contraseña <span style="font-weight:500;opacity:.75">(opcional)</span>
                    <div class="pwd-toggle-wrap">
                        <input type="password" name="account_password" class="pwd-toggle-input"
                               style="<?= e($inputStyle) ?>" autocomplete="new-password">
                        <button type="button" class="icon-btn pwd-toggle-btn" title="Mostrar contraseña"
                                aria-label="Mostrar contraseña" data-eye="<?= e(icon('eye')) ?>"
                                data-eye-off="<?= e(icon('eye-off')) ?>"><?= icon('eye') ?></button>
                    </div>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Notas
                    <input type="text" name="account_notes" style="<?= e($inputStyle) ?>">
                </label>
            </div>
            <p class="muted" style="font-size:.8rem;margin:.75rem 0 0">
                Basta el nombre (y opcionalmente la URL). Usuario y contraseña solo si el sitio los pide.
                Si dejas «Nombre del acceso» vacío, no se agrega acceso al guardar.
            </p>
        </div>
    </div>
</form>

<?php foreach ($contacts as $c): ?>
    <form id="supplier-contact-delete-<?= (int) $c['id'] ?>" method="post"
          action="<?= e(url('/admin/proveedores/' . $sid . '/contactos/' . (int) $c['id'] . '/eliminar')) ?>">
        <?= csrf_field() ?>
    </form>
<?php endforeach; ?>

<?php foreach ($accounts as $a): ?>
    <?php $aid = (int) $a['id']; ?>
    <form id="supplier-account-reveal-<?= $aid ?>" method="post"
          action="<?= e(url('/admin/proveedores/' . $sid . '/accesos/' . $aid . '/revelar')) ?>">
        <?= csrf_field() ?>
    </form>
    <form id="supplier-account-delete-<?= $aid ?>" method="post"
          action="<?= e(url('/admin/proveedores/' . $sid . '/accesos/' . $aid . '/eliminar')) ?>">
        <?= csrf_field() ?>
    </form>
    <form id="supplier-account-edit-<?= $aid ?>" method="post"
          action="<?= e(url('/admin/proveedores/' . $sid . '/accesos/' . $aid)) ?>">
        <?= csrf_field() ?>
    </form>
<?php endforeach; ?>

<div class="supplier-panel" data-panel="groups" hidden>
    <div class="panel" style="margin-top:.75rem">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
            <h2 style="margin:0;font-size:1.05rem;color:var(--doceo-blue)">Grupos de este proveedor</h2>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/grupos/nuevo?supplier_id=' . $sid)) ?>">Nuevo grupo</a>
        </div>
        <div class="table-wrap" style="margin-top:.85rem">
            <table class="data">
                <thead><tr><th>Código</th><th>Nombre</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td><code><?= e($g['code']) ?></code></td>
                        <td><?= e($g['name']) ?></td>
                        <td><a href="<?= e(url('/admin/grupos/' . $g['id'])) ?>">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($groups === []): ?>
                    <tr><td colspan="3" class="muted">Aún no hay grupos.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="supplier-panel" data-panel="bulk" hidden>
    <div class="panel" style="margin-top:.75rem;max-width:860px">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Cargar certificaciones en lote</h2>
        <p class="muted" style="font-size:.85rem">
            Sube un CSV para crear varios productos. Luego afina cada uno en Productos.
        </p>
        <p style="margin:.5rem 0 1rem">
            <a class="btn btn-ghost" href="<?= e(url('/admin/proveedores/' . $sid . '/plantilla-certificaciones.csv')) ?>">Descargar plantilla CSV</a>
        </p>
        <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/certificaciones')) ?>" enctype="multipart/form-data"
              style="display:grid;gap:.75rem">
            <?= csrf_field() ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Grupo de proceso (recomendado)
                <select name="product_group_id" style="<?= e($inputStyle) ?>">
                    <option value="">— Usar product_group_code del CSV o ninguno —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?> (<?= e($g['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Archivo CSV
                <input type="file" name="csv" accept=".csv,text/csv" required>
            </label>
            <button class="btn btn-accent" type="submit">Crear certificaciones</button>
        </form>
    </div>
</div>

<div class="supplier-sticky-save">
    <span class="muted" style="font-size:.82rem">Los cambios de Datos, Logos, Contacto nuevo y Acceso nuevo se guardan juntos.</span>
    <button class="btn btn-accent" type="submit" form="supplier-main-form">Guardar todo</button>
</div>

<form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/eliminar')) ?>"
      onsubmit="return confirm('¿Eliminar este proveedor? Solo si no tiene productos ni grupos.');"
      style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit" style="color:#b42318">Eliminar proveedor</button>
</form>

<style>
.group-tabs { display:flex; flex-wrap:wrap; gap:.4rem; }
.group-tab {
    border:1px solid #cfd8e6; background:#fff; color:var(--doceo-blue);
    border-radius:999px; padding:.45rem .9rem; font-weight:700; font-size:.86rem; cursor:pointer;
}
.group-tab.active { background:var(--doceo-blue); border-color:var(--doceo-blue); color:#fff; }
.supplier-sticky-save {
    position:sticky; bottom:0; z-index:6;
    display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between;
    margin-top:1rem; padding:.85rem 1rem; border-radius:14px;
    background:#102a56; color:#fff; box-shadow:0 -8px 24px rgba(16,42,86,.18);
}
.supplier-sticky-save .muted { color:rgba(255,255,255,.82); }
.pwd-toggle-wrap {
  position: relative;
  display: block;
}
.pwd-toggle-wrap .pwd-toggle-input {
  width: 100%;
  padding-right: 2.4rem !important;
  box-sizing: border-box;
}
.pwd-toggle-wrap .pwd-toggle-btn {
  position: absolute;
  right: .25rem;
  top: 50%;
  transform: translateY(-50%);
  z-index: 1;
}
</style>
<script>
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.group-tab[data-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.supplier-panel[data-panel]'));
  var returnTab = document.getElementById('supplier-return-tab');
  function activate(name) {
    if (!name) name = 'general';
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tab') === name;
      tab.classList.toggle('active', on);
    });
    panels.forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-panel') !== name;
    });
    if (returnTab) returnTab.value = name;
    if (history.replaceState) history.replaceState(null, '', '#' + name);
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { activate(tab.getAttribute('data-tab')); });
  });
  var hash = (location.hash || '').replace(/^#/, '');
  if (hash && document.querySelector('.supplier-panel[data-panel="' + hash + '"]')) {
    activate(hash);
  } else {
    activate('general');
  }

  document.querySelectorAll('.pwd-toggle-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap = btn.closest('.pwd-toggle-wrap');
      var input = wrap ? wrap.querySelector('.pwd-toggle-input') : null;
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? (btn.getAttribute('data-eye-off') || '') : (btn.getAttribute('data-eye') || '');
      btn.title = show ? 'Ocultar contraseña' : 'Mostrar contraseña';
      btn.setAttribute('aria-label', btn.title);
      input.focus();
    });
  });
})();
</script>
