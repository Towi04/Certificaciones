<?php
/** @var list<array<string,mixed>> $rows */
/** @var array{q:?string,view:string} $filters */
/** @var array<string,string> $views */
/** @var array<string,int> $counts */
/** @var array<string,mixed> $pagination */

$view = (string) ($filters['view'] ?? 'action');
$q = (string) ($filters['q'] ?? '');
?>
<div class="ops-page">
    <div class="ops-header">
        <div>
            <h1 style="margin:0;color:var(--doceo-blue)">Operación</h1>
            <p class="muted" style="margin:.35rem 0 0;max-width:42rem">
                Una sola tabla con los casos. Aquí confirmas pagos, envías solicitud al proveedor,
                capturas folio/clave y disparas las plantillas con la info de la fila.
            </p>
        </div>
        <div class="ops-header-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/operacion/exportar?' . http_build_query(array_filter(['view' => $view, 'q' => $q ?: null])))) ?>">
                Descargar Excel (CSV)
            </a>
        </div>
    </div>

    <form method="get" class="ops-toolbar" action="<?= e(url('/admin')) ?>">
        <div class="ops-tabs" role="tablist">
            <?php foreach ($views as $key => $label): ?>
                <?php
                $href = url('/admin?' . http_build_query(array_filter(['view' => $key, 'q' => $q ?: null])));
                $active = $view === $key;
                $n = (int) ($counts[$key] ?? 0);
                ?>
                <a class="ops-tab<?= $active ? ' active' : '' ?>" href="<?= e($href) ?>">
                    <?= e($label) ?>
                    <span class="ops-tab-count"><?= $n ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="ops-search">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar matrícula, alumno, folio, producto…">
            <button class="btn btn-primary btn-sm" type="submit">Buscar</button>
        </div>
    </form>

    <?php require BASE_PATH . '/views/shared/pagination.php'; ?>

    <form method="post" action="<?= e(url('/admin/operacion/accesos-lote')) ?>" id="ops-bulk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="return_view" value="<?= e($view) ?>">
        <input type="hidden" name="return_q" value="<?= e($q) ?>">
        <input type="hidden" name="notify" value="1">
        <div class="ops-bulk-bar" id="ops-bulk-bar" hidden>
            <span id="ops-bulk-count">0</span> seleccionados
            <button class="btn btn-accent btn-sm" type="submit">Guardar folio/clave y enviar plantillas</button>
            <button class="btn btn-ghost btn-sm" type="button" id="ops-bulk-clear">Quitar selección</button>
        </div>
    </form>

    <div class="panel ops-panel">
        <div class="table-wrap ops-table-wrap">
            <table class="data ops-table">
                <thead>
                <tr>
                    <th class="ops-check"><input type="checkbox" id="ops-check-all" title="Seleccionar visibles"></th>
                    <th>Matrícula</th>
                    <th>Alumno</th>
                    <th>Producto</th>
                    <th>Pago</th>
                    <th>Paso</th>
                    <th>Examen</th>
                    <th>Folio</th>
                    <th>Clave</th>
                    <th>Triggers</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $tid = (int) $r['id'];
                    $pid = (int) $r['purchase_id'];
                    $rowClass = '';
                    if (!empty($r['needs_payment'])) {
                        $rowClass = 'ops-row--pay';
                    } elseif (!empty($r['provider_pending'])) {
                        $rowClass = 'ops-row--provider';
                    } elseif (!empty($r['needs_access'])) {
                        $rowClass = 'ops-row--access';
                    }
                    $exam = trim((string) ($r['exam_date'] ?? ''));
                    if ($exam !== '' && !empty($r['exam_time'])) {
                        $exam .= ' ' . substr((string) $r['exam_time'], 0, 5);
                    }
                    ?>
                    <tr class="<?= e($rowClass) ?>" data-tracking-id="<?= $tid ?>">
                        <td class="ops-check">
                            <?php if (!empty($r['show_folio_fields'])): ?>
                                <input type="checkbox" class="ops-row-check" form="ops-bulk-form" name="tracking_ids[]" value="<?= $tid ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= e(url('/admin/seguimientos/' . $tid)) ?>"><strong><?= e((string) $r['matricula']) ?></strong></a>
                            <?php if (!empty($r['partner_code'])): ?>
                                <div class="muted" style="font-size:.72rem"><?= e((string) $r['partner_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= e((string) ($r['student_full_name'] ?? '')) ?></div>
                            <div class="muted" style="font-size:.75rem"><?= e((string) ($r['student_email'] ?? '')) ?></div>
                            <?php if (!empty($r['student_phone'])): ?>
                                <div class="muted" style="font-size:.72rem"><?= e((string) $r['student_phone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= e((string) ($r['product_name'] ?? '')) ?></div>
                            <div class="muted" style="font-size:.72rem"><?= e((string) ($r['product_code'] ?? '')) ?></div>
                        </td>
                        <td>
                            <span class="pill"><?= e((string) ($r['purchase_status'] ?? '')) ?></span>
                            <div class="muted" style="font-size:.72rem;margin-top:.2rem"><?= money($r['charged_amount'] ?? 0) ?></div>
                        </td>
                        <td>
                            <span class="pill"><?= e((string) ($r['current_step_code'] ?? '—')) ?></span>
                            <div class="muted" style="font-size:.72rem"><?= e((string) ($r['tracking_status'] ?? '')) ?></div>
                        </td>
                        <td class="muted" style="white-space:nowrap;font-size:.82rem">
                            <?= $exam !== '' ? e($exam) : '—' ?>
                        </td>
                        <td>
                            <?php if (!empty($r['show_folio_fields'])): ?>
                                <input class="ops-input ops-folio" type="text" form="ops-bulk-form"
                                       name="folio[<?= $tid ?>]" value="<?= e((string) ($r['folio'] ?? '')) ?>"
                                       placeholder="Folio" autocomplete="off" data-tid="<?= $tid ?>">
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['show_folio_fields'])): ?>
                                <input class="ops-input ops-key" type="text" form="ops-bulk-form"
                                       name="access_key[<?= $tid ?>]" value="<?= e((string) ($r['access_key'] ?? '')) ?>"
                                       placeholder="Clave" autocomplete="off" data-tid="<?= $tid ?>">
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="ops-actions">
                            <?php
                            $opsButtons = is_array($r['ops_buttons'] ?? null) ? $r['ops_buttons'] : [];
                            foreach ($opsButtons as $btn):
                                $action = (string) ($btn['action'] ?? '');
                                $label = (string) ($btn['label'] ?? 'Acción');
                                ?>
                                <?php if ($action === \App\Services\GroupStepConfig::ACTION_CONFIRM_PAYMENT): ?>
                                    <form method="post" action="<?= e(url('/admin/compras/' . $pid . '/confirmar-pago')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <button class="btn btn-accent btn-sm" type="submit"><?= e($label) ?></button>
                                    </form>
                                    <?php if (!empty($r['payment_proof_path'])): ?>
                                        <a class="btn btn-ghost btn-sm" target="_blank" href="<?= e(url('/admin/compras/' . $pid . '/comprobante')) ?>">Ver comprobante</a>
                                    <?php endif; ?>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_SEND_MAIL): ?>
                                    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/solicitud-proveedor')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="include_payment_proof" value="1">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="btn btn-accent btn-sm" type="submit"><?= e($label) ?></button>
                                    </form>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_EXAM_ACCESS): ?>
                                    <form method="post" action="<?= e(url('/admin/operacion/' . $tid . '/accesos')) ?>" class="ops-inline-form ops-access-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="folio" class="ops-sync-folio" value="<?= e((string) ($r['folio'] ?? '')) ?>">
                                        <input type="hidden" name="access_key" class="ops-sync-key" value="<?= e((string) ($r['access_key'] ?? '')) ?>">
                                        <button class="btn btn-primary btn-sm" type="submit" name="notify" value="1"><?= e($label) ?></button>
                                    </form>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_ADVANCE): ?>
                                    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/avanzar')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="btn btn-primary btn-sm" type="submit"><?= e($label) ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <?php if ($opsButtons === []): ?>
                                <span class="ops-flag ops-flag--ok">Al día</span>
                            <?php endif; ?>

                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/seguimientos/' . $tid)) ?>">Detalle</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="10" class="muted" style="padding:1.25rem">
                            No hay casos en esta vista. Prueba <a href="<?= e(url('/admin?view=all')) ?>">Todos</a>.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.ops-page { margin-bottom: 2rem; }
