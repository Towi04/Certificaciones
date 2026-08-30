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
?>
<p class="meta"><a href="<?= e(url('/admin/proveedores')) ?>">← Proveedores</a></p>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
        <div style="width:88px;height:88px;border:1px solid #e6ebf2;border-radius:16px;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:.5rem">
            <?php if (!empty($supplier['logo_path'])): ?>
                <img src="<?= e(asset((string) $supplier['logo_path'])) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
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
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos/nuevo?supplier_id=' . $sid)) ?>">Nuevo grupo</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/precios?supplier_id=' . $sid)) ?>">Precios</a>
    </div>
</div>

<nav class="group-tabs" style="margin-top:1rem" role="tablist">
    <button type="button" class="group-tab active" data-tab="general">Datos</button>
    <button type="button" class="group-tab" data-tab="contacts">Contactos</button>
    <button type="button" class="group-tab" data-tab="accounts">Accesos / plataformas</button>
</nav>

<div class="supplier-panel" data-panel="general">
    <form method="post" action="<?= e(url('/admin/proveedores/' . $sid)) ?>" class="panel" style="margin-top:.75rem;max-width:860px">
        <?= csrf_field() ?>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos del proveedor</h2>
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
            <label class="muted" style="<?= e($labelStyle) ?>">
                Plataforma / portal de administración
                <input type="url" name="platform_url" value="<?= e((string) ($supplier['platform_url'] ?? '')) ?>"
                       placeholder="https://admin.proveedor.com/..." style="<?= e($inputStyle) ?>">
            </label>
        </div>
        <label class="muted" style="<?= e($labelStyle) ?>;margin-top:1rem">
            Notas internas
            <textarea name="notes" rows="3" style="<?= e($inputStyle) ?>"><?= e((string) ($supplier['notes'] ?? '')) ?></textarea>
        </label>
        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.85rem">
            <input type="checkbox" name="is_active" value="1" <?= !empty($supplier['is_active']) ? 'checked' : '' ?>>
            Proveedor activo
        </label>
        <div style="margin-top:1rem">
            <button class="btn btn-accent" type="submit">Guardar proveedor</button>
        </div>
    </form>

    <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/logo')) ?>" enctype="multipart/form-data"
          class="panel" style="margin-top:1rem;max-width:860px">
        <?= csrf_field() ?>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Logo del proveedor</h2>
        <label class="muted" style="<?= e($labelStyle) ?>;max-width:360px">
            Imagen (JPG, PNG, WEBP, SVG · máx. 5 MB)
            <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp,.gif,.svg,image/*">
        </label>
        <?php if (!empty($supplier['logo_path'])): ?>
            <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-top:.75rem">
                <input type="checkbox" name="remove_logo" value="1">
                Quitar logo actual
            </label>
        <?php endif; ?>
        <button class="btn btn-ghost" type="submit" style="margin-top:.75rem">Actualizar logo</button>
    </form>
</div>

<div class="supplier-panel" data-panel="contacts" hidden>
    <div class="panel" style="margin-top:.75rem;max-width:960px">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Contactos</h2>
        <p class="muted" style="font-size:.85rem;margin-top:0">
            Agrega teléfonos y correos por área: general, ventas, soporte, facturación, etc.
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
                                <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/contactos/' . (int) $c['id'] . '/eliminar')) ?>"
                                      onsubmit="return confirm('¿Eliminar este contacto?')">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-ghost btn-sm" type="submit">Eliminar</button>
                                </form>
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
        <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/contactos')) ?>"
              style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <?= csrf_field() ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Área / rol *
                <input type="text" name="role_label" list="contact-roles" required placeholder="Ventas"
                       style="<?= e($inputStyle) ?>">
                <datalist id="contact-roles">
                    <?php foreach ($contactRoles as $role): ?>
                        <option value="<?= e($role) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Nombre
                <input type="text" name="name" style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Teléfono
                <input type="text" name="phone" placeholder="+52 ..." style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Correo
                <input type="email" name="email" style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Notas
                <input type="text" name="notes" style="<?= e($inputStyle) ?>">
            </label>
            <div style="display:flex;align-items:end">
                <button class="btn btn-accent" type="submit">Agregar contacto</button>
            </div>
        </form>
    </div>
</div>

<div class="supplier-panel" data-panel="accounts" hidden>
    <div class="panel" style="margin-top:.75rem;max-width:960px">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Accesos a plataformas</h2>
        <p class="muted" style="font-size:.85rem;margin-top:0">
            Guarda links, usuarios y contraseñas de portales donde administran exámenes.
            Las contraseñas se cifran con <code>APP_KEY</code>.
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
                                <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/accesos/' . $aid . '/revelar')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-ghost btn-sm" type="submit">Ver contraseña</button>
                                </form>
                                <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/accesos/' . $aid . '/eliminar')) ?>"
                                      onsubmit="return confirm('¿Eliminar este acceso?')">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-ghost btn-sm" type="submit" style="color:#b42318">Eliminar</button>
                                </form>
                            </div>
                        </div>
                        <details style="margin-top:.65rem">
                            <summary style="cursor:pointer;font-weight:600;color:var(--doceo-blue)">Editar acceso</summary>
                            <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/accesos/' . $aid)) ?>"
                                  style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.65rem;margin-top:.65rem">
                                <?= csrf_field() ?>
                                <label class="muted" style="<?= e($labelStyle) ?>">
                                    Nombre *
                                    <input type="text" name="label" required value="<?= e((string) $a['label']) ?>" style="<?= e($inputStyle) ?>">
                                </label>
                                <label class="muted" style="<?= e($labelStyle) ?>">
                                    URL de acceso
                                    <input type="url" name="login_url" value="<?= e((string) ($a['login_url'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
                                </label>
                                <label class="muted" style="<?= e($labelStyle) ?>">
                                    Usuario
                                    <input type="text" name="username" value="<?= e((string) ($a['username'] ?? '')) ?>" style="<?= e($inputStyle) ?>" autocomplete="off">
                                </label>
                                <label class="muted" style="<?= e($labelStyle) ?>">
                                    Nueva contraseña
                                    <input type="password" name="password" placeholder="Vacío = no cambiar" style="<?= e($inputStyle) ?>" autocomplete="new-password">
                                </label>
                                <label class="muted" style="<?= e($labelStyle) ?>">
                                    Notas
                                    <input type="text" name="notes" value="<?= e((string) ($a['notes'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
                                </label>
                                <div style="display:flex;align-items:end">
                                    <button class="btn btn-accent btn-sm" type="submit">Guardar cambios</button>
                                </div>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">Aún no hay accesos guardados.</p>
        <?php endif; ?>

        <h3 style="font-size:.95rem;color:var(--doceo-blue)">Agregar acceso</h3>
        <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/accesos')) ?>"
              style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <?= csrf_field() ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Nombre del acceso *
                <input type="text" name="label" required placeholder="Portal admin / Moodle / ..." style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                URL de la plataforma
                <input type="url" name="login_url" placeholder="https://..." style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Usuario
                <input type="text" name="username" style="<?= e($inputStyle) ?>" autocomplete="off">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Contraseña *
                <input type="password" name="password" required style="<?= e($inputStyle) ?>" autocomplete="new-password">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Notas
                <input type="text" name="notes" style="<?= e($inputStyle) ?>">
            </label>
            <div style="display:flex;align-items:end">
                <button class="btn btn-accent" type="submit">Guardar acceso</button>
            </div>
        </form>
    </div>
</div>

<div class="panel" style="margin-top:1rem;max-width:860px">
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

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Grupos de este proveedor</h2>
    <div class="table-wrap">
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
</style>
<script>
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.group-tab[data-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.supplier-panel[data-panel]'));
  function activate(name) {
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tab') === name;
      tab.classList.toggle('active', on);
    });
    panels.forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-panel') !== name;
    });
    if (history.replaceState) history.replaceState(null, '', '#' + name);
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { activate(tab.getAttribute('data-tab')); });
  });
  var hash = (location.hash || '').replace(/^#/, '');
  if (hash && document.querySelector('.supplier-panel[data-panel="' + hash + '"]')) activate(hash);
})();
</script>
