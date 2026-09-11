<?php
/** @var array<string,mixed> $tracking */
/** @var list<array<string,mixed>> $steps */
/** @var bool $isEletUks */
/** @var string $eletExamUrl */
/** @var ?string $accessKeyHint */
/** @var list<array<string,mixed>> $steps */
/** @var list<array<string,mixed>> $logs */
/** @var list<array<string,mixed>> $documents */
$current = (string) ($tracking['current_step_code'] ?? '');
$statusLabels = [
    'open' => 'Abierto',
    'waiting_admin' => 'Espera admin',
    'waiting_student' => 'Espera alumno',
    'waiting_partner' => 'Espera partner',
    'waiting_provider' => 'Espera proveedor',
    'completed' => 'Completado',
    'cancelled' => 'Cancelado',
];
?>
<p class="meta">
    <a href="<?= e(url('/admin')) ?>">← Dashboard</a>
    · <a href="<?= e(url('/admin/compras/' . $tracking['purchase_id'])) ?>">Compra <?= e($tracking['matricula']) ?></a>
</p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($tracking['product_name']) ?></h1>
<p>
    Matrícula <strong><?= e($tracking['matricula']) ?></strong>
    · <span class="pill"><?= e($statusLabels[$tracking['status']] ?? $tracking['status']) ?></span>
    · pago compra: <span class="pill"><?= e($tracking['purchase_status']) ?></span>
</p>
<p class="muted">
    <?= e(trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''))) ?>
    · <?= e($tracking['student_email']) ?>
    <?php if (!empty($tracking['student_phone'])): ?> · <?= e($tracking['student_phone']) ?><?php endif; ?>
</p>