.ops-header { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:flex-start; margin-bottom:1rem; }
.ops-toolbar { display:flex; flex-direction:column; gap:.75rem; margin-bottom:.85rem; }
.ops-tabs { display:flex; flex-wrap:wrap; gap:.4rem; }
.ops-tab {
  display:inline-flex; align-items:center; gap:.4rem;
  padding:.45rem .7rem; border-radius:999px; border:1px solid #d5deea;
  background:#fff; color:var(--doceo-blue); text-decoration:none; font-size:.84rem; font-weight:600;
}
.ops-tab:hover { background:#f3f7fc; text-decoration:none; }
.ops-tab.active { background:var(--doceo-blue); color:#fff; border-color:var(--doceo-blue); }
.ops-tab-count {
  min-width:1.35rem; text-align:center; padding:.05rem .35rem; border-radius:999px;
  background:rgba(0,0,0,.08); font-size:.75rem;
}
.ops-tab.active .ops-tab-count { background:rgba(255,255,255,.22); }
.ops-search { display:flex; gap:.45rem; flex-wrap:wrap; max-width:34rem; }
.ops-search input[type="search"] {
  flex:1; min-width:12rem; font:inherit; padding:.55rem .7rem; border:1px solid #cfd8e6; border-radius:10px;
}
.ops-panel { padding:0; overflow:hidden; }
.ops-table-wrap { max-height: min(70vh, 820px); overflow:auto; }
.ops-table { margin:0; border-collapse:separate; border-spacing:0; min-width:1100px; }
.ops-table th {
  position:sticky; top:0; z-index:2; background:#f7fafc; box-shadow: inset 0 -1px #e6ebf2;
}
.ops-table td, .ops-table th { vertical-align:top; }
.ops-check { width:2rem; text-align:center; }
.ops-input {
  width:7.5rem; max-width:100%; font:inherit; padding:.35rem .45rem;
  border:1px solid #cfd8e6; border-radius:8px;
}
.ops-actions { min-width:11.5rem; }
.ops-inline-form { display:flex; flex-wrap:wrap; gap:.3rem; margin:.15rem 0; }
.ops-flag { display:inline-block; font-size:.75rem; font-weight:700; padding:.15rem .4rem; border-radius:6px; }
.ops-flag--ok { background:#e8f7ee; color:#0f7a3a; }
.ops-row--pay { background:#fffbeb; }
.ops-row--provider { background:#fff7ed; }
.ops-row--access { background:#f0f7ff; }
.ops-bulk-bar {
  position:sticky; top:0; z-index:5; display:flex; flex-wrap:wrap; gap:.55rem; align-items:center;
  margin-bottom:.65rem; padding:.65rem .85rem; border-radius:12px;
  background:#102a56; color:#fff; box-shadow:0 8px 24px rgba(16,42,86,.18);
}
.ops-bulk-bar[hidden] { display:none !important; }
</style>

<script>
(function () {
  var checkAll = document.getElementById('ops-check-all');
  var bar = document.getElementById('ops-bulk-bar');
  var countEl = document.getElementById('ops-bulk-count');
  var clearBtn = document.getElementById('ops-bulk-clear');

  function rowChecks() {
    return Array.prototype.slice.call(document.querySelectorAll('.ops-row-check'));
  }

  function refreshBulk() {
    var selected = rowChecks().filter(function (c) { return c.checked; });
    if (countEl) countEl.textContent = String(selected.length);
    if (bar) bar.hidden = selected.length === 0;
  }

  checkAll && checkAll.addEventListener('change', function () {
    rowChecks().forEach(function (c) { c.checked = !!checkAll.checked; });
    refreshBulk();
  });
  rowChecks().forEach(function (c) {
    c.addEventListener('change', refreshBulk);
  });
  clearBtn && clearBtn.addEventListener('click', function () {
    rowChecks().forEach(function (c) { c.checked = false; });
    if (checkAll) checkAll.checked = false;
    refreshBulk();
  });

  document.querySelectorAll('.ops-table tbody tr').forEach(function (tr) {
    var tid = tr.getAttribute('data-tracking-id');
    if (!tid) return;
    var folioInput = tr.querySelector('.ops-folio');
    var keyInput = tr.querySelector('.ops-key');
    var form = tr.querySelector('.ops-access-form');
    if (!form || !folioInput || !keyInput) return;
    var syncFolio = form.querySelector('.ops-sync-folio');
    var syncKey = form.querySelector('.ops-sync-key');
    function sync() {
      if (syncFolio) syncFolio.value = folioInput.value;
      if (syncKey) syncKey.value = keyInput.value;
    }
    folioInput.addEventListener('input', sync);
    keyInput.addEventListener('input', sync);
    form.addEventListener('submit', sync);
  });
})();
</script>
