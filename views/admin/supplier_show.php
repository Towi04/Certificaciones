<?php
/** @var array<string,mixed> $supplier */
/** @var list<array<string,mixed>> $groups */
/** @var list<array<string,mixed>> $products */
/** @var list<array<string,mixed>> $contacts */
/** @var list<array<string,mixed>> $accounts */
/** @var list<array<string,mixed>> $documents */
/** @var list<array<string,mixed>> $allCertifiers */
/** @var list<int> $linkedCertifierIds */
/** @var array<int,string> $revealedPasswords */
/** @var int $productCount */
/** @var int $groupCount */
$sid = (int) $supplier['id'];
$contacts = $contacts ?? [];
$accounts = $accounts ?? [];
$documents = $documents ?? [];
$allCertifiers = $allCertifiers ?? [];
$linkedCertifierIds = array_map('intval', $linkedCertifierIds ?? []);
$revealedPasswords = $revealedPasswords ?? [];
$contactRoles = \App\Services\SupplierAdminService::CONTACT_ROLES;
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$logoMark = trim((string) ($supplier['logo_path'] ?? ''));
$logoWord = trim((string) ($supplier['logo_wordmark_path'] ?? ''));
$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / (1024 * 1024), 1) . ' MB';
};
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
    Usa las pestañas para editar. <strong>Guardar todo</strong> guarda datos, logos, certificadoras y la lista de
    contactos (altas, cambios y bajas). Los accesos existentes se editan con sus iconos;
    un acceso nuevo se crea al guardar si llenaste el bloque «Agregar acceso».
</p>

