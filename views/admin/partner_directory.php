<?php
/** @var list<array<string,mixed>> $pending */
/** @var list<array<string,mixed>> $publicCards */
/** @var bool $publicEnabled */
?>
<p class="meta"><a href="<?= e(url('/admin/partners')) ?>">← Partners</a></p>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
    <h1 style="margin:.2rem 0;color:var(--doceo-blue)">Directorio · Distribuidores autorizados</h1>
</div>

<div class="panel" style="margin-top:1rem;max-width:720px">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Visibilidad en el catálogo</h2>
    <p class="muted" style="margin-top:0;font-size:.85rem">
        Mientras este check esté apagado, la sección pública no aparece aunque existan perfiles aprobados.
    </p>
    <form method="post" action="<?= e(url('/admin/partners/directorio/visibilidad')) ?>">
        <?= csrf_field() ?>
        <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.92rem">
            <input type="checkbox" name="enabled" value="1" <?= $publicEnabled ? 'checked' : '' ?> style="margin-top:.2rem">
            <span>
                <strong>Mostrar sección «Distribuidores autorizados» en el catálogo</strong>
                <span class="muted" style="display:block;font-size:.8rem;margin-top:.2rem">
                    Estado actual: <?= $publicEnabled ? 'activado' : 'apagado' ?>
                    · perfiles aprobados visibles: <?= count($publicCards) ?>
                </span>
            </span>
        </label>
        <button class="btn btn-accent btn-sm" type="submit" style="margin-top:.75rem">Guardar visibilidad</button>
    </form>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Cola de aprobación</h2>
    <?php if ($pending === []): ?>
        <p class="muted" style="margin:0">No hay perfiles pendientes.</p>
    <?php else: ?>
        <?php foreach ($pending as $row): ?>
            <?php
            $pendingJson = [];
            if (!empty($row['pending_json'])) {
                $decoded = json_decode((string) $row['pending_json'], true);
                $pendingJson = is_array($decoded) ? $decoded : [];
            }
            ?>
            <div style="padding:1rem 0;border-bottom:1px solid #e6ebf2">
                <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;justify-content:space-between">
                    <div style="display:flex;gap:.85rem;flex-wrap:wrap;align-items:flex-start">
                        <?php if (!empty($pendingJson['logo_path'])): ?>
                            <img src="<?= e(asset((string) $pendingJson['logo_path'])) ?>" alt=""
                                 style="width:64px;height:64px;object-fit:contain;border:1px solid #e6ebf2;border-radius:10px;background:#fff">
                        <?php endif; ?>
                        <div>
                            <strong><?= e((string) ($pendingJson['display_name'] ?? $row['display_name'] ?? '')) ?></strong>
                            · <code><?= e((string) ($row['code'] ?? '')) ?></code><br>
                            <span class="muted" style="font-size:.85rem"><?= e((string) ($row['email'] ?? '')) ?></span>
                            <p style="margin:.35rem 0 0;max-width:36rem">
                                <?= e((string) ($pendingJson['description'] ?? $row['directory_description'] ?? '')) ?>
                            </p>
                            <p class="muted" style="margin:.25rem 0 0;font-size:.85rem">
                                <?= e((string) ($pendingJson['phone'] ?? '')) ?>
                                · <?= e((string) ($pendingJson['address'] ?? '')) ?>
                                <?php if (!empty($pendingJson['maps_url'])): ?>
                                    · <a href="<?= e((string) $pendingJson['maps_url']) ?>" target="_blank" rel="noopener">Maps</a>
                                <?php endif; ?>
                            </p>
                            <p class="muted" style="margin:.25rem 0 0;font-size:.78rem">
                                Solicitado: <?= e((string) ($row['publish_requested_at'] ?? '')) ?>
                            </p>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:.45rem;min-width:220px">
                        <form method="post" action="<?= e(url('/admin/partners/directorio/' . (int) $row['id'] . '/aprobar')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-accent btn-sm" type="submit" style="width:100%">Aprobar</button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/partners/directorio/' . (int) $row['id'] . '/rechazar')) ?>">
                            <?= csrf_field() ?>
                            <input type="text" name="note" required placeholder="Motivo del rechazo"
                                   style="width:100%;padding:.4rem .55rem;border:1px solid #cfd8e6;border-radius:8px;margin-bottom:.35rem;box-sizing:border-box">
                            <button class="btn btn-ghost btn-sm" type="submit" style="width:100%">Rechazar</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