<?php if (in_array((string) $tracking['purchase_status'], ['awaiting_payment', 'payment_review'], true)): ?>
    <div class="panel" style="margin-top:1rem;border:2px solid var(--doceo-yellow)">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Pago pendiente de confirmación</h2>
        <p class="muted" style="margin-top:0">Revisa el comprobante y confirma el pago para avanzar el caso.</p>
        <?php if (!empty($tracking['payment_proof_path'])): ?>
            <?php
            $proofUrl = url('/admin/compras/' . $tracking['purchase_id'] . '/comprobante');
            $ext = strtolower(pathinfo((string) $tracking['payment_proof_path'], PATHINFO_EXTENSION));
            ?>
            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)): ?>
                <a href="<?= e($proofUrl) ?>" target="_blank" rel="noopener">
                    <img src="<?= e($proofUrl) ?>" alt="Comprobante" style="max-width:min(360px,100%);height:auto;border:1px solid #d5deea;border-radius:12px;margin-bottom:.75rem">
                </a>
            <?php endif; ?>
            <p>
                <a class="btn btn-primary btn-sm" href="<?= e($proofUrl) ?>" target="_blank" rel="noopener">Ver comprobante</a>
                <a class="btn btn-accent btn-sm" href="<?= e(url('/admin/compras/' . $tracking['purchase_id'])) ?>">Confirmar pago</a>
            </p>
        <?php else: ?>
            <a class="btn btn-accent" href="<?= e(url('/admin/compras/' . $tracking['purchase_id'])) ?>">Ir a confirmar pago</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Pipeline<?= !empty($tracking['pipeline_name']) ? ': ' . e($tracking['pipeline_name']) : '' ?></h2>
    <?php if ($steps === []): ?>
        <p class="muted">Sin pipeline asignado. Re-ejecuta el seed de catálogo si hace falta.</p>
    <?php else: ?>
        <ol class="pipeline-list" style="margin:0;padding-left:1.2rem">
            <?php foreach ($steps as $s): ?>
                <?php $active = (string) $s['code'] === $current; ?>
                <li style="<?= $active ? 'font-weight:700;color:var(--doceo-blue)' : '' ?>">
                    <?= e($s['label']) ?>
                    <span class="muted" style="font-weight:500">(<?= e($s['code']) ?> · <?= e($s['actor']) ?>)</span>
                    <?php if ($active): ?> ← actual<?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/avanzar')) ?>" style="margin-top:1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem">Ir a paso
            <select name="step_code" style="padding:.45rem .6rem;border:1px solid #cfd8e6;border-radius:10px">
                <option value="">— siguiente automático —</option>
                <?php foreach ($steps as $s): ?>
                    <option value="<?= e($s['code']) ?>" <?= (string) $s['code'] === $current ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.85rem;flex:1;min-width:180px">Nota
            <input type="text" name="note" style="padding:.45rem .6rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <button class="btn btn-accent" type="submit">Actualizar paso</button>
    </form>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Examen y accesos</h2>
    <?php
    $extraVal = trim((string) ($tracking['zoom_url'] ?? ''));
    $extraCfg = [];
    if (!empty($tracking['group_config_json']) && is_string($tracking['group_config_json'])) {
        $decodedG = json_decode($tracking['group_config_json'], true);
        $extraCfg = is_array($decodedG) ? $decodedG : [];
    }
    $extraLabel = \App\Services\AdminOpsBoardService::extraFieldLabelFromConfig($extraCfg);
    $extraIsUrl = \App\Services\AdminOpsBoardService::looksLikeUrl($extraVal);
    ?>
    <?php if (!empty($tracking['exam_date'])): ?>
        <p style="margin-top:0">
            Fecha programada:
            <strong><?= e((string) $tracking['exam_date']) ?></strong>
            <?php if (!empty($tracking['exam_time'])): ?>
                <?= e(substr((string) $tracking['exam_time'], 0, 5)) ?>
            <?php endif; ?>
        </p>
        <?php if (!empty($tracking['exam_date_2'])): ?>
            <p class="muted" style="margin-top:0">
                Reagenda:
                <?= e((string) $tracking['exam_date_2']) ?>
                <?php if (!empty($tracking['exam_time_2'])): ?>
                    <?= e(substr((string) $tracking['exam_time_2'], 0, 5)) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ($extraVal !== ''): ?>
            <p class="muted" style="margin-top:0">
                <?= e($extraLabel) ?>:
                <?php if ($extraIsUrl): ?>
                    <a href="<?= e($extraVal) ?>" target="_blank" rel="noopener">abrir enlace</a>
                <?php else: ?>
                    <strong style="font-family:ui-monospace,monospace"><?= e($extraVal) ?></strong>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted" style="margin-top:0">Sin fecha de examen asignada todavía.</p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/examen')) ?>" style="margin-top:.75rem">
        <?= csrf_field() ?>
        <h3 style="margin:0 0 .5rem;font-size:.95rem;color:var(--doceo-blue)">Fecha programada</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Fecha *
                <input type="date" name="exam_date" required value="<?= e((string) ($tracking['exam_date'] ?? '')) ?>" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Hora
                <input type="time" name="exam_time" value="<?= e(isset($tracking['exam_time']) ? substr((string) $tracking['exam_time'], 0, 5) : '') ?>" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
        </div>

        <h3 style="margin:1rem 0 .35rem;font-size:.95rem;color:var(--doceo-blue)">Reagenda</h3>
        <p class="muted" style="font-size:.82rem;margin:0 0 .5rem">Solo cuando el alumno/partner solicita reagendar el examen.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Nueva fecha
                <input type="date" name="exam_date_2" value="<?= e((string) ($tracking['exam_date_2'] ?? '')) ?>" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Nueva hora
                <input type="time" name="exam_time_2" value="<?= e(isset($tracking['exam_time_2']) ? substr((string) $tracking['exam_time_2'], 0, 5) : '') ?>" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
        </div>

        <h3 style="margin:1rem 0 .35rem;font-size:.95rem;color:var(--doceo-blue)">Campos extra</h3>
        <p class="muted" style="font-size:.82rem;margin:0 0 .5rem">
            Valores según el grupo. En correos usa <code>{{codigo}}</code> de cada campo
            (y <code>{{extra}}</code> / <code>{{zoom}}</code> para el primero).
        </p>
        <?php
        $extraFieldsList = \App\Services\GroupExtraFields::fromGroupConfig($extraCfg);
        $extraValuesMap = \App\Services\GroupExtraFields::valuesFromTracking($tracking);
        if ($extraFieldsList === []) {
            $extraFieldsList = [['code' => 'extra', 'label' => $extraLabel]];
        }
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
            <?php foreach ($extraFieldsList as $i => $ef):
                $efCode = (string) ($ef['code'] ?? 'extra');
                $efLabel = (string) ($ef['label'] ?? $efCode);
                $efVal = (string) ($extraValuesMap[$efCode] ?? '');
                if ($efVal === '' && $i === 0) {
                    $efVal = $extraVal;
                }
                ?>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    <?= e($efLabel) ?>
                    <span class="muted" style="font-weight:500;font-size:.72rem"><code>{{<?= e($efCode) ?>}}</code></span>
                    <input type="text"
                           name="access_field[<?= e($efCode) ?>]"
                           value="<?= e($efVal) ?>"
                           placeholder="<?= e($efLabel) ?>"
                           style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="zoom_url" id="tracking-zoom-mirror" value="<?= e($extraVal) ?>">

        <label class="muted" style="display:flex;gap:.4rem;align-items:center;margin:.85rem 0;font-size:.88rem">
            <input type="checkbox" name="notify" value="1" checked> Avisar al alumno por correo (fecha y/o datos extra)
        </label>
        <button class="btn btn-accent" type="submit">Guardar examen / accesos</button>
    </form>
    <script>
    (function () {
      var form = document.querySelector('form[action*="examen"]');
      if (!form) return;
      var mirror = document.getElementById('tracking-zoom-mirror');
      form.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.name || t.name.indexOf('access_field[') !== 0) return;
        var first = form.querySelector('[name^="access_field["]');
        if (mirror && first) mirror.value = first.value;
      });
      // Sincronizar al cargar por si solo hay un campo.
      var first = form.querySelector('[name^="access_field["]');
      if (mirror && first) mirror.value = first.value;
    })();
    </script>

    <?php
    $examSchedMeta = [];
    if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
        $decodedExtra = json_decode($tracking['extra_json'], true);
        if (is_array($decodedExtra) && is_array($decodedExtra['exam_schedule'] ?? null)) {
            $examSchedMeta = $decodedExtra['exam_schedule'];
        }
    }
    $pendingExamAuth = ($examSchedMeta['status'] ?? '') === 'pending_admin';
    ?>
    <?php if ($pendingExamAuth): ?>
        <div class="callout callout-info" style="margin-top:.85rem">
            <strong>Fecha pendiente de autorización</strong>
            <div class="muted" style="font-size:.85rem;margin:.35rem 0">
                Tipo: <?= e((string) ($examSchedMeta['kind'] ?? '—')) ?>
                <?php if (!empty($examSchedMeta['surcharge_amount'])): ?>
                    · cargo extra: <?= e(money($examSchedMeta['surcharge_amount'])) ?>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/examen/autorizar')) ?>"
                  style="display:grid;gap:.55rem;max-width:420px">
                <?= csrf_field() ?>
                <label class="muted" style="font-size:.85rem;font-weight:600">
                    Fecha autorizada
                    <input type="date" name="exam_date" required
                           value="<?= e((string) ($tracking['exam_date'] ?? '')) ?>"
                           style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
                <label class="muted" style="font-size:.85rem;font-weight:600">
                    Hora autorizada
                    <input type="time" name="exam_time" required
                           value="<?= e(substr((string) ($tracking['exam_time'] ?? '11:00'), 0, 5)) ?>"
                           style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
                <label class="muted" style="font-size:.85rem;font-weight:600">
                    Nota interna (opcional)
                    <input type="text" name="note" placeholder="Autorizado por excepción…"
                           style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
                <button class="btn btn-accent" type="submit">Autorizar fecha</button>
            </form>
        </div>
    <?php endif; ?>