<nav class="group-tabs" style="margin-top:1rem" role="tablist" aria-label="Secciones del proveedor">
    <button type="button" class="group-tab active" data-tab="general">Datos</button>
    <button type="button" class="group-tab" data-tab="logos">Logos</button>
    <button type="button" class="group-tab" data-tab="certifiers">Certificadoras</button>
    <button type="button" class="group-tab" data-tab="contacts">Contactos</button>
    <button type="button" class="group-tab" data-tab="accounts">Accesos</button>
    <button type="button" class="group-tab" data-tab="documents">Documentos</button>
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
                Datos generales del proveedor. Los enlaces a portales, usuarios y contraseñas van en
                <strong>Accesos</strong> (solo si el proveedor te da acceso).
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
            <label class="muted" style="<?= e($labelStyle) ?>;margin-top:.85rem">
                Notas
                <textarea name="notes" rows="5" placeholder="Acuerdos, condiciones, recordatorios internos…"
                          style="<?= e($inputStyle) ?>;resize:vertical;min-height:7rem;font:inherit"><?= e((string) ($supplier['notes'] ?? '')) ?></textarea>
                <span style="font-weight:500;font-size:.78rem">Solo para uso interno del equipo. No se muestra en el catálogo.</span>
            </label>
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

    <div class="supplier-panel" data-panel="certifiers" hidden>
        <div class="panel" style="margin-top:.75rem;max-width:860px">
            <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Certificadoras</h2>
            <p class="muted" style="font-size:.85rem;margin-top:0">
                Marca las instituciones que emiten las certificaciones de este proveedor
                (ej. ETC → Microsoft, Adobe, Apple, Certiport; Lingua Franca → TOEFL / TOEIC).
                Luego, al editar un producto de este proveedor, el selector «Quién certifica»
                se limita a estas opciones.
            </p>
            <input type="hidden" name="certifier_ids_present" value="1">
            <?php if ($allCertifiers === []): ?>
                <p class="muted" style="margin:0">
                    Aún no hay certificadoras.
                    <a href="<?= e(url('/admin/certificadoras/nueva')) ?>">Crear una</a>.
                </p>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.55rem">
                    <?php foreach ($allCertifiers as $c): ?>
                        <?php $cid = (int) $c['id']; ?>
                        <label style="display:flex;align-items:flex-start;gap:.55rem;border:1px solid #e6ebf2;border-radius:12px;padding:.65rem .75rem;background:#fff;font-size:.88rem;font-weight:600">
                            <input type="checkbox" name="certifier_ids[]" value="<?= $cid ?>"
                                <?= in_array($cid, $linkedCertifierIds, true) ? 'checked' : '' ?>
                                style="margin-top:.2rem">
                            <span>
                                <?= e((string) $c['name']) ?>
                                <span class="muted" style="display:block;font-weight:500;font-size:.78rem">
                                    <code><?= e((string) $c['code']) ?></code>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted" style="font-size:.8rem;margin:.85rem 0 0">
                    ¿Falta una marca?
                    <a href="<?= e(url('/admin/certificadoras/nueva')) ?>">Agregar certificadora</a>
                    y vuelve a esta pestaña.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="supplier-panel" data-panel="contacts" hidden>
        <div class="panel" style="margin-top:.75rem;max-width:960px">
            <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Contactos</h2>
            <p class="muted" style="font-size:.85rem;margin-top:0">
                Edita los contactos existentes o agrega varios con <strong>+ Agregar contacto</strong>.
                Todo se guarda con <strong>Guardar todo</strong> (también datos y logos).
            </p>
            <div id="supplier-contacts-list" style="display:flex;flex-direction:column;gap:.75rem">
                <?php if ($contacts === []): ?>
                    <p class="muted" id="supplier-contacts-empty" style="margin:0;font-size:.85rem">Aún no hay contactos.</p>
                <?php endif; ?>
                <?php foreach ($contacts as $c): ?>
                    <div class="supplier-contact-row" data-contact-id="<?= (int) $c['id'] ?>"
                         style="border:1px solid #e6ebf2;border-radius:12px;padding:.75rem .85rem;background:#fff">
                        <input type="hidden" name="contact_id[]" value="<?= (int) $c['id'] ?>">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.55rem;align-items:end">
                            <label class="muted" style="<?= e($labelStyle) ?>">
                                Área / rol *
                                <input type="text" name="contact_role_label[]" list="contact-roles"
                                       value="<?= e((string) ($c['role_label'] ?? '')) ?>"
                                       placeholder="Ventas" style="<?= e($inputStyle) ?>">
                            </label>
                            <label class="muted" style="<?= e($labelStyle) ?>">
                                Nombre
                                <input type="text" name="contact_name[]"
                                       value="<?= e((string) ($c['name'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
                            </label>
                            <label class="muted" style="<?= e($labelStyle) ?>">
                                Teléfono
                                <input type="text" name="contact_phone[]"
                                       value="<?= e((string) ($c['phone'] ?? '')) ?>"
                                       placeholder="+52 ..." style="<?= e($inputStyle) ?>">
                            </label>
                            <label class="muted" style="<?= e($labelStyle) ?>">
                                Correo
                                <input type="email" name="contact_email[]"
                                       value="<?= e((string) ($c['email'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
                            </label>
                            <label class="muted" style="<?= e($labelStyle) ?>">
                                Notas
                                <input type="text" name="contact_notes[]"
                                       value="<?= e((string) ($c['notes'] ?? '')) ?>" style="<?= e($inputStyle) ?>">
                            </label>
                            <div style="display:flex;justify-content:flex-end;padding-bottom:.15rem">
                                <button type="button" class="icon-btn supplier-contact-remove"
                                        title="Eliminar contacto" aria-label="Eliminar contacto"
                                        style="color:#b42318"><?= icon('trash') ?></button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <datalist id="contact-roles">
                <?php foreach ($contactRoles as $role): ?>
                    <option value="<?= e($role) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div id="supplier-contact-delete-bag" hidden></div>
            <div style="margin-top:.85rem;display:flex;gap:.55rem;flex-wrap:wrap;align-items:center">
                <button type="button" class="btn btn-ghost btn-sm" id="supplier-contact-add">+ Agregar contacto</button>
                <span class="muted" style="font-size:.8rem">Cada fila necesita área/rol y teléfono o correo.</span>
            </div>
        </div>
    </div>

    <template id="supplier-contact-row-template">
        <div class="supplier-contact-row" data-contact-id="0"
             style="border:1px solid #e6ebf2;border-radius:12px;padding:.75rem .85rem;background:#fafcff">
            <input type="hidden" name="contact_id[]" value="">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.55rem;align-items:end">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Área / rol *
                    <input type="text" name="contact_role_label[]" list="contact-roles"
                           value="" placeholder="Ventas" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Nombre
                    <input type="text" name="contact_name[]" value="" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Teléfono
                    <input type="text" name="contact_phone[]" value="" placeholder="+52 ..." style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Correo
                    <input type="email" name="contact_email[]" value="" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Notas
                    <input type="text" name="contact_notes[]" value="" style="<?= e($inputStyle) ?>">
                </label>
                <div style="display:flex;justify-content:flex-end;padding-bottom:.15rem">
                    <button type="button" class="icon-btn supplier-contact-remove"
                            title="Quitar" aria-label="Quitar"
                            style="color:#b42318"><?= icon('trash') ?></button>
                </div>
            </div>
        </div>
    </template>

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
                                <div style="display:flex;gap:.25rem;flex-wrap:wrap;align-items:flex-start">
                                    <button class="icon-btn" type="submit" form="supplier-account-reveal-<?= $aid ?>"
                                            title="Ver contraseña" aria-label="Ver contraseña"><?= icon('key') ?></button>
                                    <button class="icon-btn" type="button"
                                            title="Editar acceso" aria-label="Editar acceso"
                                            onclick="var d=this.closest('article').querySelector('details'); if(d){ d.open=!d.open; }"><?= icon('edit') ?></button>
                                    <button class="icon-btn" type="submit" form="supplier-account-delete-<?= $aid ?>"
                                            title="Eliminar acceso" aria-label="Eliminar acceso"
                                            style="color:#b42318"
                                            onclick="return confirm('¿Eliminar este acceso?')"><?= icon('trash') ?></button>
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
                    Usuario
                    <input type="text" name="account_username" style="<?= e($inputStyle) ?>" autocomplete="off">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Contraseña
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

