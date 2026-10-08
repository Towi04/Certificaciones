<?php /** @var array<string,mixed> $partner */ ?>
<h1 style="margin:0;color:var(--doceo-blue)">Mi escuela / directorio</h1>
<p class="muted">Próximamente podrás publicar el perfil de tu escuela en el directorio de distribuidores autorizados.</p>
<div class="panel" style="margin-top:1rem;max-width:560px">
    <p style="margin:0">
        Escuela: <strong><?= e((string) ($partner['display_name'] ?? '')) ?></strong><br>
        Código: <code><?= e((string) ($partner['code'] ?? '')) ?></code>
    </p>
    <p class="muted" style="margin:.75rem 0 0;font-size:.85rem">
        Mientras tanto, edita tu nombre comercial y datos de contacto en
        <a href="<?= e(url('/partner/perfil')) ?>">Mi perfil</a>.
    </p>
</div>