</div>


<?php
$providerCfg = null;
try {
    $providerCfg = \App\Services\ProviderRequestService::configForProduct([
        'config_json' => $tracking['config_json'] ?? null,
        'group_config_json' => $tracking['group_config_json'] ?? null,
        'id' => $tracking['product_id'] ?? 0,
        'name' => $tracking['product_name'] ?? '',
        'code' => $tracking['product_code'] ?? '',
    ]);
} catch (Throwable $e) {
    $providerCfg = null;
}
$providerPending = false;
$adminProof = null;
if ($providerCfg) {
    $providerSvc = new \App\Services\ProviderRequestService();
    $providerPending = $providerSvc->isPendingSend($tracking);
    $adminProof = $providerSvc->findAdminPaymentProof((int) $tracking['id']);
}
$needsAdminProof = $providerCfg && !empty($providerCfg['require_admin_payment_proof']);
?>
<?php if ($providerCfg && (string) ($tracking['purchase_status'] ?? '') === 'paid'): ?>
<div class="panel" style="margin-top:1rem;border:2px solid #f59e0b;background:#fffbeb">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Solicitud al proveedor</h2>
    <p class="muted" style="margin-top:0;font-size:.88rem">
        Este producto requiere enviar solicitud al proveedor
        <?= !empty($providerCfg['to']) ? ' (<strong>' . e($providerCfg['to']) . '</strong>)' : '' ?>.
        <?php if ($providerPending): ?>
            <strong style="color:#b45309">Pendiente de envío.</strong>
        <?php else: ?>
            Puedes reenviar el correo si hace falta.
        <?php endif; ?>
    </p>

    <div style="margin:.75rem 0;padding:.75rem;border:1px dashed #f59e0b;border-radius:10px;background:#fff">
        <p style="margin:0 0 .5rem;font-size:.9rem;font-weight:700;color:var(--doceo-blue)">
            Comprobante de pago DOCEO → proveedor
        </p>
        <p class="muted" style="margin:0 0 .65rem;font-size:.82rem">
            Súbelo aquí (ya no aparece en la tabla de Operación). No se solicita al proveedor
            hasta que exista este comprobante
            <?= $needsAdminProof ? '(obligatorio en este grupo)' : '(recomendado)' ?>.
        </p>
        <?php if ($adminProof): ?>
            <p class="muted" style="font-size:.85rem;margin:0 0 .55rem">
                Subido: <code><?= e((string) ($adminProof['original_name'] ?? $adminProof['storage_path'] ?? '')) ?></code>
                <?php if (!empty($adminProof['created_at'])): ?>
                    · <?= e((string) $adminProof['created_at']) ?>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="muted" style="font-size:.85rem;margin:0 0 .55rem;color:#b45309">
                Aún no hay comprobante admin.
            </p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/comprobante-proveedor')) ?>"
              enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center">
            <?= csrf_field() ?>
            <input type="file" name="provider_payment_proof" accept=".pdf,.jpg,.jpeg,.png,.webp" required
                   style="padding:.4rem;border:1px solid #cfd8e6;border-radius:8px;background:#fff">
            <button type="submit" class="btn btn-primary btn-sm">
                <?= $adminProof ? 'Reemplazar comprobante' : 'Subir comprobante' ?>
                <?= !empty($providerCfg['auto_send_on_admin_proof']) ? ' y enviar' : '' ?>
            </button>
        </form>
    </div>

    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/solicitud-proveedor')) ?>"
          class="tracking-provider-mail-form"
          data-has-admin-proof="<?= $adminProof ? '1' : '0' ?>"
          enctype="multipart/form-data"
          style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
        <?= csrf_field() ?>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Comprobante DOCEO → proveedor (opcional)
            <input type="file" name="provider_payment_proof" accept=".pdf,.jpg,.jpeg,.png,.webp"
                   style="padding:.4rem;border:1px solid #cfd8e6;border-radius:8px;background:#fff;font-weight:500">
        </label>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
            <input type="checkbox" name="include_payment_proof" value="1" checked>
            Incluir <strong>enlace</strong> al comprobante en el correo
        </label>
        <p class="muted" style="flex-basis:100%;margin:0;font-size:.78rem;color:#64748b">
            No se adjunta el archivo (Neubox bloquea adjuntos). Si está marcado, se rellena
            <code>{{comprobante_url}}</code> en la plantilla.
        </p>
        <input type="hidden" name="skip_admin_proof" id="tracking-skip-admin-proof" value="0">
        <button type="submit" class="btn btn-accent btn-sm" name="provider_send_mode" value="upload"
                onclick="document.getElementById('tracking-skip-admin-proof').value='0'">
            <?= $providerPending ? 'Subir (si hay) y enviar' : 'Reenviar solicitud' ?>
        </button>
        <button type="submit" class="btn btn-ghost btn-sm" name="provider_send_mode" value="omit"
                onclick="document.getElementById('tracking-skip-admin-proof').value='1'">
            Omitir comprobante y enviar
        </button>
    </form>
    <p class="muted" style="font-size:.8rem;margin:.55rem 0 0">
        Si el grupo exige comprobante y aún no hay uno, usa «Omitir…» solo cuando realmente no aplique.
    </p>