<div class="supplier-panel" data-panel="documents" hidden>
    <div class="panel" style="margin-top:.75rem;max-width:920px">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Documentos del proveedor</h2>
        <p class="muted" style="font-size:.85rem;margin-top:0">
            Archivos internos (contratos, tarifas, guías, etc.). Solo el equipo admin los ve.
            En PDF puedes abrir una <strong>vista previa</strong> y decidir si lo descargas.
        </p>

        <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/documentos')) ?>"
              enctype="multipart/form-data"
              style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));align-items:end;margin-bottom:1.25rem;padding:1rem;border:1px dashed #cfd8e6;border-radius:14px;background:#f8fafc">
            <?= csrf_field() ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Título (opcional)
                <input type="text" name="title" placeholder="Ej. Contrato 2026" style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Nota (opcional)
                <input type="text" name="notes" placeholder="Breve referencia interna" style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Archivo *
                <input type="file" name="document_file" required
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,application/pdf">
            </label>
            <div>
                <button class="btn btn-accent" type="submit">Subir documento</button>
            </div>
        </form>

        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Documento</th>
                    <th>Archivo</th>
                    <th>Tamaño</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $doc):
                    $did = (int) $doc['id'];
                    $mime = strtolower((string) ($doc['mime_type'] ?? ''));
                    $orig = (string) ($doc['original_name'] ?? '');
                    $isPdf = str_contains($mime, 'pdf') || str_ends_with(strtolower($orig), '.pdf');
                    $viewUrl = url('/admin/proveedores/' . $sid . '/documentos/' . $did . '/ver');
                    $downloadUrl = url('/admin/proveedores/' . $sid . '/documentos/' . $did . '/descargar');
                    $title = trim((string) ($doc['title'] ?? '')) !== ''
                        ? (string) $doc['title']
                        : ($orig !== '' ? $orig : 'Documento');
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($title) ?></strong>
                            <?php if (!empty($doc['notes'])): ?>
                                <div class="muted" style="font-size:.8rem"><?= e((string) $doc['notes']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($doc['created_at'])): ?>
                                <div class="muted" style="font-size:.75rem">Subido: <?= e((string) $doc['created_at']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><code style="font-size:.78rem"><?= e($orig !== '' ? $orig : '—') ?></code></td>
                        <td class="muted"><?= e($formatBytes((int) ($doc['file_size'] ?? 0))) ?></td>
                        <td>
                            <span class="row-actions" style="display:flex;gap:.35rem;flex-wrap:wrap;justify-content:flex-end">
                                <?php if ($isPdf): ?>
                                    <button type="button"
                                            class="btn btn-primary btn-sm supplier-doc-preview-btn"
                                            data-preview-url="<?= e($viewUrl) ?>"
                                            data-download-url="<?= e($downloadUrl) ?>"
                                            data-title="<?= e($title) ?>">
                                        Vista previa
                                    </button>
                                <?php else: ?>
                                    <a class="btn btn-ghost btn-sm" href="<?= e($viewUrl) ?>" target="_blank" rel="noopener">Abrir</a>
                                <?php endif; ?>
                                <a class="btn btn-accent btn-sm" href="<?= e($downloadUrl) ?>">Descargar</a>
                                <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/documentos/' . $did . '/eliminar')) ?>"
                                      onsubmit="return confirm('¿Eliminar este documento?');" style="display:inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-ghost btn-sm" type="submit" style="color:#b42318">Eliminar</button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($documents === []): ?>
                    <tr><td colspan="4" class="muted">Aún no hay documentos. Sube el primero arriba.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
            Sube un CSV para crear o actualizar productos (clave: <code>code</code>). Luego afina cada uno en Productos.
        </p>
        <p style="margin:.5rem 0 1rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <a class="btn btn-ghost" href="<?= e(url('/admin/proveedores/' . $sid . '/plantilla-certificaciones.csv')) ?>">Descargar plantilla CSV</a>
            <a class="btn btn-ghost" href="<?= e(url('/admin/productos/exportar.csv?supplier_id=' . $sid)) ?>">Descargar productos de este proveedor</a>
        </p>
        <form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/certificaciones')) ?>" enctype="multipart/form-data"
              style="display:grid;gap:.75rem">
            <?= csrf_field() ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Modo
                <select name="import_mode" style="<?= e($inputStyle) ?>">
                    <option value="upsert" selected>Crear y actualizar (por código)</option>
                    <option value="update">Solo actualizar existentes</option>
                    <option value="create">Solo crear nuevos</option>
                </select>
            </label>
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
            <button class="btn btn-accent" type="submit">Procesar CSV</button>
        </form>
    </div>
</div>

<div class="supplier-sticky-save">
    <span class="muted" style="font-size:.82rem">Datos, logos, contactos y acceso nuevo se guardan juntos.</span>
    <button class="btn btn-accent" type="submit" form="supplier-main-form">Guardar todo</button>
</div>

<form method="post" action="<?= e(url('/admin/proveedores/' . $sid . '/eliminar')) ?>"
      onsubmit="return confirm('¿Eliminar este proveedor? Solo si no tiene productos ni grupos.');"
      style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="btn btn-ghost" type="submit" style="color:#b42318">Eliminar proveedor</button>
</form>

<div class="supplier-doc-modal" id="supplier-doc-modal" hidden>
    <button type="button" class="supplier-doc-backdrop" id="supplier-doc-backdrop" aria-label="Cerrar"></button>
    <div class="supplier-doc-dialog" role="dialog" aria-modal="true" aria-labelledby="supplier-doc-title">
        <div class="supplier-doc-head">
            <strong id="supplier-doc-title">Documento</strong>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <a class="btn btn-accent btn-sm" id="supplier-doc-download" href="#">Descargar</a>
                <button type="button" class="btn btn-primary btn-sm" id="supplier-doc-close">Cerrar</button>
            </div>
        </div>
        <div class="supplier-doc-body">
            <iframe class="supplier-doc-frame" id="supplier-doc-frame" title="Vista previa del PDF"></iframe>
        </div>
    </div>
</div>

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
.supplier-doc-modal[hidden] { display:none !important; }
.supplier-doc-modal {
  position:fixed; inset:0; z-index:80; display:flex; align-items:center; justify-content:center;
  padding:1rem;
}
.supplier-doc-backdrop {
  position:absolute; inset:0; border:0; padding:0; margin:0; cursor:pointer;
  background:rgba(16,42,86,.55);
}
.supplier-doc-dialog {
  position:relative; z-index:1; width:min(960px,100%); height:min(88vh,900px);
  background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(16,42,86,.28);
  display:flex; flex-direction:column; overflow:hidden;
}
.supplier-doc-head {
  display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap;
  padding:.85rem 1rem; border-bottom:1px solid #e6ebf2;
}
.supplier-doc-body { flex:1; min-height:0; background:#f4f7fb; }
.supplier-doc-frame { width:100%; height:100%; border:0; background:#fff; }
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

  var docModal = document.getElementById('supplier-doc-modal');
  var docFrame = document.getElementById('supplier-doc-frame');
  var docTitle = document.getElementById('supplier-doc-title');
  var docDownload = document.getElementById('supplier-doc-download');
  var docClose = document.getElementById('supplier-doc-close');
  var docBackdrop = document.getElementById('supplier-doc-backdrop');
  function closeDocPreview() {
    if (!docModal) return;
    docModal.hidden = true;
    if (docFrame) docFrame.src = 'about:blank';
  }
  function openDocPreview(url, downloadUrl, title) {
    if (!docModal || !docFrame) return;
    if (docTitle) docTitle.textContent = title || 'Documento';
    if (docDownload) {
      docDownload.href = downloadUrl || url;
      docDownload.hidden = !(downloadUrl || url);
    }
    docFrame.src = url;
    docModal.hidden = false;
  }
  document.querySelectorAll('.supplier-doc-preview-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openDocPreview(
        btn.getAttribute('data-preview-url') || '',
        btn.getAttribute('data-download-url') || '',
        btn.getAttribute('data-title') || 'Documento'
      );
    });
  });
  if (docClose) docClose.addEventListener('click', closeDocPreview);
  if (docBackdrop) docBackdrop.addEventListener('click', closeDocPreview);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && docModal && !docModal.hidden) closeDocPreview();
  });

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

  (function setupContactsEditor() {
    var list = document.getElementById('supplier-contacts-list');
    var tpl = document.getElementById('supplier-contact-row-template');
    var addBtn = document.getElementById('supplier-contact-add');
    var bag = document.getElementById('supplier-contact-delete-bag');
    if (!list || !tpl || !addBtn || !bag) return;

    function syncEmpty() {
      var empty = document.getElementById('supplier-contacts-empty');
      var hasRows = list.querySelectorAll('.supplier-contact-row').length > 0;
      if (empty) empty.hidden = hasRows;
      else if (!hasRows) {
        var p = document.createElement('p');
        p.id = 'supplier-contacts-empty';
        p.className = 'muted';
        p.style.cssText = 'margin:0;font-size:.85rem';
        p.textContent = 'Aún no hay contactos.';
        list.prepend(p);
      }
    }

    addBtn.addEventListener('click', function () {
      var empty = document.getElementById('supplier-contacts-empty');
      if (empty) empty.remove();
      var node = tpl.content.cloneNode(true);
      list.appendChild(node);
      var last = list.querySelector('.supplier-contact-row:last-child input[name="contact_role_label[]"]');
      if (last) last.focus();
    });

    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.supplier-contact-remove');
      if (!btn) return;
      var row = btn.closest('.supplier-contact-row');
      if (!row) return;
      var id = parseInt(row.getAttribute('data-contact-id') || '0', 10);
      if (id > 0) {
        if (!confirm('¿Eliminar este contacto al guardar?')) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'contact_delete_ids[]';
        input.value = String(id);
        bag.appendChild(input);
      }
      row.remove();
      syncEmpty();
    });
  })();
})();
</script>
