<?php
/** @var list<array<string,mixed>> $rows */
/** @var array{q:?string,view:string} $filters */
/** @var array<string,string> $views */
/** @var array<string,string> $viewHints */
/** @var array<string,int> $counts */
/** @var array<string,mixed> $pagination */

$view = (string) ($filters['view'] ?? 'action');
$q = (string) ($filters['q'] ?? '');
$viewHints = $viewHints ?? [];
$todayYmd = date('Y-m-d');
$tomorrowYmd = date('Y-m-d', strtotime('+1 day'));
$hasAccessEditors = false;
$extraColLabels = [];
foreach ($rows as $__r) {
    if (!empty($__r['show_folio_fields']) || !empty($__r['show_zoom_fields'])) {
        $hasAccessEditors = true;
    }
    if (!empty($__r['show_zoom_fields'])) {
        $lbl = trim((string) ($__r['extra_field_label'] ?? 'Extra'));
        if ($lbl === '') {
            $lbl = 'Extra';
        }
        $extraColLabels[$lbl] = true;
    }
}
$extraColHeader = count($extraColLabels) === 1
    ? (string) array_key_first($extraColLabels)
    : 'Extra';
?>
<div class="ops-page">
    <div class="ops-header">
        <div>
            <h1 style="margin:0;color:var(--doceo-blue)">Operación</h1>
            <p class="muted" style="margin:.35rem 0 0;max-width:48rem">
                Una sola tabla con los casos. Los botones salen de la configuración del grupo
                (confirmar pago, solicitud al proveedor, accesos, etc.). El comprobante DOCEO→proveedor
                se sube en el detalle del caso (clic en la matrícula).
            </p>
        </div>
        <div class="ops-header-actions" style="display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
            <?php if ($hasAccessEditors): ?>
                <button class="btn btn-accent btn-sm" type="submit" form="ops-bulk-form" id="ops-save-all-btn"
                        title="Guarda folio, clave y dato extra de todas las filas visibles">
                    Guardar folio/clave/extra
                </button>
            <?php endif; ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/operacion/exportar?' . http_build_query(array_filter(['view' => $view, 'q' => $q ?: null])))) ?>">
                Descargar Excel (CSV)
            </a>
        </div>
    </div>

    <div class="ops-legend" aria-label="Leyenda">
        <span class="ops-legend-item"><span class="ops-status-badge ops-status-badge--pending" style="position:static"><?= icon('clock') ?></span> Pendiente</span>
        <span class="ops-legend-item"><span class="ops-status-badge ops-status-badge--ok" style="position:static"><?= icon('check') ?></span> Enviado / hecho</span>
        <span class="ops-legend-item"><span class="ops-status-badge ops-status-badge--error" style="position:static"><?= icon('x') ?></span> Error / rebote</span>
        <span class="ops-legend-item"><span class="ops-reschedule-count" style="position:static">2</span> Número = veces reagendado</span>
        <span class="ops-legend-item"><span class="ops-exam-pill ops-exam-pill--today">Hoy</span></span>
        <span class="ops-legend-item"><span class="ops-exam-pill ops-exam-pill--tomorrow">Mañana</span></span>
        <span class="ops-legend-item"><span class="ops-exam-pill ops-exam-pill--future">Futuro</span></span>
    </div>

    <form method="get" class="ops-toolbar" action="<?= e(url('/admin')) ?>">
        <div class="ops-tabs" role="tablist">
            <?php foreach ($views as $key => $label): ?>
                <?php
                $href = url('/admin?' . http_build_query(array_filter(['view' => $key, 'q' => $q ?: null])));
                $active = $view === $key;
                $n = (int) ($counts[$key] ?? 0);
                ?>
                <a class="ops-tab<?= $active ? ' active' : '' ?>" href="<?= e($href) ?>"
                   title="<?= e((string) ($viewHints[$key] ?? '')) ?>">
                    <?= e($label) ?>
                    <span class="ops-tab-count"><?= $n ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($viewHints[$view])): ?>
            <p class="muted ops-view-hint"><?= e((string) $viewHints[$view]) ?></p>
        <?php endif; ?>
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
        <input type="hidden" name="notify" value="0">
    </form>

    <div class="panel ops-panel">
        <div class="table-wrap ops-table-wrap">
            <table class="data ops-table">
                <thead>
                <tr>
                    <th>Triggers</th>
                    <th>Matrícula</th>
                    <th>Alumno</th>
                    <th>Producto</th>
                    <th>Pago</th>
                    <th>Paso</th>
                    <th>Examen</th>
                    <th>Folio</th>
                    <th>Clave</th>
                    <th title="Campo extra del grupo (Zoom, ID escuela, código de acceso…)"><?= e($extraColHeader) ?></th>
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
                    $examDateOnly = trim((string) ($r['exam_date'] ?? ''));
                    $exam = $examDateOnly;
                    if ($exam !== '' && !empty($r['exam_time'])) {
                        $exam .= ' ' . substr((string) $r['exam_time'], 0, 5);
                    }
                    $examTone = '';
                    if ($examDateOnly !== '') {
                        if ($examDateOnly === $todayYmd) {
                            $examTone = 'today';
                        } elseif ($examDateOnly === $tomorrowYmd) {
                            $examTone = 'tomorrow';
                        } elseif ($examDateOnly > $todayYmd) {
                            $examTone = 'future';
                        } else {
                            $examTone = 'past';
                        }
                    }
                    ?>
                    <tr class="<?= e($rowClass) ?>" data-tracking-id="<?= $tid ?>">
                        <td class="ops-actions">
                            <div class="ops-actions-row">
                            <?php
                            $opsButtons = is_array($r['ops_buttons'] ?? null) ? $r['ops_buttons'] : [];
                            $opsMetaBits = [];
                            $renderOpsStatusBadge = static function (array $btn): string {
                                $status = (string) ($btn['status'] ?? '');
                                if ($status === '' || $status === 'none') {
                                    return '';
                                }
                                $detail = trim((string) ($btn['status_detail'] ?? ''));
                                $map = [
                                    'ok' => ['check', 'Enviado / listo', 'ops-status-badge--ok'],
                                    'pending' => ['clock', 'Pendiente de ejecutar', 'ops-status-badge--pending'],
                                    'error' => ['x', $detail !== '' ? $detail : 'Error al enviar', 'ops-status-badge--error'],
                                ];
                                if (!isset($map[$status])) {
                                    return '';
                                }
                                [$ico, $title, $cls] = $map[$status];

                                return '<span class="ops-status-badge ' . $cls . '" title="' . e($title)
                                    . '" aria-label="' . e($title) . '">' . icon($ico) . '</span>';
                            };
                            foreach ($opsButtons as $btn):
                                $action = (string) ($btn['action'] ?? '');
                                $label = (string) ($btn['label'] ?? 'Acción');
                                $done = !empty($btn['done']);
                                $opsIcon = trim((string) ($btn['ops_icon'] ?? ''));
                                $iconSvg = $opsIcon !== ''
                                    ? icon($opsIcon)
                                    : icon(\App\Services\GroupStepConfig::defaultOpsIcon($action, $btn));
                                if ($iconSvg === '') {
                                    $iconSvg = icon($done ? 'check' : 'clock');
                                }
                                $status = (string) ($btn['status'] ?? ($done ? 'ok' : 'pending'));
                                $btnClass = 'ops-icon-btn'
                                    . ($status === 'error' ? ' ops-icon-btn--error' : ($done ? ' ops-icon-btn--done' : ' ops-icon-btn--pending'));
                                ?>
                                <?php
                                $collectExam = !empty($btn['collect_exam'])
                                    || $action === \App\Services\GroupStepConfig::ACTION_EDIT_EXAM;
                                $audience = (string) ($btn['audience'] ?? ($btn['email']['audience'] ?? 'student'));
                                $examMailOn = !empty($btn['email']['enabled'])
                                    && trim((string) ($btn['email']['template_code'] ?? '')) !== '';
                                $stepTplCode = trim((string) ($btn['email']['template_code'] ?? ''));
                                // Reagendar / plantilla de reagenda: nunca usar flujo de solicitud a proveedor.
                                if (
                                    !$collectExam
                                    && (
                                        \App\Services\MailTemplateService::rescheduleTemplateHeuristic($stepTplCode)
                                        || preg_match('/reagend|re-?agend|reschedule/ui', (string) ($btn['label'] ?? '')) === 1
                                    )
                                ) {
                                    $collectExam = true;
                                }
                                $statusBadgeHtml = $collectExam ? '' : $renderOpsStatusBadge($btn);
                                ?>
                                <?php if (
                                    $collectExam
                                    && in_array($action, [
                                        \App\Services\GroupStepConfig::ACTION_EDIT_EXAM,
                                        \App\Services\GroupStepConfig::ACTION_ADVANCE,
                                        \App\Services\GroupStepConfig::ACTION_SEND_MAIL,
                                    ], true)
                                ): ?>
                                    <?php
                                    $examDateVal = (string) ($r['exam_date'] ?? '');
                                    $examTimeVal = !empty($r['exam_time']) ? substr((string) $r['exam_time'], 0, 5) : '';
                                    $rescheduleCount = (int) ($btn['reschedule_count'] ?? 0);
                                    $rescheduleTitle = ($examMailOn
                                        ? ($audience === 'provider'
                                            ? 'Guardar fecha y enviar plantilla al proveedor'
                                            : ($audience === 'partner'
                                                ? 'Guardar fecha y enviar plantilla al partner'
                                                : 'Guardar fecha y enviar plantilla al alumno'))
                                        : 'Guardar fecha de examen')
                                        . ($rescheduleCount > 0
                                            ? ' · Reagendado ' . $rescheduleCount . ' vez' . ($rescheduleCount === 1 ? '' : 'es')
                                            : '')
                                        . ' · ' . $label;
                                    $rescheduleSubmit = $examMailOn
                                        ? ($audience === 'provider'
                                            ? 'Guardar y enviar al proveedor'
                                            : ($audience === 'partner'
                                                ? 'Guardar y enviar al partner'
                                                : 'Guardar y enviar al alumno'))
                                        : 'Guardar fecha';
                                    ?>
                                    <button type="button"
                                            class="<?= e($btnClass) ?> ops-reschedule-btn"
                                            data-action-url="<?= e(url('/admin/seguimientos/' . $tid . '/examen')) ?>"
                                            data-csrf="<?= e(csrf_token()) ?>"
                                            data-return-view="<?= e($view) ?>"
                                            data-return-q="<?= e($q) ?>"
                                            data-step-code="<?= e((string) ($btn['code'] ?? '')) ?>"
                                            data-notify="<?= $examMailOn ? '1' : '0' ?>"
                                            data-exam-date="<?= e($examDateVal) ?>"
                                            data-exam-time="<?= e($examTimeVal) ?>"
                                            data-title="<?= e('Reagendar · ' . (string) ($r['matricula'] ?? '') . ' · ' . $label) ?>"
                                            data-submit-label="<?= e($rescheduleSubmit) ?>"
                                            title="<?= e($rescheduleTitle) ?>"
                                            aria-label="<?= e($label) ?>">
                                        <?= $iconSvg ?>
                                        <?php if ($rescheduleCount > 0): ?>
                                            <span class="ops-reschedule-count" aria-label="Reagendado <?= (int) $rescheduleCount ?> veces"><?= (int) $rescheduleCount ?></span>
                                        <?php endif; ?>
                                    </button>
                                <?php elseif (
                                    $action === \App\Services\GroupStepConfig::ACTION_CONFIRM_PAYMENT
                                    || $action === \App\Services\GroupStepConfig::ACTION_CONFIRM_PAYMENT_POPUP
                                ): ?>
                                    <?php
                                    $hasProof = !empty($r['payment_proof_path']);
                                    $proofUrl = $hasProof
                                        ? url('/admin/compras/' . $pid . '/comprobante')
                                        : '';
                                    ?>
                                    <button type="button"
                                            class="<?= e($btnClass) ?> ops-confirm-pay-btn"
                                            data-confirm-url="<?= e(url('/admin/compras/' . $pid . '/confirmar-pago')) ?>"
                                            data-proof-url="<?= e($proofUrl) ?>"
                                            data-has-proof="<?= $hasProof ? '1' : '0' ?>"
                                            data-return-view="<?= e($view) ?>"
                                            data-return-q="<?= e($q) ?>"
                                            data-csrf="<?= e(csrf_token()) ?>"
                                            data-title="<?= e((!empty($r['is_package'])
                                                ? 'Confirmar pago del paquete'
                                                : 'Confirmar pago') . ' · ' . (string) ($r['matricula'] ?? '')) ?>"
                                            title="<?= e((!empty($r['is_package'])
                                                ? 'Confirma el pago único del paquete; aplica a todos los productos de la matrícula'
                                                : 'Confirmar pago (ver comprobante)') . ' · ' . $label) ?>"
                                            aria-label="<?= e($label) ?>">
                                        <?= $iconSvg ?>
                                        <?= $statusBadgeHtml ?>
                                    </button>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_SEND_MAIL): ?>
                                    <?php
                                    $audience = (string) ($btn['audience'] ?? ($btn['email']['audience'] ?? 'student'));
                                    $sendTpl = trim((string) ($btn['email']['template_code'] ?? ''));
                                    $resultsReady = array_key_exists('results_ready', $btn)
                                        ? !empty($btn['results_ready'])
                                        : true;
                                    $resultsBlocked = trim((string) ($btn['results_blocked'] ?? ''));
                                    // Solo la solicitud inicial UKS usa ProviderRequestService
                                    // (reglamento / pago / Excel). Otras plantillas de proveedor
                                    // (p. ej. reagendar_uks) van por enviar-correo-paso.
                                    $isHeavyProviderRequest = $audience === 'provider'
                                        && (
                                            $sendTpl === ''
                                            || \App\Services\MailTemplateService::isUksSolicitudCode($sendTpl)
                                        );
                                    $mailBtnClass = $btnClass . ($resultsReady ? '' : ' ops-icon-btn--disabled');
                                    $mailTitle = $resultsReady
                                        ? (($audience === 'partner'
                                            ? 'Enviar al partner del caso'
                                            : ($audience === 'provider'
                                                ? 'Enviar plantilla al proveedor'
                                                : 'Enviar plantilla al alumno')) . ' · ' . $label)
                                        : ($resultsBlocked !== '' ? $resultsBlocked : $label);
                                    ?>
                                    <?php if ($isHeavyProviderRequest): ?>
                                    <form method="post"
                                          action="<?= e(url('/admin/seguimientos/' . $tid . '/solicitud-proveedor')) ?>"
                                          class="ops-inline-form ops-provider-mail-form"
                                          data-has-admin-proof="<?= !empty($r['admin_proof_uploaded']) ? '1' : '0' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="include_payment_proof" value="1">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="<?= e($mailBtnClass) ?>" type="submit"
                                            <?= $resultsReady ? '' : 'disabled' ?>
                                            title="<?= e(!$resultsReady
                                                ? $mailTitle
                                                : ('Enviar al proveedor · comprobante por enlace (sin adjuntos) · ' . $label)) ?>"
                                            aria-label="<?= e($label) ?>">
                                            <?= $iconSvg ?>
                                            <?= $statusBadgeHtml ?>
                                        </button>
                                    </form>
                                    <?php elseif ($audience === 'provider'): ?>
                                    <form method="post"
                                          action="<?= e(url('/admin/seguimientos/' . $tid . '/enviar-correo-paso')) ?>"
                                          class="ops-inline-form ops-provider-mail-form"
                                          data-has-admin-proof="<?= !empty($r['admin_proof_uploaded']) ? '1' : '0' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="<?= e($mailBtnClass) ?>" type="submit"
                                            <?= $resultsReady ? '' : 'disabled' ?>
                                            title="<?= e(!$resultsReady
                                                ? $mailTitle
                                                : ('Enviar al proveedor · comprobante por enlace (sin adjuntos) · ' . $label)) ?>"
                                            aria-label="<?= e($label) ?>">
                                            <?= $iconSvg ?>
                                            <?= $statusBadgeHtml ?>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/enviar-correo-paso')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="<?= e($mailBtnClass) ?>" type="submit"
                                            <?= $resultsReady ? '' : 'disabled' ?>
                                            title="<?= e($mailTitle) ?>"
                                            aria-label="<?= e($label) ?>">
                                            <?= $iconSvg ?>
                                            <?= $statusBadgeHtml ?>
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
                                        <input type="hidden" name="zoom_url" class="ops-access-zoom-hidden" value="<?= e((string) ($r['zoom_url'] ?? '')) ?>">
                                        <button class="<?= e($btnClass) ?>" type="submit" name="notify" value="1"
                                                title="<?= e('Guarda y envía la plantilla de accesos al alumno · ' . $label) ?>"
                                                aria-label="<?= e($label) ?>">
                                            <?= $iconSvg ?>
                                            <?= $statusBadgeHtml ?>
                                        </button>
                                    </form>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_EDIT_STUDENT): ?>
                                    <details class="ops-collect-details ops-collect-details--compact">
                                        <summary class="<?= e($btnClass) ?>" style="list-style:none;cursor:pointer"
                                                 title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
                                            <?= $iconSvg ?>
                                            <?= $statusBadgeHtml ?>
                                        </summary>
                                        <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/alumno')) ?>"
                                              class="ops-inline-form ops-collect-form ops-collect-popover">
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
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_DOWNLOAD_CSV): ?>
                                    <?php
                                    $csvCfg = is_array($btn['csv'] ?? null) ? $btn['csv'] : [];
                                    $csvTpl = trim((string) ($csvCfg['template_code'] ?? ''));
                                    $csvScope = (string) ($csvCfg['scope'] ?? 'student');
                                    if (!in_array($csvScope, ['student', 'exam_date'], true)) {
                                        $csvScope = 'student';
                                    }
                                    $csvQs = http_build_query([
                                        'tracking_id' => $tid,
                                        'step_code' => (string) ($btn['code'] ?? ''),
                                        'scope' => $csvScope,
                                        'return' => '/admin/operacion?' . http_build_query(array_filter([
                                            'view' => $view !== '' ? $view : null,
                                            'q' => $q !== '' ? $q : null,
                                        ])),
                                    ]);
                                    $csvTitle = $csvScope === 'exam_date'
                                        ? 'Descargar CSV del día (misma fecha y certificación)'
                                        : 'Descargar CSV de este alumno';
                                    ?>
                                    <?php if ($csvTpl !== ''): ?>
                                        <a class="<?= e($btnClass) ?>"
                                           href="<?= e(url('/admin/plantillas-csv/' . rawurlencode($csvTpl) . '/descargar?' . $csvQs)) ?>"
                                           title="<?= e($csvTitle . ' · ' . $label) ?>"
                                           aria-label="<?= e($label) ?>">
                                            <?= $iconSvg !== '' ? $iconSvg : icon('download') ?>
                                            <?= $statusBadgeHtml ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="muted" title="Configura la plantilla CSV en el grupo">CSV sin plantilla</span>
                                    <?php endif; ?>
                                <?php elseif ($action === \App\Services\GroupStepConfig::ACTION_ADVANCE): ?>
                                    <form method="post" action="<?= e(url('/admin/seguimientos/' . $tid . '/avanzar')) ?>" class="ops-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_ops" value="1">
                                        <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="step_code" value="<?= e((string) ($btn['code'] ?? '')) ?>">
                                        <button class="<?= e($btnClass) ?>" type="submit"
                                            title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
                                            <?= $iconSvg ?>
                                            <?= $statusBadgeHtml ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </div>

                            <?php if (!empty($r['payment_confirm_on_sibling']) && !empty($r['needs_payment'])): ?>
                                <?php
                                $opsMetaBits[] = '<span class="ops-mini-ok muted" title="El pago se confirma una sola vez en otra fila del mismo paquete">'
                                    . 'Mismo pago · matrícula ' . e((string) ($r['matricula'] ?? ''))
                                    . '</span>';
                                ?>
                            <?php endif; ?>

                            <?php if ($opsMetaBits !== []): ?>
                                <div class="ops-actions-meta"><?= implode('', $opsMetaBits) ?></div>
                            <?php endif; ?>

                            <?php
                            // Si el grupo no tiene botón de accesos pero sí campos folio/Zoom,
                            // el guardado va por «Guardar folio/clave/Zoom» del encabezado.
                            $hasExamAccessBtn = !empty(array_filter(
                                $opsButtons,
                                static fn ($b) => ($b['action'] ?? '') === \App\Services\GroupStepConfig::ACTION_EXAM_ACCESS
                            ));
                            $needsAccessSendForm = (!$hasExamAccessBtn)
                                && (!empty($r['show_folio_fields']) || !empty($r['show_zoom_fields']));
                            ?>
                            <?php if ($needsAccessSendForm): ?>
                                <form method="post" action="<?= e(url('/admin/operacion/' . $tid . '/accesos')) ?>"
                                      class="ops-inline-form ops-access-form" id="ops-access-form-<?= $tid ?>"
                                      data-tid="<?= $tid ?>" hidden>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_view" value="<?= e($view) ?>">
                                    <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                    <input type="hidden" name="folio" class="ops-access-folio-hidden" value="<?= e((string) ($r['folio'] ?? '')) ?>">
                                    <input type="hidden" name="access_key" class="ops-access-key-hidden" value="<?= e((string) ($r['access_key'] ?? '')) ?>">
                                    <input type="hidden" name="zoom_url" class="ops-access-zoom-hidden" value="<?= e((string) ($r['zoom_url'] ?? '')) ?>">
                                </form>
                            <?php endif; ?>

                            <?php if ($opsButtons === []): ?>
                                <span class="ops-flag ops-flag--ok">Al día</span>
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
                        <td style="white-space:nowrap;font-size:.82rem">
                            <?php if ($exam === ''): ?>
                                <span class="muted">—</span>
                            <?php else: ?>
                                <span class="ops-exam-pill ops-exam-pill--<?= e($examTone) ?>"
                                      title="<?= $examTone === 'today' ? 'Examen hoy'
                                          : ($examTone === 'tomorrow' ? 'Examen mañana'
                                          : ($examTone === 'future' ? 'Examen futuro' : 'Fecha pasada')) ?>">
                                    <?= e($exam) ?>
                                </span>
                            <?php endif; ?>
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
                        <td>
                            <?php if (!empty($r['show_zoom_fields'])): ?>
                                <?php $extraLbl = trim((string) ($r['extra_field_label'] ?? 'Extra')) ?: 'Extra'; ?>
                                <input class="ops-input ops-zoom" type="text"
                                       id="ops-zoom-<?= $tid ?>"
                                       name="zoom_url_display"
                                       value="<?= e((string) ($r['zoom_url'] ?? '')) ?>"
                                       placeholder="<?= e($extraLbl) ?>"
                                       title="<?= e($extraLbl) ?>"
                                       autocomplete="off" data-tid="<?= $tid ?>"
                                       style="min-width:9.5rem;max-width:14rem">
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
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
.ops-table td, .ops-table th { vertical-align:middle; }
.ops-check { width:2rem; text-align:center; }
.ops-input {
  width:7.5rem; max-width:100%; font:inherit; padding:.35rem .45rem;
  border:1px solid #cfd8e6; border-radius:8px;
}
.ops-actions { min-width:7.5rem; white-space:normal; }
.ops-actions-row {
  display:flex; flex-wrap:wrap; align-items:center; gap:.35rem;
}
.ops-actions-row > .ops-inline-form {
  display:inline-flex; flex-wrap:nowrap; margin:0; gap:0;
}
.ops-actions-meta {
  display:flex; flex-wrap:wrap; gap:.25rem; margin-top:.3rem;
}
.ops-table th:first-child,
.ops-table td.ops-actions {
  position: sticky;
  left: 0;
  z-index: 1;
  background: #fff;
}
.ops-table th:first-child { z-index: 3; background:#f7fafc; }
.ops-table tr.ops-row--pay td.ops-actions { background:#fffbeb; }
.ops-table tr.ops-row--provider td.ops-actions { background:#fff7ed; }
.ops-table tr.ops-row--access td.ops-actions { background:#f0f7ff; }
.ops-view-hint { margin:0; font-size:.82rem; max-width:52rem; }
.ops-exam-pill {
  display:inline-flex; align-items:center; gap:.25rem;
  padding:.2rem .5rem; border-radius:999px; font-weight:700; font-size:.78rem;
  border:1px solid transparent; line-height:1.2;
}
.ops-exam-pill--today { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
.ops-exam-pill--tomorrow { background:#ffedd5; color:#9a3412; border-color:#fed7aa; }
.ops-exam-pill--future { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
.ops-exam-pill--past { background:#f1f5f9; color:#64748b; border-color:#e2e8f0; }
.ops-inline-form { display:flex; flex-wrap:wrap; gap:.3rem; margin:.15rem 0; align-items:center; }
.ops-collect-fields { display:flex; flex-wrap:wrap; gap:.3rem; align-items:center; }
.ops-collect-fields--stack { flex-direction:column; align-items:stretch; width:100%; }
.ops-collect-fields .ops-input { min-width:7.5rem; }
.ops-collect-details {
  margin:.15rem 0; padding:.35rem .45rem; border:1px solid #e6ebf2; border-radius:10px; background:#fff;
}
.ops-collect-details--compact {
  position:relative; margin:0; padding:0; border:0; background:transparent;
  display:inline-flex; align-items:center;
}
.ops-collect-details--compact > summary.ops-icon-btn { margin:0; }
.ops-collect-popover {
  position:absolute; left:0; top:calc(100% + .35rem); z-index:6;
  width:min(18rem, 70vw); margin:0; padding:.55rem;
  border:1px solid #e6ebf2; border-radius:12px; background:#fff;
  box-shadow:0 12px 28px rgba(16,42,86,.16);
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
.ops-icon-btn {
  display:inline-flex; align-items:center; justify-content:center; gap:.15rem;
  width:2.05rem; height:2.05rem; padding:0;
  border-radius:10px; border:1px solid transparent;
  vertical-align:middle; cursor:pointer; text-decoration:none;
  line-height:1; flex-shrink:0; position:relative;
}
.ops-icon-btn svg { width:16px; height:16px; display:block; }
.ops-icon-btn--pending {
  background: var(--doceo-yellow, #f5c518); color:#1f2937; border-color:#eab308;
}
.ops-icon-btn--done {
  background:#16a34a; color:#fff; border-color:#15803d;
}
.ops-icon-btn--error {
  box-shadow:0 0 0 2px rgba(220,38,38,.28);
}
.ops-icon-btn--disabled,
.ops-icon-btn:disabled {
  opacity:.45; cursor:not-allowed; filter:grayscale(.25);
}
details.ops-collect-details > summary.ops-icon-btn {
  display:inline-flex; list-style:none; position:relative;
}
details.ops-collect-details > summary.ops-icon-btn::-webkit-details-marker { display:none; }
.ops-icon-btn .ops-reschedule-count,
.ops-icon-btn .ops-status-badge {
  position:absolute; top:-.35rem; right:-.35rem;
}
.ops-inline-form .ops-icon-btn { position:relative; }
.ops-btn-ico {
  display:inline-flex; align-items:center; gap:.2rem; margin-right:.35rem; vertical-align:-2px;
}
.ops-status-badge {
  display:inline-flex; align-items:center; justify-content:center;
  width:1.05rem; height:1.05rem; border-radius:999px;
  border:1px solid transparent; line-height:1; pointer-events:none;
  box-shadow:0 1px 2px rgba(15,23,42,.18);
}
.ops-status-badge svg { width:10px; height:10px; display:block; }
.ops-status-badge--ok {
  background:#16a34a; color:#fff; border-color:#15803d;
}
.ops-status-badge--pending {
  background:#fef3c7; color:#92400e; border-color:#f59e0b;
}
.ops-status-badge--error {
  background:#dc2626; color:#fff; border-color:#b91c1c;
}
.ops-legend .ops-status-badge {
  width:1.15rem; height:1.15rem; vertical-align:middle;
}
.ops-legend .ops-status-badge svg { width:11px; height:11px; }
.ops-reschedule-count {
  display:inline-flex; align-items:center; justify-content:center;
  min-width:1.15rem; height:1.15rem; padding:0 .28rem;
  border-radius:999px; font-size:.72rem; font-weight:800; line-height:1;
  background:rgba(255,255,255,.95); color:#15803d;
  border:1px solid rgba(21,128,61,.35);
}
.btn-accent .ops-reschedule-count,
.ops-icon-btn--pending .ops-reschedule-count {
  background:#fff7cc; color:#92400e; border-color:rgba(146,64,14,.25);
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
  width:100%; height:min(52vh, 560px); border:0; background:#fff; display:block;
}
.ops-proof-frame[hidden],
.ops-proof-empty[hidden],
.ops-proof-confirm[hidden] { display:none !important; }
.ops-proof-empty {
  padding:1.25rem 1.1rem; color:#5b6b7c; font-size:.9rem; line-height:1.45;
}
.ops-proof-confirm {
  padding:.85rem 1rem; border-top:1px solid #e6ebf2; background:#fff;
  display:flex; flex-wrap:wrap; gap:.65rem; align-items:flex-end;
}
.ops-proof-confirm label {
  flex:1 1 220px; display:flex; flex-direction:column; gap:.3rem;
  font-size:.82rem; font-weight:600; color:#5b6b7c;
}
.ops-proof-confirm input[type="text"] {
  padding:.45rem .6rem; border:1px solid #cfd8e6; border-radius:8px; font:inherit;
}
.ops-reschedule-modal[hidden] { display:none !important; }
.ops-reschedule-modal {
  position:fixed; inset:0; z-index:85; display:flex; align-items:center; justify-content:center;
  padding:1rem;
}
.ops-reschedule-backdrop {
  position:absolute; inset:0; background:rgba(16,42,86,.55); border:0; padding:0; cursor:pointer;
}
.ops-reschedule-dialog {
  position:relative; z-index:1; width:min(420px, 96vw);
  background:#fff; border-radius:16px; box-shadow:0 24px 64px rgba(0,0,0,.28);
  display:flex; flex-direction:column; overflow:hidden;
}
.ops-reschedule-head {
  display:flex; align-items:center; justify-content:space-between; gap:.75rem;
  padding:.85rem 1rem; border-bottom:1px solid #e6ebf2; background:#f7fafc;
}
.ops-reschedule-head strong { color:var(--doceo-blue); font-size:.95rem; }
.ops-reschedule-form {
  padding:.95rem 1rem 1.05rem; display:flex; flex-direction:column; gap:.75rem;
}
.ops-reschedule-fields {
  display:grid; grid-template-columns:1fr 1fr; gap:.65rem;
}
.ops-reschedule-fields label {
  display:flex; flex-direction:column; gap:.3rem;
  font-size:.82rem; font-weight:600; color:#5b6b7c;
}
.ops-reschedule-fields input {
  width:100%; box-sizing:border-box; font:inherit; padding:.5rem .6rem;
  border:1px solid #cfd8e6; border-radius:8px;
}
.ops-reschedule-actions {
  display:flex; justify-content:flex-end; gap:.45rem; flex-wrap:wrap;
}
@media (max-width:480px) {
  .ops-reschedule-fields { grid-template-columns:1fr; }
}
.ops-provider-proof-modal[hidden] { display:none !important; }
.ops-provider-proof-modal {
  position:fixed; inset:0; z-index:90; display:flex; align-items:center; justify-content:center;
  padding:1rem;
}
.ops-provider-proof-backdrop {
  position:absolute; inset:0; background:rgba(16,42,86,.55); border:0; padding:0; cursor:pointer;
}
.ops-provider-proof-dialog {
  position:relative; z-index:1; width:min(460px, 96vw);
  background:#fff; border-radius:16px; box-shadow:0 24px 64px rgba(0,0,0,.28);
  display:flex; flex-direction:column; overflow:hidden;
}
.ops-provider-proof-head {
  display:flex; align-items:center; justify-content:space-between; gap:.75rem;
  padding:.85rem 1rem; border-bottom:1px solid #e6ebf2; background:#f7fafc;
}
.ops-provider-proof-head strong { color:var(--doceo-blue); font-size:.95rem; }
.ops-provider-proof-body {
  padding:.95rem 1rem 1.05rem; display:flex; flex-direction:column; gap:.75rem;
}
.ops-provider-proof-body p { margin:0; font-size:.88rem; color:#5b6b7c; line-height:1.45; }
.ops-provider-proof-body label {
  display:flex; flex-direction:column; gap:.35rem;
  font-size:.82rem; font-weight:600; color:#5b6b7c;
}
.ops-provider-proof-body input[type="file"] {
  font:inherit; padding:.45rem; border:1px solid #cfd8e6; border-radius:8px; background:#fff;
}
.ops-provider-proof-hint {
  font-size:.8rem; color:#15803d; background:#e8f7ee; border-radius:8px; padding:.45rem .65rem;
}
.ops-provider-proof-actions {
  display:flex; justify-content:flex-end; gap:.45rem; flex-wrap:wrap;
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
            <div class="ops-proof-empty" id="ops-proof-empty" hidden>
                No hay comprobante del alumno. Puedes confirmar el pago de todas formas.
            </div>
        </div>
        <form method="post" id="ops-proof-confirm" class="ops-proof-confirm" hidden>
            <input type="hidden" name="_csrf" id="ops-proof-csrf" value="">
            <input type="hidden" name="return_ops" value="1">
            <input type="hidden" name="return_view" id="ops-proof-return-view" value="">
            <input type="hidden" name="return_q" id="ops-proof-return-q" value="">
            <label>Nota (opcional)
                <input type="text" name="notes" id="ops-proof-notes" placeholder="Ej. Transferencia vista en banco">
            </label>
            <button type="submit" class="btn btn-accent" id="ops-proof-submit">Confirmar pago</button>
        </form>
    </div>
</div>

<div class="ops-reschedule-modal" id="ops-reschedule-modal" hidden>
    <button type="button" class="ops-reschedule-backdrop" id="ops-reschedule-backdrop" aria-label="Cerrar"></button>
    <div class="ops-reschedule-dialog" role="dialog" aria-modal="true" aria-labelledby="ops-reschedule-title">
        <div class="ops-reschedule-head">
            <strong id="ops-reschedule-title">Reagendar examen</strong>
            <button type="button" class="btn btn-primary btn-sm" id="ops-reschedule-close">Cerrar</button>
        </div>
        <form method="post" id="ops-reschedule-form" class="ops-reschedule-form">
            <input type="hidden" name="_csrf" id="ops-reschedule-csrf" value="">
            <input type="hidden" name="return_ops" value="1">
            <input type="hidden" name="return_view" id="ops-reschedule-return-view" value="">
            <input type="hidden" name="return_q" id="ops-reschedule-return-q" value="">
            <input type="hidden" name="step_code" id="ops-reschedule-step-code" value="">
            <input type="hidden" name="notify" id="ops-reschedule-notify" value="0">
            <div class="ops-reschedule-fields">
                <label>Fecha de examen
                    <input type="date" name="exam_date" id="ops-reschedule-date" required>
                </label>
                <label>Hora de examen
                    <input type="time" name="exam_time" id="ops-reschedule-time">
                </label>
            </div>
            <div class="ops-reschedule-actions">
                <button type="button" class="btn btn-ghost btn-sm" id="ops-reschedule-cancel">Cancelar</button>
                <button type="submit" class="btn btn-accent" id="ops-reschedule-submit">Guardar fecha</button>
            </div>
        </form>
    </div>
</div>

<div class="ops-provider-proof-modal" id="ops-provider-proof-modal" hidden>
    <button type="button" class="ops-provider-proof-backdrop" id="ops-provider-proof-backdrop" aria-label="Cerrar"></button>
    <div class="ops-provider-proof-dialog" role="dialog" aria-modal="true" aria-labelledby="ops-provider-proof-title">
        <div class="ops-provider-proof-head">
            <strong id="ops-provider-proof-title">Comprobante al proveedor</strong>
            <button type="button" class="btn btn-primary btn-sm" id="ops-provider-proof-close">Cerrar</button>
        </div>
        <form method="post" id="ops-provider-proof-form" class="ops-provider-proof-body" enctype="multipart/form-data">
            <div id="ops-provider-proof-fields"></div>
            <p>
                Antes de enviar el correo al proveedor puedes subir el comprobante de pago
                <strong>DOCEO → proveedor</strong>. El archivo <strong>no se adjunta</strong>:
                solo se usa el enlace <code>{{comprobante_url}}</code> en la plantilla.
                Si no aplica, omite y se envía igual.
            </p>
            <div class="ops-provider-proof-hint" id="ops-provider-proof-ready" hidden>
                Ya hay un comprobante admin cargado para este caso. Puedes reemplazarlo o omitir.
            </div>
            <label>Comprobante (PDF o imagen)
                <input type="file" name="provider_payment_proof" id="ops-provider-proof-file"
                       accept=".pdf,.jpg,.jpeg,.png,.webp">
            </label>
            <input type="hidden" name="skip_admin_proof" id="ops-provider-proof-skip" value="0">
            <input type="hidden" name="include_payment_proof" id="ops-provider-proof-include" value="1">
            <div class="ops-provider-proof-actions">
                <button type="button" class="btn btn-ghost btn-sm" id="ops-provider-proof-cancel">Cancelar</button>
                <button type="submit" class="btn btn-ghost btn-sm" id="ops-provider-proof-omit"
                        data-mode="omit">Omitir y enviar</button>
                <button type="submit" class="btn btn-accent btn-sm" id="ops-provider-proof-send"
                        data-mode="upload">Subir y enviar</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
  var proofModal = document.getElementById('ops-proof-modal');
  var proofFrame = document.getElementById('ops-proof-frame');
  var proofEmpty = document.getElementById('ops-proof-empty');
  var proofTitle = document.getElementById('ops-proof-title');
  var proofOpen = document.getElementById('ops-proof-open');
  var proofClose = document.getElementById('ops-proof-close');
  var proofBackdrop = document.getElementById('ops-proof-backdrop');
  var proofConfirm = document.getElementById('ops-proof-confirm');
  var proofCsrf = document.getElementById('ops-proof-csrf');
  var proofReturnView = document.getElementById('ops-proof-return-view');
  var proofReturnQ = document.getElementById('ops-proof-return-q');
  var proofNotes = document.getElementById('ops-proof-notes');

  function closeProof() {
    if (!proofModal) return;
    proofModal.hidden = true;
    if (proofFrame) {
      proofFrame.src = 'about:blank';
      proofFrame.hidden = false;
    }
    if (proofEmpty) proofEmpty.hidden = true;
    if (proofConfirm) proofConfirm.hidden = true;
    if (proofNotes) proofNotes.value = '';
  }
  function openProof(url, title, opts) {
    if (!proofModal) return;
    opts = opts || {};
    if (proofTitle) proofTitle.textContent = title || 'Comprobante';
    var hasUrl = !!(url && String(url).trim());
    if (proofOpen) {
      if (hasUrl) {
        proofOpen.href = url;
        proofOpen.hidden = false;
      } else {
        proofOpen.hidden = true;
      }
    }
    if (proofFrame && proofEmpty) {
      if (hasUrl) {
        proofFrame.hidden = false;
        proofEmpty.hidden = true;
        proofFrame.src = url;
      } else {
        proofFrame.hidden = true;
        proofFrame.src = 'about:blank';
        proofEmpty.hidden = false;
        if (opts.warnText) proofEmpty.textContent = opts.warnText;
        else proofEmpty.textContent = 'No hay comprobante del alumno. Puedes confirmar el pago de todas formas.';
      }
    }
    if (proofConfirm) {
      if (opts.confirmUrl) {
        proofConfirm.action = opts.confirmUrl;
        proofConfirm.hidden = false;
        if (proofCsrf) proofCsrf.value = opts.csrf || '';
        if (proofReturnView) proofReturnView.value = opts.returnView || '';
        if (proofReturnQ) proofReturnQ.value = opts.returnQ || '';
      } else {
        proofConfirm.hidden = true;
      }
    }
    proofModal.hidden = false;
  }
  document.querySelectorAll('.ops-proof-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openProof(btn.getAttribute('data-proof-url') || '', btn.getAttribute('data-proof-title') || 'Comprobante');
    });
  });
  document.querySelectorAll('.ops-confirm-pay-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var hasProof = btn.getAttribute('data-has-proof') === '1';
      openProof(
        hasProof ? (btn.getAttribute('data-proof-url') || '') : '',
        btn.getAttribute('data-title') || 'Confirmar pago',
        {
          confirmUrl: btn.getAttribute('data-confirm-url') || '',
          csrf: btn.getAttribute('data-csrf') || '',
          returnView: btn.getAttribute('data-return-view') || '',
          returnQ: btn.getAttribute('data-return-q') || '',
          warnText: 'No hay comprobante del alumno cargado. Confirma solo si verificaste el pago por otro medio.'
        }
      );
    });
  });
  proofClose && proofClose.addEventListener('click', closeProof);
  proofBackdrop && proofBackdrop.addEventListener('click', closeProof);

  var rescheduleModal = document.getElementById('ops-reschedule-modal');
  var rescheduleForm = document.getElementById('ops-reschedule-form');
  var rescheduleTitle = document.getElementById('ops-reschedule-title');
  var rescheduleClose = document.getElementById('ops-reschedule-close');
  var rescheduleCancel = document.getElementById('ops-reschedule-cancel');
  var rescheduleBackdrop = document.getElementById('ops-reschedule-backdrop');
  var rescheduleCsrf = document.getElementById('ops-reschedule-csrf');
  var rescheduleReturnView = document.getElementById('ops-reschedule-return-view');
  var rescheduleReturnQ = document.getElementById('ops-reschedule-return-q');
  var rescheduleStepCode = document.getElementById('ops-reschedule-step-code');
  var rescheduleNotify = document.getElementById('ops-reschedule-notify');
  var rescheduleDate = document.getElementById('ops-reschedule-date');
  var rescheduleTime = document.getElementById('ops-reschedule-time');
  var rescheduleSubmit = document.getElementById('ops-reschedule-submit');

  function closeReschedule() {
    if (!rescheduleModal) return;
    rescheduleModal.hidden = true;
  }
  function openReschedule(btn) {
    if (!rescheduleModal || !rescheduleForm || !btn) return;
    rescheduleForm.action = btn.getAttribute('data-action-url') || '';
    if (rescheduleCsrf) rescheduleCsrf.value = btn.getAttribute('data-csrf') || '';
    if (rescheduleReturnView) rescheduleReturnView.value = btn.getAttribute('data-return-view') || '';
    if (rescheduleReturnQ) rescheduleReturnQ.value = btn.getAttribute('data-return-q') || '';
    if (rescheduleStepCode) rescheduleStepCode.value = btn.getAttribute('data-step-code') || '';
    if (rescheduleNotify) rescheduleNotify.value = btn.getAttribute('data-notify') || '0';
    if (rescheduleDate) rescheduleDate.value = btn.getAttribute('data-exam-date') || '';
    if (rescheduleTime) rescheduleTime.value = btn.getAttribute('data-exam-time') || '';
    if (rescheduleTitle) rescheduleTitle.textContent = btn.getAttribute('data-title') || 'Reagendar examen';
    if (rescheduleSubmit) {
      rescheduleSubmit.textContent = btn.getAttribute('data-submit-label') || 'Guardar fecha';
    }
    rescheduleModal.hidden = false;
    if (rescheduleDate) {
      setTimeout(function () { rescheduleDate.focus(); }, 30);
    }
  }
  document.querySelectorAll('.ops-reschedule-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openReschedule(btn);
    });
  });
  rescheduleClose && rescheduleClose.addEventListener('click', closeReschedule);
  rescheduleCancel && rescheduleCancel.addEventListener('click', closeReschedule);
  rescheduleBackdrop && rescheduleBackdrop.addEventListener('click', closeReschedule);


  var providerProofModal = document.getElementById('ops-provider-proof-modal');
  var providerProofForm = document.getElementById('ops-provider-proof-form');
  var providerProofFields = document.getElementById('ops-provider-proof-fields');
  var providerProofFile = document.getElementById('ops-provider-proof-file');
  var providerProofSkip = document.getElementById('ops-provider-proof-skip');
  var providerProofInclude = document.getElementById('ops-provider-proof-include');
  var providerProofReady = document.getElementById('ops-provider-proof-ready');
  var providerProofClose = document.getElementById('ops-provider-proof-close');
  var providerProofCancel = document.getElementById('ops-provider-proof-cancel');
  var providerProofBackdrop = document.getElementById('ops-provider-proof-backdrop');
  var providerProofMode = 'upload';

  function closeProviderProof() {
    if (!providerProofModal) return;
    providerProofModal.hidden = true;
    if (providerProofFields) providerProofFields.innerHTML = '';
    if (providerProofFile) providerProofFile.value = '';
    if (providerProofSkip) providerProofSkip.value = '0';
  }

  function openProviderProof(sourceForm) {
    if (!providerProofModal || !providerProofForm || !sourceForm) return;
    providerProofForm.action = sourceForm.getAttribute('action') || '';
    if (providerProofFields) {
      providerProofFields.innerHTML = '';
      Array.prototype.slice.call(sourceForm.querySelectorAll('input[type="hidden"]')).forEach(function (input) {
        var name = input.getAttribute('name') || '';
        if (!name || name === 'skip_admin_proof') return;
        if (name === 'include_payment_proof') {
          if (providerProofInclude) providerProofInclude.value = input.value || '1';
          return;
        }
        var clone = input.cloneNode(true);
        providerProofFields.appendChild(clone);
      });
    }
    if (providerProofReady) {
      providerProofReady.hidden = sourceForm.getAttribute('data-has-admin-proof') !== '1';
    }
    if (providerProofSkip) providerProofSkip.value = '0';
    if (providerProofFile) providerProofFile.value = '';
    providerProofMode = 'upload';
    providerProofModal.hidden = false;
  }

  document.querySelectorAll('form.ops-provider-mail-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      openProviderProof(form);
    });
  });

  if (providerProofForm) {
    providerProofForm.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-mode]');
      if (!btn) return;
      providerProofMode = btn.getAttribute('data-mode') || 'upload';
    });
    providerProofForm.addEventListener('submit', function (e) {
      if (providerProofMode === 'omit') {
        if (providerProofSkip) providerProofSkip.value = '1';
        if (providerProofFile) providerProofFile.value = '';
        return;
      }
      // Subir y enviar: si no eligió archivo y ya hay comprobante, permitir; si no hay, pedir archivo o usar omitir.
      var hasFile = providerProofFile && providerProofFile.files && providerProofFile.files.length > 0;
      var already = providerProofReady && !providerProofReady.hidden;
      if (!hasFile && !already) {
        e.preventDefault();
        alert('Selecciona el comprobante, o pulsa «Omitir y enviar» si no se requiere.');
        return false;
      }
      if (providerProofSkip) providerProofSkip.value = already && !hasFile ? '0' : '0';
      if (providerProofInclude) providerProofInclude.value = '1';
    });
  }
  providerProofClose && providerProofClose.addEventListener('click', closeProviderProof);
  providerProofCancel && providerProofCancel.addEventListener('click', closeProviderProof);
  providerProofBackdrop && providerProofBackdrop.addEventListener('click', closeProviderProof);

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (providerProofModal && !providerProofModal.hidden) {
      closeProviderProof();
      return;
    }
    if (rescheduleModal && !rescheduleModal.hidden) {
      closeReschedule();
      return;
    }
    if (proofModal && !proofModal.hidden) closeProof();
  });

  // Sincronizar folio/clave/Zoom visibles → hidden del form de accesos (enviar plantilla).
  function syncAccessFields(tid) {
    var folioEl = document.getElementById('ops-folio-' + tid);
    var keyEl = document.getElementById('ops-key-' + tid);
    var zoomEl = document.getElementById('ops-zoom-' + tid);
    var form = document.getElementById('ops-access-form-' + tid);
    if (!form) return false;
    var hFolio = form.querySelector('.ops-access-folio-hidden');
    var hKey = form.querySelector('.ops-access-key-hidden');
    var hZoom = form.querySelector('.ops-access-zoom-hidden');
    if (hFolio) hFolio.value = folioEl ? folioEl.value.trim() : '';
    if (hKey) hKey.value = keyEl ? keyEl.value.trim() : '';
    if (hZoom) hZoom.value = zoomEl ? zoomEl.value.trim() : '';
    return true;
  }
  document.querySelectorAll('.ops-folio, .ops-key, .ops-zoom').forEach(function (el) {
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
        alert('Escribe folio y clave antes de enviar la plantilla de accesos.');
        var folioEl = document.getElementById('ops-folio-' + tid);
        if (folioEl) folioEl.focus();
        return false;
      }
    });
  });

  // Guardar todo (encabezado): inyectar todas las filas con folio/clave/Zoom visibles.
  var bulkForm = document.getElementById('ops-bulk-form');
  if (bulkForm) {
    bulkForm.addEventListener('submit', function (e) {
      Array.prototype.slice.call(bulkForm.querySelectorAll('.ops-bulk-injected')).forEach(function (el) {
        el.parentNode.removeChild(el);
      });
      var tids = {};
      document.querySelectorAll('.ops-folio, .ops-key, .ops-zoom').forEach(function (el) {
        var tid = el.getAttribute('data-tid') || '';
        if (tid) tids[tid] = true;
      });
      var injected = 0;
      var invalid = null;
      Object.keys(tids).forEach(function (tid) {
        syncAccessFields(tid);
        var folioEl = document.getElementById('ops-folio-' + tid);
        var keyEl = document.getElementById('ops-key-' + tid);
        var zoomEl = document.getElementById('ops-zoom-' + tid);
        var folio = folioEl ? folioEl.value.trim() : '';
        var key = keyEl ? keyEl.value.trim() : '';
        var zoom = zoomEl ? zoomEl.value.trim() : '';
        if (!folio && !key && !zoom) {
          return;
        }
        if ((folio && !key) || (!folio && key)) {
          invalid = tid;
          return;
        }
        function inject(name, value) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          input.value = value || '';
          input.className = 'ops-bulk-injected';
          bulkForm.appendChild(input);
        }
        inject('folio[' + tid + ']', folio);
        inject('access_key[' + tid + ']', key);
        inject('zoom_url[' + tid + ']', zoom);
        injected++;
      });
      if (invalid) {
        e.preventDefault();
        alert('Indica folio y clave juntos en la fila correspondiente.');
        var bad = document.getElementById('ops-folio-' + invalid);
        if (bad) bad.focus();
        return false;
      }
      if (injected < 1) {
        e.preventDefault();
        alert('No hay folio, clave o dato extra para guardar en las filas visibles.');
        return false;
      }
    });
  }
})();
</script>