</div>
<?php endif; ?>

<?php if ($isEletUks && (string) ($tracking['purchase_status'] ?? '') === 'paid'): ?>
<?php if (in_array($current, ['registro', 'confirm_pago'], true)): ?>
<div class="panel" style="margin-top:1rem;border:2px solid #f59e0b;background:#fffbeb">
    <p style="margin:0;font-size:.9rem">
        Este caso quedó en <strong><?= e($current) ?></strong> tras confirmar el pago.
        Usa «Reenviar correo a UKS» para moverlo a solicitud UKS y notificar a UKS.
    </p>
</div>
<?php endif; ?>
<div class="panel" style="margin-top:1rem;border:2px solid #dbeafe">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Solicitud a UKS</h2>
    <p class="muted" style="margin-top:0;font-size:.88rem">
        Al confirmar el pago se envía a UKS el nombre del alumno, fecha/hora del examen y un <strong>enlace al reglamento firmado</strong> (sin adjuntos en el correo).
        Opcionalmente incluye enlace al comprobante. Luego descarga el CSV, súbelo en UKS y captura folio y clave abajo.
    </p>
    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/uks-solicitud')) ?>" style="margin-top:.75rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center">
        <?= csrf_field() ?>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
            <input type="checkbox" name="include_payment_proof" value="1">
            Incluir enlace al comprobante de pago
        </label>
        <button type="submit" class="btn btn-ghost btn-sm">Reenviar correo a UKS</button>
    </form>
