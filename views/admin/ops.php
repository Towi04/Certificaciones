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
            <p class="muted" style="margin:.35rem 0 0;max-width:48rem">
                Una sola tabla con los casos. Los botones salen de la configuración del grupo
                (confirmar pago, solicitud al proveedor, accesos, etc.). El comprobante DOCEO→proveedor
                se sube en el <strong>detalle</strong> del caso.
            </p>
        </div>
        <div class="ops-header-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/operacion/exportar?' . http_build_query(array_filter(['view' => $view, 'q' => $q ?: null])))) ?>">
                Descargar Excel (CSV)
            </a>
        </div>
    </div>

    <div class="ops-legend" aria-label="Leyenda de botones">
        <span class="ops-legend-item"><span class="ops-swatch ops-swatch--pending"></span> Amarillo = pendiente <?= icon('clock') ?></span>
        <span class="ops-legend-item"><span class="ops-swatch ops-swatch--done"></span> Verde = ya hecho (clic = reenviar) <?= icon('check') ?></span>
        <span class="ops-legend-item"><span class="ops-swatch ops-swatch--ghost"></span> Blanco = ver / detalle</span>
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
                                <input class="ops-input ops-folio" type="text"
                                       id="ops-folio-<?= $tid ?>"
                                       name="folio_display"
                                       value="<?= e((string) ($r['folio'] ?? '')) ?>"
                                       placeholder="Folio" autocomplete="off" data-tid="<?= $tid ?>">
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['show_folio_fields'])): ?>
                                <input class="ops-input ops-key" type="text"
                                       id="ops-key-<?= $tid ?>"
                                       name="access_key_display"
                                       value="<?= e((string) ($r['access_key'] ?? '')) ?>"
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
                                $done = !empty($btn['done']);
                                $btnClass = $done ? 'btn btn-ops-done btn-sm' : 'btn btn-accent btn-sm';
                                $statusIcon = $done ? icon('check') : icon('clock');
                                ?>
                                <?php if ($action === \App\Services\GroupStepConfig::ACTION_CONFIRM_PAYMENT): ?>
                                    <form method="post" action="<?= e(url('/admin/compras/' . $pid . '/confirmar-pago')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <button class="<?= e($btnClass) ?>" type="submit">
                                            <span class="ops-btn-ico"><?= $statusIcon ?></span><?= e($label) ?>
                                        </button>
                                    </form>
                                    <?php if (!empty($r['payment_proof_path'])): ?>
                                        <button type="button" class="btn btn-ghost btn-sm ops-proof-btn"
                                                data-proof-url="<?= e(url('/admin/compras/' . $pid . '/comprobante')) ?>"
                                                data-proof-title="Comprobante alumno · <?= e((string) ($r['matricula'] ?? '')) ?>">
                                            Ver comprobante alumno
                                        </button>
                                    <?php endif; ?>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_SEND_MAIL): ?>
                                    <?php
                                    $audience = (string) ($btn['audience'] ?? ($btn['email']['audience'] ?? 'student'));
                                    $isProviderMail = $audience === 'provider';
                                    ?>
                                    <?php if ($isProviderMail): ?>
                                    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/solicitud-proveedor')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="include_payment_proof" value="1">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="<?= e($btnClass) ?>" type="submit"
                                            <?= empty($r['admin_proof_uploaded']) ? 'title="Sube el comprobante DOCEO en Detalle si el grupo lo exige"' : '' ?>>
                                            <span class="ops-btn-ico"><?= $statusIcon ?></span><?= e($label) ?>
                                        </button>
                                    </form>
                                    <?php if (!empty($r['admin_proof_uploaded'])): ?>
                                        <span class="ops-mini-ok" title="Comprobante DOCEO→proveedor listo (detalle)"><?= icon('check') ?> comprobante</span>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/avanzar')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="<?= e($btnClass) ?>" type="submit">
                                            <span class="ops-btn-ico"><?= $statusIcon ?></span><?= e($label) ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_EXAM_ACCESS): ?>
                                    <form method="post" action="<?= e(url('/admin/operacion/' . $tid . '/accesos')) ?>"
                                          class="ops-inline-form ops-access-form" id="ops-access-form-<?= $tid ?>"
                                          data-tid="<?= $tid ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="folio" class="ops-access-folio-hidden" value="<?= e((string) ($r['folio'] ?? '')) ?>">
                                        <input type="hidden" name="access_key" class="ops-access-key-hidden" value="<?= e((string) ($r['access_key'] ?? '')) ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit" name="notify" value="0" title="Guardar folio y clave sin enviar correo">
                                            Guardar
                                        </button>
                                        <button class="<?= e($btnClass) ?>" type="submit" name="notify" value="1">
                                            <span class="ops-btn-ico"><?= $statusIcon ?></span><?= e($label) ?>
                                        </button>
                                    </form>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_EDIT_EXAM): ?>
                                    <?php
                                    $examDateVal = (string) ($r['exam_date'] ?? '');
                                    $examTimeVal = !empty($r['exam_time']) ? substr((string) $r['exam_time'], 0, 5) : '';
                                    ?>
                                    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/examen')) ?>"
                                          class="ops-inline-form ops-collect-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <input type="hidden" name="notify" value="0">
                                        <div class="ops-collect-fields">
                                            <input class="ops-input" type="date" name="exam_date" required
                                                   value="<?= e($examDateVal) ?>" title="Fecha examen">
                                            <input class="ops-input" type="time" name="exam_time"
                                                   value="<?= e($examTimeVal) ?>" title="Hora examen">
                                        </div>
                                        <button class="<?= e($btnClass) ?>" type="submit">
                                            <span class="ops-btn-ico"><?= $statusIcon ?></span><?= e($label) ?>
                                        </button>
                                    </form>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_EDIT_STUDENT): ?>
                                    <details class="ops-collect-details">
                                        <summary class="<?= e($btnClass) ?>" style="list-style:none;cursor:pointer">
                                            <span class="ops-btn-ico"><?= $statusIcon ?></span><?= e($label) ?>
                                        </summary>
                                        <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/alumno')) ?>"
                                              class="ops-inline-form ops-collect-form" style="margin-top:.4rem">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_ops" value="1">
                                            <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                            <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                            <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                            <div class="ops-collect-fields ops-collect-fields--stack">
                                                <input class="ops-input" type="text" name="first_name" required
                                                       value="<?= e((string) ($r['first_name'] ?? '')) ?>" placeholder="Nombre(s)">
                                                <input class="ops-input" type="text" name="last_name_p" required
                                                       value="<?= e((string) ($r['last_name_p'] ?? '')) ?>" placeholder="Apellido paterno">
                                                <input class="ops-input" type="text" name="last_name_m"
                                                       value="<?= e((string) ($r['last_name_m'] ?? '')) ?>" placeholder="Apellido materno">
                                                <input class="ops-input" type="email" name="email"
                                                       value="<?= e((string) ($r['student_email'] ?? '')) ?>" placeholder="Correo">
                                                <input class="ops-input" type="text" name="phone"
                                                       value="<?= e((string) ($r['student_phone'] ?? '')) ?>" placeholder="Teléfono">
                                            </div>
                                            <button class="btn btn-accent btn-sm" type="submit">Guardar datos</button>
                                        </form>
                                    </details>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_ADVANCE): ?>
                                    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/avanzar')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="<?= e($btnClass) ?>" type="submit">
                                            <span class="ops-btn-ico"><?= $statusIcon ?></span><?= e($label) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <?php if (!empty($r['show_folio_fields']) && empty(array_filter($opsButtons, static fn ($b) => ($b['action'] ?? '') === \App\Services\GroupStepConfig::ACTION_EXAM_ACCESS))): ?>
                                <form method="post" action="<?= e(url('/admin/operacion/' . $tid . '/accesos')) ?>"
                                      class="ops-inline-form ops-access-form" id="ops-access-form-<?= $tid ?>"
                                      data-tid="<?= $tid ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                    <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                    <input type="hidden" name="folio" class="ops-access-folio-hidden" value="<?= e((string) ($r['folio'] ?? '')) ?>">
                                    <input type="hidden" name="access_key" class="ops-access-key-hidden" value="<?= e((string) ($r['access_key'] ?? '')) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit" name="notify" value="0">Guardar folio/clave</button>
                                </form>
                            <?php endif; ?>

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
.ops-actions { min-width:12.5rem; }
.ops-inline-form { display:flex; flex-wrap:wrap; gap:.3rem; margin:.15rem 0; align-items:center; }
.ops-collect-fields { display:flex; flex-wrap:wrap; gap:.3rem; align-items:center; }
.ops-collect-fields--stack { flex-direction:column; align-items:stretch; width:100%; }
.ops-collect-fields .ops-input { min-width:7.5rem; }
.ops-collect-details {
  margin:.15rem 0; padding:.35rem .45rem; border:1px solid #e6ebf2; border-radius:10px; background:#fff;
}
.ops-collect-details > summary {
  display:inline-flex; align-items:center; gap:.35rem;
}
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
.ops-legend {
  display:flex; flex-wrap:wrap; gap:.75rem 1.25rem; margin:0 0 .85rem;
  padding:.55rem .8rem; border-radius:12px; background:#f7fafc; border:1px solid #e6ebf2;
  font-size:.8rem; color:#445; font-weight:600;
}
.ops-legend-item { display:inline-flex; align-items:center; gap:.4rem; }
.ops-swatch {
  width:.85rem; height:.85rem; border-radius:4px; display:inline-block; border:1px solid rgba(0,0,0,.08);
}
.ops-swatch--pending { background: var(--doceo-yellow, #f5c518); }
.ops-swatch--done { background:#16a34a; }
.ops-swatch--ghost { background:#fff; border-color:#cfd8e6; }
.btn-ops-done {
  background:#16a34a !important; border-color:#15803d !important; color:#fff !important;
}
.btn-ops-done:hover { filter:brightness(.95); }
.ops-btn-ico {
  display:inline-flex; align-items:center; margin-right:.3rem; vertical-align:-2px;
}
.ops-file-btn { position:relative; overflow:hidden; cursor:pointer; }
.ops-file-btn input[type="file"] {
  position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
}
.ops-mini-ok {
  display:inline-flex; align-items:center; gap:.2rem;
  font-size:.72rem; font-weight:700; color:#15803d; background:#e8f7ee;
  border-radius:999px; padding:.1rem .45rem;
}

.ops-proof-modal[hidden] { display:none !important; }
.ops-proof-modal {
  position:fixed; inset:0; z-index:80; display:flex; align-items:center; justify-content:center;
  padding:1rem;
}
.ops-proof-backdrop {
  position:absolute; inset:0; background:rgba(16,42,86,.55); border:0; padding:0; cursor:pointer;
}
.ops-proof-dialog {
  position:relative; z-index:1; width:min(920px, 96vw); max-height:90vh;
  background:#fff; border-radius:16px; box-shadow:0 24px 64px rgba(0,0,0,.28);
  display:flex; flex-direction:column; overflow:hidden;
}
.ops-proof-head {
  display:flex; align-items:center; justify-content:space-between; gap:.75rem;
  padding:.85rem 1rem; border-bottom:1px solid #e6ebf2; background:#f7fafc;
}
.ops-proof-head strong { color:var(--doceo-blue); font-size:.95rem; }
.ops-proof-body { flex:1; min-height:0; background:#edf1f7; }
.ops-proof-frame {
  width:100%; height:min(72vh, 780px); border:0; background:#fff; display:block;
}
</style>

<div class="ops-proof-modal" id="ops-proof-modal" hidden>
    <button type="button" class="ops-proof-backdrop" id="ops-proof-backdrop" aria-label="Cerrar"></button>
    <div class="ops-proof-dialog" role="dialog" aria-modal="true" aria-labelledby="ops-proof-title">
        <div class="ops-proof-head">
            <strong id="ops-proof-title">Comprobante</strong>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <a class="btn btn-ghost btn-sm" id="ops-proof-open" href="#" target="_blank" rel="noopener">Abrir en pestaña</a>
                <button type="button" class="btn btn-primary btn-sm" id="ops-proof-close">Cerrar</button>
            </div>
        </div>
        <div class="ops-proof-body">
            <iframe class="ops-proof-frame" id="ops-proof-frame" title="Vista del comprobante"></iframe>
        </div>
    </div>
</div>

<script>
(function () {
  var checkAll = document.getElementById('ops-check-all');
  var bar = document.getElementById('ops-bulk-bar');
  var countEl = document.getElementById('ops-bulk-count');
  var clearBtn = document.getElementById('ops-bulk-clear');
  var proofModal = document.getElementById('ops-proof-modal');
  var proofFrame = document.getElementById('ops-proof-frame');
  var proofTitle = document.getElementById('ops-proof-title');
  var proofOpen = document.getElementById('ops-proof-open');
  var proofClose = document.getElementById('ops-proof-close');
  var proofBackdrop = document.getElementById('ops-proof-backdrop');

  function closeProof() {
    if (!proofModal) return;
    proofModal.hidden = true;
    if (proofFrame) proofFrame.src = 'about:blank';
  }
  function openProof(url, title) {
    if (!proofModal || !proofFrame) return;
    if (proofTitle) proofTitle.textContent = title || 'Comprobante';
    if (proofOpen) proofOpen.href = url;
    proofFrame.src = url;
    proofModal.hidden = false;
  }
  document.querySelectorAll('.ops-proof-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openProof(btn.getAttribute('data-proof-url') || '', btn.getAttribute('data-proof-title') || 'Comprobante');
    });
  });
  proofClose && proofClose.addEventListener('click', closeProof);
  proofBackdrop && proofBackdrop.addEventListener('click', closeProof);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && proofModal && !proofModal.hidden) closeProof();
  });

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

  // Sincronizar folio/clave visibles → hidden del form de accesos (el atributo form= fallaba a veces).
  function syncAccessFields(tid) {
    var folioEl = document.getElementById('ops-folio-' + tid);
    var keyEl = document.getElementById('ops-key-' + tid);
    var form = document.getElementById('ops-access-form-' + tid);
    if (!form) return false;
    var hFolio = form.querySelector('.ops-access-folio-hidden');
    var hKey = form.querySelector('.ops-access-key-hidden');
    if (hFolio) hFolio.value = folioEl ? folioEl.value.trim() : '';
    if (hKey) hKey.value = keyEl ? keyEl.value.trim() : '';
    return true;
  }
  document.querySelectorAll('.ops-folio, .ops-key').forEach(function (el) {
    el.addEventListener('input', function () {
      syncAccessFields(el.getAttribute('data-tid') || '');
    });
    el.addEventListener('change', function () {
      syncAccessFields(el.getAttribute('data-tid') || '');
    });
  });
  document.querySelectorAll('.ops-access-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var tid = form.getAttribute('data-tid') || '';
      syncAccessFields(tid);
      var hFolio = form.querySelector('.ops-access-folio-hidden');
      var hKey = form.querySelector('.ops-access-key-hidden');
      var folio = hFolio ? hFolio.value.trim() : '';
      var key = hKey ? hKey.value.trim() : '';
      if (!folio || !key) {
        e.preventDefault();
        alert('Escribe folio y clave antes de guardar o enviar accesos.');
        var folioEl = document.getElementById('ops-folio-' + tid);
        if (folioEl) folioEl.focus();
        return false;
      }
    });
  });

  // Lote: inyectar folio/clave de las filas seleccionadas (ya no usan form=ops-bulk-form).
  var bulkForm = document.getElementById('ops-bulk-form');
  if (bulkForm) {
    bulkForm.addEventListener('submit', function () {
      Array.prototype.slice.call(bulkForm.querySelectorAll('.ops-bulk-injected')).forEach(function (el) {
        el.parentNode.removeChild(el);
      });
      rowChecks().filter(function (c) { return c.checked; }).forEach(function (c) {
        var tid = c.value;
        syncAccessFields(tid);
        var folioEl = document.getElementById('ops-folio-' + tid);
        var keyEl = document.getElementById('ops-key-' + tid);
        function inject(name, value) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          input.value = value || '';
          input.className = 'ops-bulk-injected';
          bulkForm.appendChild(input);
        }
        inject('folio[' + tid + ']', folioEl ? folioEl.value : '');
        inject('access_key[' + tid + ']', keyEl ? keyEl.value : '');
      });
    });
  }
})();
</script>