</div>

<div class="panel" style="margin-top:1rem;border:2px solid var(--doceo-yellow)">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Accesos examen ELeT (folio y clave)</h2>
    <p class="muted" style="margin-top:0;font-size:.88rem">
        El <strong>folio</strong> es único por alumno. La <strong>clave del día</strong> es la misma para todos los que presentan en la misma fecha.
    </p>
    <?php if ($accessKeyHint): ?>
        <p class="muted" style="font-size:.85rem;margin:.5rem 0">
            Referencia misma fecha: clave del día usada en otro caso =
            <code style="font-family:ui-monospace,monospace"><?= e($accessKeyHint) ?></code>
        </p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/elet-accesos')) ?>" style="margin-top:.75rem">
        <?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;max-width:520px">
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Folio UKS (único) *
                <input type="text" name="folio" required value="<?= e((string) ($tracking['folio'] ?? '')) ?>" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Clave del día *
                <input type="text" name="access_key" required value="<?= e((string) ($tracking['access_key'] ?? $accessKeyHint ?? '')) ?>" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
        </div>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;margin:.85rem 0;font-size:.88rem">
            <input type="checkbox" name="notify" value="1" checked> Enviar correo al alumno con link y accesos
        </label>
        <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
            Link del examen: <a href="<?= e($eletExamUrl) ?>" target="_blank" rel="noopener"><?= e($eletExamUrl) ?></a>
        </p>
        <button class="btn btn-accent" type="submit">Guardar y avisar al alumno</button>
    </form>
</div>
<?php endif; ?>

<?php if (
    (
        !empty($resultsDelivery['enabled'])
        || !empty($inventoryEnabled)
    )
    && (string) ($tracking['purchase_status'] ?? '') === 'paid'
): ?>
<?php
$resultsDelivery = is_array($resultsDelivery ?? null)
    ? $resultsDelivery
    : ['fields' => [], 'enabled' => false, 'cancel_template' => ''];
$resultsState = is_array($resultsState ?? null) ? $resultsState : [
    'values' => [],
    'cancelled' => false,
    'cancel_reason' => '',
];
$resultsFields = is_array($resultsDelivery['fields'] ?? null) ? $resultsDelivery['fields'] : [];
$resultsValues = is_array($resultsState['values'] ?? null) ? $resultsState['values'] : [];
$resultsStepCode = (string) ($resultsStepCode ?? '');
$deliveryEnabled = !empty($resultsDelivery['enabled']);
$isCancelled = $deliveryEnabled && !empty($resultsState['cancelled']);
?>
<div class="panel" style="margin-top:1rem;border:2px solid #bbf7d0;background:#f0fdf4">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">
        <?= $deliveryEnabled ? 'Resultados / cancelación' : 'Resultados + CENNI (inventario)' ?>
    </h2>
    <p class="muted" style="margin-top:0;font-size:.88rem">
        <?php if ($deliveryEnabled): ?>
            Campos configurados en el grupo.
            Al guardar se avanza al paso <code>resultados</code>.
            <?php if ($resultsStepCode !== ''): ?>
                El envío usa el paso <code><?= e($resultsStepCode) ?></code> (Enviar correo + resultados).
            <?php endif; ?>
        <?php else: ?>
            Al guardar se avanza al paso <code>resultados</code> y, si está marcado, se envía la plantilla
            de resultados CENNI al alumno.
        <?php endif; ?>
    </p>
    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/resultados')) ?>"
          enctype="multipart/form-data" style="margin-top:.75rem" id="results-form">
        <?= csrf_field() ?>
        <?php if ($resultsStepCode !== ''): ?>
            <input type="hidden" name="results_step_code" value="<?= e($resultsStepCode) ?>">
        <?php endif; ?>
        <?php if ($deliveryEnabled): ?>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;margin:0 0 .85rem;font-size:.88rem;font-weight:600">
            <input type="checkbox" name="cancelled" value="1" id="results-cancelled"
                <?= $isCancelled ? 'checked' : '' ?>>
            Examen cancelado (enviar plantilla de cancelación)
        </label>
        <div id="results-cancel-fields" style="<?= $isCancelled ? '' : 'display:none' ?>">
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;max-width:720px">
                Motivo de cancelación
                <textarea name="cancel_reason" rows="3"
                          style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;font:inherit"
                          placeholder="Motivo que verá el alumno…"><?= e((string) ($resultsState['cancel_reason'] ?? '')) ?></textarea>
            </label>
        </div>
        <?php endif; ?>
        <div id="results-data-fields" style="<?= $isCancelled ? 'display:none' : '' ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;max-width:720px">
                <?php if ($deliveryEnabled): ?>
                    <?php foreach ($resultsFields as $field):
                        if (!is_array($field)) {
                            continue;
                        }
                        $fCode = (string) ($field['code'] ?? '');
                        if ($fCode === '') {
                            continue;
                        }
                        $fType = (string) ($field['type'] ?? 'text');
                        $fLabel = (string) ($field['label'] ?? $fCode);
                        $fReq = !empty($field['required']);
                        $rawVal = $resultsValues[$fCode] ?? null;
                        ?>
                        <?php if ($fType === 'pdf'): ?>
                            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;grid-column:1/-1">
                                <?= e($fLabel) ?><?= $fReq ? ' *' : '' ?>
                                <input type="file" name="results_file[<?= e($fCode) ?>]" accept=".pdf,application/pdf"
                                       style="padding:.35rem 0" <?= $fReq && !(is_array($rawVal) && !empty($rawVal['path'])) ? 'required' : '' ?>>
                                <?php if (is_array($rawVal) && !empty($rawVal['path'])): ?>
                                    <span class="muted" style="font-weight:500;font-size:.8rem">
                                        Actual:
                                        <a href="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/resultados-pdf?field=' . rawurlencode($fCode))) ?>"
                                           target="_blank" rel="noopener">
                                            <?= e((string) ($rawVal['name'] ?: 'Ver PDF')) ?>
                                        </a>
                                    </span>
                                <?php endif; ?>
                            </label>
                        <?php elseif ($fType === 'url'): ?>
                            <?php
                            $urlVal = is_string($rawVal) || is_numeric($rawVal)
                                ? (string) $rawVal
                                : \App\Services\ResultsDeliveryService::displayValue($field, $resultsValues, $tracking);
                            ?>
                            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;grid-column:1/-1">
                                <?= e($fLabel) ?><?= $fReq ? ' *' : '' ?>
                                <input type="url" name="results_value[<?= e($fCode) ?>]"
                                       value="<?= e($urlVal) ?>"
                                       placeholder="https://…"
                                       <?= $fReq ? 'required' : '' ?>
                                       style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                            </label>
                        <?php else: ?>
                            <?php
                            $textVal = is_string($rawVal) || is_numeric($rawVal)
                                ? (string) $rawVal
                                : \App\Services\ResultsDeliveryService::displayValue($field, $resultsValues, $tracking);
                            ?>
                            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                                <?= e($fLabel) ?><?= $fReq ? ' *' : '' ?>
                                <input type="text" name="results_value[<?= e($fCode) ?>]"
                                       value="<?= e($textVal) ?>"
                                       <?= $fReq ? 'required' : '' ?>
                                       style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                            </label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($inventoryEnabled) && !$deliveryEnabled): ?>
                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        Nivel
                        <input type="text" name="results_level" value="<?= e((string) ($tracking['results_level'] ?? '')) ?>"
                               placeholder="B2 / 4.5 …"
                               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    </label>
                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        Puntaje
                        <input type="text" name="results_score" value="<?= e((string) ($tracking['results_score'] ?? '')) ?>"
                               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    </label>
                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        Folio CENNI
                        <input type="text" name="cenni_folio" value="<?= e((string) ($tracking['cenni_folio'] ?? '')) ?>"
                               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    </label>
                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;grid-column:1/-1">
                        URL certificado / resultados
                        <input type="url" name="results_url" value="<?= e((string) ($tracking['results_url'] ?? '')) ?>"
                               placeholder="https://…"
                               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    </label>
                <?php elseif (!empty($inventoryEnabled)): ?>
                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        Folio CENNI
                        <input type="text" name="cenni_folio" value="<?= e((string) ($tracking['cenni_folio'] ?? '')) ?>"
                               style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    </label>
                <?php endif; ?>
            </div>
        </div>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;margin:.85rem 0;font-size:.88rem">
            <input type="checkbox" name="notify" value="1" id="results-notify" checked>
            Enviar correo al alumno
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem">
            <button class="btn btn-ghost" type="submit" id="results-save-only">Guardar</button>
            <button class="btn btn-accent" type="submit" id="results-save-send">Guardar y enviar</button>
        </div>
    </form>
    <?php if (!empty($inventoryEnabled) && (!empty($tracking['folio']) || !empty($tracking['access_key']))): ?>
        <p class="muted" style="font-size:.82rem;margin:.85rem 0 0">
            Acceso examen (inventario): folio <code><?= e((string) ($tracking['folio'] ?? '')) ?></code>
            · clave <code><?= e((string) ($tracking['access_key'] ?? '')) ?></code>
        </p>
    <?php endif; ?>
</div>
<script>
(function () {
  var cb = document.getElementById('results-cancelled');
  var cancelBox = document.getElementById('results-cancel-fields');
  var dataBox = document.getElementById('results-data-fields');
  var notify = document.getElementById('results-notify');
  var saveOnly = document.getElementById('results-save-only');
  var saveSend = document.getElementById('results-save-send');
  if (cb && cancelBox && dataBox) {
    function sync() {
      var on = !!cb.checked;
      cancelBox.style.display = on ? '' : 'none';
      dataBox.style.display = on ? 'none' : '';
      dataBox.querySelectorAll('[required]').forEach(function (el) {
        el.disabled = on;
      });
    }
    cb.addEventListener('change', sync);
    sync();
  }
  if (notify && saveOnly) {
    saveOnly.addEventListener('click', function () { notify.checked = false; });
  }
  if (notify && saveSend) {
    saveSend.addEventListener('click', function () { notify.checked = true; });
  }
})();
</script>
<?php endif; ?>

<?php require BASE_PATH . '/views/shared/uks_report.php'; ?>

<?php if (!empty($exportTemplateCode) && in_array((string) ($tracking['purchase_status'] ?? ''), ['paid'], true)): ?>
<div class="panel" style="margin-top:1rem;border:2px solid #dbeafe">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Exportar a UKS</h2>
    <p class="muted" style="margin-top:0">
        Genera el CSV con el formato <em>Plantilla Instituto DOCEO</em> para registrar al alumno en la plataforma UKS.
    </p>
    <a class="btn btn-primary" href="<?= e(url('/admin/exportaciones/' . $exportTemplateCode . '?tracking_id=' . (int) $tracking['id'])) ?>">
        Descargar CSV UKS (este alumno)
    </a>
</div>
<?php endif; ?>

<?php if (($tracking['platform_type'] ?? '') === 'moodle' || ($tracking['product_type'] ?? '') === 'course'): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Campus Moodle</h2>
    <?php if (empty($moodleConfigured)): ?>
        <p class="muted">Configura <code>MOODLE_URL</code> y <code>MOODLE_TOKEN</code> en el .env. Revisa /admin/salud.</p>
    <?php endif; ?>
    <?php if (!empty($tracking['moodle_username'])): ?>
        <p>
            Usuario: <code><?= e($tracking['moodle_username']) ?></code>
            <?php if (!empty($tracking['moodle_password'])): ?>
                · Contraseña: <code><?= e($tracking['moodle_password']) ?></code>
            <?php endif; ?>
        </p>
        <p class="muted" style="font-size:.85rem">
            Acceso:
            <?= e($tracking['moodle_access_starts_at'] ?? '—') ?>
            →
            <?= e($tracking['moodle_access_ends_at'] ?? '—') ?>
            <?php if (!empty($tracking['moodle_course_id'])): ?>
                · course id <?= (int) $tracking['moodle_course_id'] ?>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p class="muted">Aún no hay alta Moodle en este caso.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tracking['id'] . '/moodle')) ?>" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;margin-top:.75rem">
        <?= csrf_field() ?>
        <button class="btn btn-primary" type="submit">Sincronizar Moodle</button>
    </form>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Documentos</h2>
    <?php if ($documents === []): ?>
        <p class="muted">Sin documentos en este caso (normal si el producto no los pide en checkout).</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Tipo</th><th>Archivo</th><th>Estatus</th><th>Motivo</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td><?= e($d['doc_type']) ?></td>
                        <td>
                            <a href="<?= e(url('/admin/documentos/' . $d['id'] . '/ver')) ?>" target="_blank" rel="noopener">
                                <?= e($d['original_name']) ?>
                            </a>
                        </td>
                        <td><span class="pill"><?= e($d['status']) ?></span></td>
                        <td class="muted"><?= e($d['rejection_reason'] ?? '') ?></td>
                        <td style="white-space:nowrap">
                            <?php if ($d['status'] === 'pending' || $d['status'] === 'rejected'): ?>
                                <form method="post" action="<?= e(url('/admin/documentos/' . $d['id'] . '/aprobar')) ?>" style="display:inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-primary btn-sm" type="submit">Aprobar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($d['status'] !== 'rejected'): ?>
                                <form method="post" action="<?= e(url('/admin/documentos/' . $d['id'] . '/rechazar')) ?>" style="display:inline-flex;gap:.35rem;align-items:center;margin-top:.35rem">
                                    <?= csrf_field() ?>
                                    <input type="text" name="reason" required placeholder="Motivo rechazo" style="padding:.3rem .5rem;border:1px solid #cfd8e6;border-radius:8px;max-width:160px">
                                    <button class="btn btn-ghost btn-sm" type="submit">Rechazar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Historial</h2>
    <?php if ($logs === []): ?>
        <p class="muted">Sin eventos.</p>
    <?php else: ?>
        <ul style="margin:0;padding-left:1.1rem">
            <?php foreach ($logs as $l): ?>
                <li>
                    <code><?= e($l['step_code']) ?></code>
                    <?php if (!empty($l['note'])): ?> — <?= e($l['note']) ?><?php endif; ?>
                    <span class="muted" style="font-size:.82rem">
                        · <?= e($l['created_at']) ?>
                        <?php if (!empty($l['first_name'])): ?>
                            · <?= e(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name_p'] ?? ''))) ?>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
