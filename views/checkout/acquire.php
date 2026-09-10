<?php
/** @var array<string,mixed> $product */
/** @var list<array{code:string,label:string,required:bool,type:string}> $fields */
/** @var list<array{code:string,label:string,required:bool,accept:string}> $docs */
/** @var array{template_url:string,doc_code:string,required_before_checkout:bool}|null $reglamento */
/** @var array<string,string> $prefill */
/** @var array<string,mixed> $quote */
/** @var bool $openpayReady */
/** @var array{bank:string,clabe:string,holder:string,concept:string} $bank */
/** @var string $depositCard */
/** @var bool $needsExam */
/** @var ?string $examMinDate */
/** @var int $examAdvanceDays */

$catalogPrice = (float) ($quote['catalog'] ?? $product['catalog_price'] ?? 0);
$basePrice = (float) ($quote['base'] ?? $catalogPrice);
$comboOffers = $comboOffers ?? ['combos' => [], 'addons' => []];
$comboAddons = []; // Ya no se ofrece “a la carta”: solo combos armados.
$comboList = $comboOffers['combos'] ?? [];
$hasComboOffers = $comboList !== [];
$maxComboSavings = 0.0;
foreach ($comboList as $c) {
    $list = (float) ($c['list_price'] ?? $c['catalog_price'] ?? 0);
    if ($list <= 0) {
        $list = (float) ($c['public_price'] ?? 0);
    }
    $packagePrice = !empty($c['partner_price'])
        ? (float) $c['partner_price']
        : $list;
    if (!empty($c['solo_sum']) && (float) $c['solo_sum'] > $packagePrice) {
        $maxComboSavings = max($maxComboSavings, (float) $c['solo_sum'] - $packagePrice);
    }
}

/** @var bool $isPartnerCheckout */
/** @var array<string,mixed>|null $partner */
$isPartnerCheckout = !empty($isPartnerCheckout);
$partner = $partner ?? null;
$wizardSteps = ['datos'];
if (!empty($reglamento)) {
    $wizardSteps[] = 'reglamento';
}
if ($needsExam) {
    $wizardSteps[] = 'agenda';
}
if ($hasComboOffers) {
    $wizardSteps[] = 'paquete';
}
$wizardSteps[] = 'pago';
$wizardSteps[] = 'confirmar';

$stepLabels = [
    'datos' => 'Datos',
    'reglamento' => 'Reglamento',
    'agenda' => 'Agenda',
    'paquete' => 'Paquete',
    'pago' => 'Pago',
    'confirmar' => 'Confirmar',
];
?>
<div class="checkout-page">
    <p class="meta" style="margin-bottom:.5rem"><a href="<?= e(url('/producto/' . $product['slug'])) ?>">← <?= e($product['name']) ?></a></p>
    <h1 style="margin:.2rem 0 .75rem;color:var(--doceo-blue)">Adquirir <?= e($product['name']) ?></h1>

    <div class="checkout-layout">
        <div class="checkout-main">
            <nav class="checkout-timeline" aria-label="Pasos del registro">
                <?php foreach ($wizardSteps as $i => $code): ?>
                    <button type="button" class="timeline-step<?= $i === 0 ? ' active' : '' ?>" data-goto="<?= e($code) ?>" disabled>
                        <span class="timeline-num"><?= $i + 1 ?></span>
                        <span class="timeline-label"><?= e($stepLabels[$code] ?? $code) ?></span>
                    </button>
                    <?php if ($i < count($wizardSteps) - 1): ?>
                        <span class="timeline-line" aria-hidden="true"></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            
<?php if ($isPartnerCheckout): ?>
<div class="flash flash-info" style="margin:0 0 1rem">
    <strong>Registro de alumno (partner<?= $partner ? ' · ' . e(\App\Services\PartnerAdminService::tierLabel($partner['tier'] ?? null)) : '' ?>)</strong><br>
    Completa el mismo proceso que un alumno: datos requeridos, reglamento, agenda, paquetes/combos y pago.
    Puedes firmar el reglamento en pantalla (pasa el iPad/mouse al alumno) o descargarlo, firmarlo en papel y subir el PDF escaneado.
    Al terminar seguirás en tu portal partner con el caso creado.
</div>
<?php endif; ?>
<form method="post" action="<?= e(url('/adquirir/' . $product['slug'])) ?>" enctype="multipart/form-data" class="checkout-form panel" id="checkout-form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="payment_method" id="payment_method" value="transfer_proof">
                <input type="hidden" name="card_msi_months" id="card_msi_months" value="1">
                <?php if ($needsExam): ?>
                    <input type="hidden" name="exam_date" id="exam_date" value="">
                    <input type="hidden" name="exam_time" id="exam_time" value="">
                <?php endif; ?>

                
                <input type="hidden" name="combo_id" id="combo_id" value="">

                <div class="wizard-step active" data-step="datos">
                    <h2 class="step-title"><?= $isPartnerCheckout ? 'Datos del alumno' : 'Tus datos' ?></h2>
                    <div class="callout callout-info">
                        Registra tu información <strong>tal cual debe aparecer en tu certificado</strong>
                        (nombres y apellidos sin abreviar, sin errores ortográficos).
                    </div>
                    <div class="form-grid">
                        <?php foreach ($fields as $field): ?>
                            <?php
                            $code = $field['code'];
                            $val = $prefill[$code] ?? '';
                            $req = !empty($field['required']);
                            ?>
                            <?php if (($field['type'] ?? '') === 'select'): ?>
                                <?php
                                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                                if ($options === [] && $code === 'sex') {
                                    $options = \App\Services\CheckoutRequirements::SEX_OPTIONS;
                                }
                                $selected = (string) $val;
                                if ($code === 'sex') {
                                    $selected = \App\Services\CheckoutRequirements::normalizeSexValue($selected);
                                }
                                ?>
                                <label><?= e($field['label']) ?><?= $req ? ' *' : '' ?>
                                    <select name="<?= e($code) ?>" <?= $req ? 'required' : '' ?>>
                                        <option value="">— Selecciona —</option>
                                        <?php foreach ($options as $opt): ?>
                                            <?php
                                            $optVal = (string) ($opt['value'] ?? '');
                                            $optLabel = (string) ($opt['label'] ?? $optVal);
                                            ?>
                                            <option value="<?= e($optVal) ?>" <?= $selected === $optVal ? 'selected' : '' ?>>
                                                <?= e($optLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($code === 'sex'): ?>
                                        <span class="muted" style="display:block;font-size:.75rem;font-weight:500;margin-top:.25rem">
                                            Se registra como M o F para el proveedor.
                                        </span>
                                    <?php endif; ?>
                                </label>
                            <?php else: ?>
                                <label><?= e($field['label']) ?><?= $req ? ' *' : '' ?>
                                    <input type="<?= e($field['type']) ?>" name="<?= e($code) ?>" value="<?= e((string) $val) ?>"
                                        <?= $req ? 'required' : '' ?>
                                        <?= $code === 'email' ? 'autocomplete="email"' : '' ?>
                                        <?= $code === 'phone' ? 'autocomplete="tel"' : '' ?>>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($reglamento)): ?>
                    <div class="wizard-step" data-step="reglamento" hidden>
                        <h2 class="step-title">Reglamento del examen</h2>
                        <?php require BASE_PATH . '/views/checkout/_reglamento_signature.php'; ?>
                    </div>
                <?php endif; ?>

                <?php if ($needsExam): ?>
                    <?php
                    $examRules = \App\Services\ExamScheduleService::scheduleRules($product ?? []);
                    $examMode = (string) ($examRules['mode'] ?? 'window');
                    $examHelp = trim((string) ($examRules['checkout_help'] ?? ''));
                    if ($examHelp === '') {
                        $examHelp = match ($examMode) {
                            'fixed_slots' => 'Elige un horario regular (p. ej. sábado 11:00 o 13:00). Si necesitas otra fecha, solicita extraordinaria (con costo extra y autorización).',
                            'dated_list' => 'Elige una convocatoria abierta del proveedor. La inscripción cierra en la fecha límite indicada.',
                            default => 'Elige fecha y hora disponible según el calendario del examen.',
                        };
                    }
                    $extraCfg = is_array($examRules['extraordinary'] ?? null) ? $examRules['extraordinary'] : [];
                    ?>
                    <div class="wizard-step" data-step="agenda" hidden>
                        <h2 class="step-title">Agenda tu examen</h2>
                        <p class="muted" style="font-size:.88rem;margin-top:0"><?= e($examHelp) ?></p>
                        <input type="hidden" name="exam_kind" id="exam_kind" value="regular">
                        <input type="hidden" name="exam_session_id" id="exam_session_id" value="">
                        <input type="hidden" name="exam_allow_short_advance" id="exam_allow_short_advance" value="0">

                        <?php if ($examMode === 'dated_list'): ?>
                            <div class="form-grid" style="max-width:560px">
                                <label>Convocatoria *
                                    <select id="exam_session_select">
                                        <option value="">— elige convocatoria —</option>
                                    </select>
                                </label>
                            </div>
                        <?php else: ?>
                            <div class="form-grid" style="max-width:480px">
                                <label>Fecha del examen *
                                    <input type="date" id="exam_date_select" min="<?= e($examMinDate ?? '') ?>">
                                </label>
                                <label>Hora *
                                    <select id="exam_time_select" disabled>
                                        <option value="">— elige hora —</option>
                                    </select>
                                </label>
                            </div>
                            <?php if ($examMode === 'fixed_slots' && !empty($extraCfg['enabled'])): ?>
                                <div style="margin-top:.85rem;padding:.75rem;border:1px dashed #c5d0e0;border-radius:12px;max-width:560px">
                                    <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.9rem;font-weight:600">
                                        <input type="checkbox" id="exam_extraordinary_toggle" value="1" style="margin-top:.2rem">
                                        <span>
                                            Solicitar fecha extraordinaria
                                            <span class="muted" style="display:block;font-weight:500;font-size:.8rem;margin-top:.15rem">
                                                Costo extra: <?= e(money($extraCfg['surcharge_amount'] ?? 0)) ?>
                                                · <?= e((string) ($extraCfg['surcharge_label'] ?? 'Fecha extraordinaria')) ?>
                                                <?php if (!empty($extraCfg['requires_admin_approval'])): ?>
                                                    · requiere autorización del admin
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </label>
                                    <div id="exam-extraordinary-fields" hidden style="margin-top:.65rem;display:grid;gap:.55rem">
                                        <label class="muted" style="font-size:.85rem;font-weight:600">
                                            Fecha extraordinaria *
                                            <input type="date" id="exam_extraordinary_date" min="<?= e(date('Y-m-d')) ?>"
                                                   style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                                        </label>
                                        <label class="muted" style="font-size:.85rem;font-weight:600">
                                            Hora preferida *
                                            <input type="time" id="exam_extraordinary_time" value="11:00"
                                                   style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <p class="muted" id="exam-slot-hint" style="font-size:.82rem;margin-top:.5rem">
                            <?php
                            $examAdvanceDays = (int) ($examAdvanceDays ?? 2);
                            if ($examMode === 'dated_list') {
                                echo 'Solo se muestran convocatorias con inscripción abierta.';
                            } elseif ($examAdvanceDays <= 0) {
                                echo 'Puedes agendar desde hoy (sin días de antelación).';
                            } elseif ($examAdvanceDays === 1) {
                                echo 'Agendar con 1 día de antelación';
                            } else {
                                echo 'Agendar con ' . $examAdvanceDays . ' días de antelación';
                            }
                            ?>
                        </p>
                        <p id="exam-date-warn" class="exam-date-warn" hidden role="alert"></p>
                        <p id="exam-surcharge-note" class="muted" style="font-size:.82rem;color:#9a3412" hidden></p>
                    </div>
                <?php endif; ?>

                <?php if ($hasComboOffers): ?>
                    <div class="wizard-step" data-step="paquete" hidden>
                        <?php $comboStepIntro = true; require __DIR__ . '/_combo_offer.php'; ?>
                    </div>
                <?php endif; ?>

                <div class="wizard-step" data-step="pago" hidden>
                    <h2 class="step-title">Forma de pago</h2>
                    <p class="muted" style="margin-top:0;font-size:.88rem">Elige cómo realizarás tu pago.</p>

                    <div class="pay-tiles" role="group" aria-label="Método de pago">
                        <button type="button" class="pay-tile active" data-method="transfer_proof" data-ui="transfer" aria-pressed="true">
                            <span class="pay-tile-icon" aria-hidden="true">🏦</span>
                            <span class="pay-tile-label">Transferencia</span>
                            <span class="pay-tile-sub">SPEI · comprobante</span>
                        </button>
                        <button type="button" class="pay-tile" data-method="openpay_store" data-ui="oxxo" aria-pressed="false">
                            <span class="pay-tile-icon" aria-hidden="true">🏪</span>
                            <span class="pay-tile-label">OXXO</span>
                            <span class="pay-tile-sub">Depósito en tienda</span>
                        </button>
                        <?php if ($openpayReady): ?>
                            <button type="button" class="pay-tile" data-method="openpay_card" data-ui="msi" aria-pressed="false">
                                <span class="pay-tile-icon" aria-hidden="true">💳</span>
                                <span class="pay-tile-label">TDC</span>
                                <span class="pay-tile-sub">Link seguro OpenPay BBVA</span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <div id="pay-transfer-panel" class="pay-panel">
                        <p class="muted" style="font-size:.88rem;margin:.75rem 0 .5rem">
                            Transfiere el monto indicado en el panel derecho y sube tu comprobante.
                        </p>
                        <?php if (!empty($bank['clabe'])): ?>
                            <div class="pay-data-card pay-data-card--transfer" role="group" aria-label="Datos de transferencia">
                                <p class="pay-data-card-title">Datos para transferir</p>
                                <dl class="pay-data-list">
                                    <div class="pay-data-row">
                                        <dt>Banco</dt>
                                        <dd><strong><?= e($bank['bank']) ?></strong></dd>
                                    </div>
                                    <div class="pay-data-row pay-data-row--highlight">
                                        <dt>CLABE</dt>
                                        <dd class="copy-line">
                                            <strong class="mono pay-data-value"><?= e($bank['clabe']) ?></strong>
                                            <button type="button" class="btn btn-primary btn-sm copy-btn" data-copy="<?= e($bank['clabe']) ?>">Copiar</button>
                                        </dd>
                                    </div>
                                    <div class="pay-data-row">
                                        <dt>Titular</dt>
                                        <dd><strong><?= e($bank['holder']) ?></strong></dd>
                                    </div>
                                </dl>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div id="pay-oxxo-panel" class="pay-panel" hidden>
                        <p class="muted" style="font-size:.88rem;margin:.75rem 0 .5rem">
                            Realiza un depósito en OXXO (o tienda afiliada) a esta tarjeta:
                        </p>
                        <div class="pay-data-card pay-data-card--oxxo" role="group" aria-label="Datos de depósito OXXO">
                            <p class="pay-data-card-title">Tarjeta de depósito</p>
                            <span class="muted" style="font-size:.82rem">Número de tarjeta</span>
                            <div class="copy-line" style="margin-top:.35rem">
                                <span class="mono pay-data-value deposit-card-num"><?= e($depositCard) ?></span>
                                <button type="button" class="btn btn-primary btn-sm copy-btn" data-copy="<?= e($depositCard) ?>">Copiar</button>
                            </div>
                            <p class="muted" style="font-size:.82rem;margin:.65rem 0 0">
                                Deposita el monto exacto del panel derecho y sube el comprobante.
                            </p>
                        </div>
                    </div>

                    <div id="payment-proof-panel" class="pay-panel payment-proof-panel">
                        <div class="file-picker" id="proof-picker">
                            <input type="file" name="payment_proof" id="payment_proof" accept=".pdf,.jpg,.jpeg,.png">
                            <div class="file-picker-body">
                                <span class="file-picker-icon" aria-hidden="true">📄</span>
                                <div>
                                    <strong>Comprobante de pago</strong>
                                    <span class="muted file-picker-hint">PDF o imagen · obligatorio</span>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm file-picker-btn">Seleccionar archivo</button>
                            </div>
                            <p class="file-picker-name muted" id="proof-filename">Ningún archivo seleccionado</p>
                        </div>
                    </div>

                    <div id="pay-msi-panel" class="pay-panel" hidden>
                        <p class="muted" style="font-size:.82rem;margin:.75rem 0 .4rem">TDC</p>
                        <div class="msi-chips" id="msi-chips"></div>
                        <p class="msi-monthly-line" id="msi-monthly-line"></p>
                        <p class="muted" style="font-size:.82rem;margin:.35rem 0 0">
                            Te enviaremos por correo el link de pago para proceder de manera segura desde el portal OpenPay de BBVA.
                        </p>
                    </div>
                    <p class="muted" id="pay-hint" style="font-size:.82rem;margin-top:.65rem"></p>
                </div>

                <div class="wizard-step" data-step="confirmar" hidden>
                    <h2 class="step-title">Confirma tu registro</h2>
                    <p class="muted" style="margin-top:0;font-size:.88rem">Revisa que todo esté correcto antes de enviar.</p>
                    <div class="confirm-summary" id="confirm-summary"></div>
                </div>

                <?php if ($docs !== []): ?>
                    <div class="muted" style="font-size:.82rem;margin-top:1rem;padding-top:1rem;border-top:1px solid #e6ebf2">
                        <?php foreach ($docs as $doc): ?>
                            <label style="display:block;margin-bottom:.5rem">
                                <?= e($doc['label']) ?><?= $doc['required'] ? ' *' : '' ?>
                                <input type="file" name="doc_<?= e($doc['code']) ?>" accept="<?= e($doc['accept']) ?>" <?= $doc['required'] ? 'required' : '' ?>>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="wizard-nav">
                    <button type="button" class="btn btn-ghost" id="wizard-prev" hidden>Anterior</button>
                    <button type="button" class="btn btn-primary" id="wizard-next">Siguiente</button>
                    <button class="btn btn-accent" type="submit" id="checkout-submit" hidden>Confirmar registro</button>
                    <a class="btn btn-ghost" href="<?= e(url('/producto/' . $product['slug'])) ?>">Cancelar</a>
                </div>
            </form>
        </div>

        <aside class="checkout-sidebar panel" aria-label="Resumen de compra">
            <h2 class="sidebar-title"><?= e($product['name']) ?></h2>
            <?php if ($hasComboOffers): ?>
            <div id="combo-sidebar-teaser" class="combo-sidebar-teaser">
                <strong>Paquete con descuento</strong>
                <p class="muted" style="margin:.35rem 0 0;font-size:.8rem;line-height:1.4">
                    <?php if ($maxComboSavings > 0): ?>
                        En el paso <em>Paquete</em> podrás agregar curso o trámite y ahorrar hasta <?= money($maxComboSavings) ?>.
                    <?php else: ?>
                        En el paso <em>Paquete</em> podrás agregar curso o trámite antes de pagar.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            <?php if (!$isPartnerCheckout): ?>
            <div class="sidebar-promo">
                <label for="promo_code">Código promocional</label>
                <div class="sidebar-promo-row">
                    <input type="text" name="promo_code" id="promo_code" placeholder="CÓDIGO PROMOCIONAL" form="checkout-form" style="text-transform:uppercase" autocomplete="off">
                    <button type="button" class="btn btn-primary btn-sm" id="apply-promo">Aplicar</button>
                </div>
                <?php
                $whatsappUrl = \App\Support\Settings::schoolWhatsappPromoUrl((string) ($product['name'] ?? ''));
                if ($whatsappUrl !== null):
                    ?>
                    <p class="combo-advisor-once" style="margin:.55rem 0 0">
                        <a class="combo-advisor-link" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer">
                            Contacta a un asesor por WhatsApp para ver si existe algún código promocional vigente
                        </a>
                    </p>
                <?php endif; ?>
                <p class="muted" style="font-size:.78rem;margin:.35rem 0 0">
                    Un código partner baja el precio al público; la diferencia se abona como crédito al partner.
                </p>
                <p class="muted" id="quote-error" style="color:#b00020;display:none;margin:.4rem 0 0;font-size:.82rem"></p>
            </div>
            <?php else: ?>
                <p class="muted" id="quote-error" style="color:#b00020;display:none;margin:.4rem 0 0;font-size:.82rem"></p>
            <?php endif; ?>
            <div class="sidebar-price">
                <div class="muted" style="font-size:.82rem" id="price-label"><?= e($quote['label'] ?? 'Precio de lista') ?></div>
                <div id="combo-breakdown" class="combo-breakdown" hidden></div>
                <div class="sidebar-price-row">
                    <?php
                    $initialCharged = (float) ($quote['charged'] ?? $quote['base'] ?? $catalogPrice);
                    $showPartnerStrike = $isPartnerCheckout && abs($initialCharged - $catalogPrice) > 0.009;
                    ?>
                    <span class="price<?= $showPartnerStrike ? ' price-strike' : '' ?>" id="price-list"><?= money($catalogPrice) ?></span>
                    <span id="price-arrow" style="<?= $showPartnerStrike ? '' : 'display:none;' ?>color:var(--doceo-muted)">→</span>
                    <span class="price" id="price-final" style="<?= $showPartnerStrike ? '' : 'display:none' ?>"><?= $showPartnerStrike ? money($initialCharged) : '' ?></span>
                </div>
                <p class="muted" style="font-size:.78rem;margin:.5rem 0 0" id="sidebar-pay-note">Total a pagar</p>
                <p id="combo-savings-note" class="combo-savings-note" hidden></p>
            </div>
        </aside>
    </div>
</div>

<style>
.checkout-page { margin:1.25rem 0 2.5rem; }
.checkout-layout {
  display:grid; grid-template-columns:1fr min(300px, 32%); gap:1.25rem; align-items:start;
}
.checkout-main { min-width:0; }
.checkout-sidebar {
  position:sticky; top:5.5rem; padding:1.1rem 1.15rem;
  border:2px solid rgba(49,82,133,.12);
}
.sidebar-title { margin:0 0 1rem; font-size:1rem; color:var(--doceo-blue); }
.sidebar-promo label { font-size:.82rem; font-weight:600; color:var(--doceo-muted); display:block; margin-bottom:.35rem; }
.sidebar-promo-row { display:flex; gap:.45rem; }
.sidebar-promo-row input {
  flex:1; font:inherit; padding:.5rem .65rem; border:1px solid #cfd8e6; border-radius:10px; min-width:0;
}
.sidebar-price { margin-top:1.1rem; padding-top:1rem; border-top:1px solid #e6ebf2; }
.sidebar-price-row { display:flex; align-items:baseline; gap:.5rem; flex-wrap:wrap; margin-top:.25rem; }
.combo-breakdown { margin:.65rem 0 .85rem; font-size:.8rem; }
.combo-breakdown-table { width:100%; border-collapse:collapse; }
.combo-breakdown-table th,
.combo-breakdown-table td { padding:.3rem 0; vertical-align:top; }
.combo-breakdown-table th { text-align:left; font-weight:600; color:var(--doceo-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.03em; }
.combo-breakdown-table .num { text-align:right; white-space:nowrap; }
.combo-breakdown-table .solo { text-decoration:line-through; color:var(--doceo-muted); }
.combo-breakdown-table .share { font-weight:700; color:var(--doceo-blue); }
.combo-breakdown-table .disc { color:#176b3a; font-weight:600; }
.combo-savings-note {
  margin:.55rem 0 0; padding:.45rem .6rem; border-radius:10px; background:#eaf8ef;
  color:#176b3a; font-size:.8rem; font-weight:700;
}
.combo-sidebar-teaser {
  margin:0 0 1rem; padding:.75rem .85rem; border-radius:12px;
  background:linear-gradient(180deg, #f0f7ff 0%, #f8fbff 100%);
  border:1px solid #c5d8ef; font-size:.85rem;
}
.combo-sidebar-teaser strong { color:var(--doceo-blue); display:block; font-size:.88rem; }
.combo-upsell--step { margin-top:0; }
.combo-step-intro { margin-bottom:1rem; }
.combo-step-badge {
  display:inline-block; font-size:.68rem; font-weight:700; letter-spacing:.04em;
  text-transform:uppercase; color:#5a7aa8; background:#eef4fc; padding:.2rem .5rem; border-radius:999px;
}
.combo-tiles { display:flex; gap:.65rem; flex-wrap:wrap; margin-bottom:.85rem; }
.checkout-form .combo-tile {
  flex:1 1 120px; min-width:118px; max-width:160px; min-height:92px;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:.15rem; padding:.75rem .55rem; margin:0;
  border:2px solid #d5deea; border-radius:14px; background:#fbfcfe; cursor:pointer;
  font:inherit; font-weight:600; color:var(--doceo-muted);
  text-align:center; transition:border-color .15s, background .15s;
  position:relative;
}
.checkout-form .combo-tile:hover { border-color:#9cb4d8; }
.checkout-form .combo-tile:has(input:checked) {
  border-color:var(--doceo-blue); background:#eef4fc;
}
.checkout-form .combo-tile input {
  position:absolute; opacity:0; width:1px; height:1px; margin:0; pointer-events:none;
}
.checkout-form .combo-tile-label {
  display:block; font-weight:700; font-size:.82rem; line-height:1.25;
  color:var(--doceo-blue); text-align:center;
}
.combo-advisor-once { margin:0 0 .25rem; font-size:.8rem; }
.combo-advisor-link {
  font-weight:600; color:#176b3a; text-decoration:underline; text-underline-offset:2px;
}
.combo-advisor-link:hover { color:#0f4f2a; }
.sidebar-price .price { font-size:1.65rem; color:var(--doceo-blue); font-weight:800; }
.price-strike { text-decoration:line-through; opacity:.55; font-size:1.2rem !important; }

.checkout-timeline {
  display:flex; align-items:center; gap:0; margin-bottom:1rem; flex-wrap:wrap;
  padding:.5rem; background:#fff; border-radius:14px; border:1px solid #e6ebf2;
}
.timeline-step {
  display:flex; align-items:center; gap:.4rem; padding:.4rem .55rem;
  border:0; background:transparent; cursor:default; font:inherit; color:var(--doceo-muted);
  border-radius:999px; opacity:.55;
}
.timeline-step.active { color:var(--doceo-blue); font-weight:700; opacity:1; background:#eef4fc; }
.timeline-step.done { opacity:.85; color:var(--doceo-blue); }
.timeline-num {
  width:1.5rem; height:1.5rem; border-radius:50%; background:#e6ebf2;
  display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700;
}
.timeline-step.active .timeline-num { background:var(--doceo-blue); color:#fff; }
.timeline-step.done .timeline-num { background:var(--doceo-yellow); color:#2a2a00; }
.timeline-label { font-size:.78rem; }
.timeline-line { width:12px; height:2px; background:#d5deea; flex-shrink:0; }

.step-title { margin:0 0 .85rem; font-size:1.1rem; color:var(--doceo-blue); }
.callout-info {
  padding:.75rem 1rem; border-radius:12px; background:#eef4fc; border:1px solid #c5d8ef;
  font-size:.88rem; margin-bottom:1rem; color:var(--doceo-text);
}
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:.75rem 1rem; }
.form-grid label, .checkout-form label { display:flex; flex-direction:column; gap:.35rem; font-size:.88rem; font-weight:600; color:var(--doceo-muted); }
.form-grid input, .form-grid select { font:inherit; padding:.55rem .7rem; border:1px solid #cfd8e6; border-radius:10px; background:#fff; }

.pay-tiles { display:flex; gap:.65rem; flex-wrap:wrap; }
.pay-tile {
  flex:1; min-width:100px; max-width:140px; display:flex; flex-direction:column; align-items:center; gap:.2rem;
  padding:.75rem .5rem; border:2px solid #d5deea; border-radius:14px; background:#fbfcfe; cursor:pointer;
  font:inherit; color:var(--doceo-text); transition:border-color .15s, background .15s;
}
.pay-tile:hover { border-color:#9cb4d8; }
.pay-tile.active { border-color:var(--doceo-blue); background:#eef4fc; }
.pay-tile-icon { font-size:1.45rem; line-height:1; }
.pay-tile-label { font-weight:700; font-size:.95rem; color:var(--doceo-blue); }
.pay-tile-sub { font-size:.68rem; color:var(--doceo-muted); font-weight:500; text-align:center; line-height:1.2; }

.copy-line { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
.copy-btn { padding:.25rem .65rem; font-size:.75rem; }
.mono { font-family:ui-monospace,monospace; letter-spacing:.03em; }
.pay-data-card {
  margin:.55rem 0 .85rem; padding:1rem 1.15rem; border-radius:14px;
  background:linear-gradient(180deg, #eef5ff 0%, #f7faff 100%);
  border:2px solid #9db8de; box-shadow:0 1px 0 rgba(20,60,120,.04);
}
.pay-data-card--oxxo { border-color:#f0b45a; background:linear-gradient(180deg, #fff7eb 0%, #fffbf4 100%); }
.pay-data-card-title {
  margin:0 0 .65rem; font-size:.78rem; font-weight:700; letter-spacing:.04em;
  text-transform:uppercase; color:var(--doceo-blue);
}
.pay-data-card--oxxo .pay-data-card-title { color:#9a5b00; }
.pay-data-list { margin:0; display:grid; gap:.55rem; }
.pay-data-row { display:grid; grid-template-columns:6.5rem 1fr; gap:.5rem; align-items:center; }
.pay-data-row dt { margin:0; font-size:.78rem; color:var(--doceo-muted); font-weight:600; }
.pay-data-row dd { margin:0; font-size:.95rem; }
.pay-data-row--highlight {
  padding:.55rem .65rem; margin:0 -.15rem; border-radius:10px;
  background:#fff; border:1px dashed #9db8de;
}
.pay-data-value { font-size:1.2rem; font-weight:800; color:var(--doceo-blue); letter-spacing:.04em; word-break:break-all; }
.deposit-card-num { font-size:1.25rem; font-weight:800; color:var(--doceo-blue); }

.file-picker {
  border:2px dashed #c5d4e8; border-radius:14px; padding:1rem; background:#fbfcfe;
  margin-top:.5rem; max-width:520px;
}
.file-picker input[type=file] { position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; }
.file-picker-body { display:flex; align-items:center; gap:.85rem; flex-wrap:wrap; }
.file-picker-icon { font-size:1.75rem; }
.file-picker-hint { display:block; font-size:.78rem; }
.file-picker-name { font-size:.82rem; margin:.65rem 0 0; }

.msi-chips { display:flex; gap:.45rem; flex-wrap:wrap; }
.msi-chip {
  padding:.45rem .9rem; border:1px solid #cfd8e6; border-radius:999px; background:#fff;
  font:inherit; font-size:.85rem; font-weight:600; cursor:pointer; color:var(--doceo-muted);
  display:flex; flex-direction:column; align-items:center; gap:.1rem;
}
.msi-chip small { font-size:.68rem; font-weight:600; }
.msi-chip.active { border-color:var(--doceo-blue); color:var(--doceo-blue); background:#eef4fc; }
.msi-monthly-line { margin:.85rem 0 0; font-size:1.1rem; color:var(--doceo-blue); font-weight:700; }

.confirm-summary {
  background:#f4f7fb; border-radius:12px; padding:1rem 1.15rem; font-size:.9rem;
}
.confirm-summary dl { margin:0; }
.confirm-summary dt { font-weight:600; color:var(--doceo-muted); font-size:.78rem; margin-top:.65rem; }
.confirm-summary dt:first-child { margin-top:0; }
.confirm-summary dd { margin:.15rem 0 0; color:var(--doceo-text); }

.wizard-nav {
  display:flex; gap:.65rem; flex-wrap:wrap; margin-top:1.35rem; padding-top:1rem; border-top:1px solid #e6ebf2;
}
.wizard-nav button[hidden] { display: none !important; }

@media (max-width: 860px) {
  .checkout-layout { grid-template-columns:1fr; }
  .checkout-sidebar { position:static; order:-1; }
  .timeline-label { display:none; }
}
</style>

<script>
(function () {
  const slug = <?= json_encode($product['slug'], JSON_UNESCAPED_UNICODE) ?>;
  const openpayReady = <?= $openpayReady ? 'true' : 'false' ?>;
  const needsExam = <?= $needsExam ? 'true' : 'false' ?>;
  const wizardSteps = <?= json_encode($wizardSteps, JSON_UNESCAPED_UNICODE) ?>;
  const comboStepIndex = wizardSteps.indexOf('paquete');
  const comboSidebarTeaser = document.getElementById('combo-sidebar-teaser');
  const depositCard = <?= json_encode($depositCard, JSON_UNESCAPED_UNICODE) ?>;
  const isPartnerCheckout = <?= $isPartnerCheckout ? 'true' : 'false' ?>;

  let quoteData = <?= json_encode($quote, JSON_UNESCAPED_UNICODE) ?>;
  let payUi = 'transfer';
  let stepIndex = 0;

  const form = document.getElementById('checkout-form');
  const codeInput = document.getElementById('promo_code');
  const applyBtn = document.getElementById('apply-promo');
  const priceList = document.getElementById('price-list');
  const priceFinal = document.getElementById('price-final');
  const priceArrow = document.getElementById('price-arrow');
  const labelEl = document.getElementById('price-label');
  const sidebarPayNote = document.getElementById('sidebar-pay-note');
  const errEl = document.getElementById('quote-error');
  const methodInput = document.getElementById('payment_method');
  const msiInput = document.getElementById('card_msi_months');
  const msiChips = document.getElementById('msi-chips');
  const msiMonthlyLine = document.getElementById('msi-monthly-line');
  const transferPanel = document.getElementById('pay-transfer-panel');
  const oxxoPanel = document.getElementById('pay-oxxo-panel');
  const proofPanel = document.getElementById('payment-proof-panel');
  const msiPanel = document.getElementById('pay-msi-panel');
  const proofInput = document.getElementById('payment_proof');
  const proofPicker = document.getElementById('proof-picker');
  const proofFilename = document.getElementById('proof-filename');
  const payHint = document.getElementById('pay-hint');
  const submitBtn = document.getElementById('checkout-submit');
  const prevBtn = document.getElementById('wizard-prev');
  const nextBtn = document.getElementById('wizard-next');
  const confirmSummary = document.getElementById('confirm-summary');
  const examDateHidden = document.getElementById('exam_date');
  const examTimeHidden = document.getElementById('exam_time');
  const examKindHidden = document.getElementById('exam_kind');
  const examSessionHidden = document.getElementById('exam_session_id');
  const examDateSelect = document.getElementById('exam_date_select');
  const examTimeSelect = document.getElementById('exam_time_select');
  const examSessionSelect = document.getElementById('exam_session_select');
  const examSlotHint = document.getElementById('exam-slot-hint');
  const examExtraToggle = document.getElementById('exam_extraordinary_toggle');
  const examExtraFields = document.getElementById('exam-extraordinary-fields');
  const examExtraDate = document.getElementById('exam_extraordinary_date');
  const examExtraTime = document.getElementById('exam_extraordinary_time');
  const examSurchargeNote = document.getElementById('exam-surcharge-note');
  let examMode = 'window';
  let examExtraordinary = null;
  let examBaseAmount = Number(quoteData.base ?? quoteData.catalog ?? 0);

  if (!form || !prevBtn || !nextBtn || !submitBtn) {
    console.error('[checkout] Formulario o botones del wizard no encontrados.');
    return;
  }

  function money(n) {
    return '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function baseAmount() { return Number(quoteData.base ?? quoteData.catalog ?? 0); }
  function catalogAmount() { return Number(quoteData.catalog ?? 0); }
  function cardPlans() { return quoteData.payment_options?.msi || quoteData.msi_plans || []; }
  function selectedCardPlan() {
    const months = activeMsiMonths();
    const plans = cardPlans();
    return plans.find(p => Number(p.months) === months) || plans[0] || null;
  }
  function selectedPayTotal() {
    const plan = payUi === 'msi' ? selectedCardPlan() : null;
    return plan ? Number(plan.total || plan.base || baseAmount()) : baseAmount();
  }

  function updatePriceSummary() {
    const catalog = catalogAmount();
    const total = selectedPayTotal();
    const hasAdjustment = Math.abs(total - catalog) > 0.009;
    priceList.textContent = money(catalog);
    if (hasAdjustment) {
      priceList.classList.add('price-strike');
      priceArrow.style.display = '';
      priceFinal.style.display = '';
      priceFinal.textContent = money(total);
    } else {
      priceList.classList.remove('price-strike');
      priceArrow.style.display = 'none';
      priceFinal.style.display = 'none';
    }
    if (sidebarPayNote) {
      sidebarPayNote.textContent = 'Total a pagar';
    }
  }

  function currentStep() { return wizardSteps[stepIndex]; }

  function showStep(index) {
    stepIndex = Math.max(0, Math.min(wizardSteps.length - 1, index));
    document.querySelectorAll('.wizard-step').forEach(el => {
      el.hidden = el.getAttribute('data-step') !== currentStep();
      el.classList.toggle('active', el.getAttribute('data-step') === currentStep());
    });
    document.querySelectorAll('.timeline-step').forEach((btn, i) => {
      const code = wizardSteps[i];
      if (!code) return;
      btn.classList.toggle('active', i === stepIndex);
      btn.classList.toggle('done', i < stepIndex);
      btn.disabled = i > stepIndex;
    });
    prevBtn.hidden = stepIndex === 0;
    nextBtn.hidden = stepIndex === wizardSteps.length - 1;
    submitBtn.hidden = stepIndex !== wizardSteps.length - 1;
    if (comboSidebarTeaser) {
      comboSidebarTeaser.hidden = comboStepIndex < 0 || stepIndex >= comboStepIndex;
    }
    if (currentStep() === 'paquete') {
      nextBtn.textContent = 'Continuar al pago';
    } else {
      nextBtn.textContent = 'Siguiente';
    }
    if (currentStep() === 'confirmar') buildConfirmSummary();
    if (currentStep() === 'paquete') refreshQuote();
    updateHint();
  }

  function fieldValue(name) {
    const el = form.querySelector('[name="' + name + '"]');
    return el ? String(el.value || '').trim() : '';
  }

  function fullName() {
    return [fieldValue('first_name'), fieldValue('last_name_p'), fieldValue('last_name_m')].filter(Boolean).join(' ');
  }

  function payMethodLabel() {
    if (payUi === 'transfer') return 'Transferencia SPEI (con comprobante)';
    if (payUi === 'oxxo') return 'Depósito OXXO · tarjeta ' + depositCard;
    if (payUi === 'msi') {
      const m = activeMsiMonths();
      return 'TDC · ' + m + ' meses';
    }
    return '';
  }

  function validateFieldsInStep(stepName) {
    const stepEl = form.querySelector('.wizard-step[data-step="' + stepName + '"]');
    if (!stepEl) return true;
    const fields = stepEl.querySelectorAll('input:not([type="hidden"]), select, textarea');
    for (const el of fields) {
      if (el.disabled) continue;
      if (!el.checkValidity()) {
        el.reportValidity();
        return false;
      }
    }
    return true;
  }

  function validateStep(step) {
    if (step === 'datos') {
      return validateFieldsInStep('datos');
    }
    if (step === 'reglamento' && window.reglamentoWizard) {
      try { window.reglamentoWizard.validateStep(); } catch (e) { alert(e.message); return false; }
    }
    if (step === 'agenda' && needsExam) {
      if (!examDateHidden?.value || !examTimeHidden?.value) {
        alert('Selecciona fecha y hora del examen.');
        return false;
      }
    }
    if (step === 'pago') {
      if ((payUi === 'transfer' || payUi === 'oxxo') && proofInput && !proofInput.files.length) {
        alert('Sube el comprobante de pago.');
        if (proofPicker) proofPicker.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
      }
    }
    return true;
  }

  function validateAllBeforeSubmit() {
    for (const step of wizardSteps) {
      if (step === 'confirmar') continue;
      if (!validateStep(step)) {
        const idx = wizardSteps.indexOf(step);
        if (idx >= 0) showStep(idx);
        return false;
      }
    }
    return true;
  }

  window.checkoutWizardValidateAll = validateAllBeforeSubmit;

  function buildConfirmSummary() {
    if (!confirmSummary) return;
    let html = '<dl>';
    html += '<dt>Alumno</dt><dd>' + (fullName() || '—') + '</dd>';
    html += '<dt>Correo</dt><dd>' + (fieldValue('email') || '—') + '</dd>';
    html += '<dt>Teléfono</dt><dd>' + (fieldValue('phone') || '—') + '</dd>';
    if (needsExam && examDateHidden && examTimeHidden) {
      html += '<dt>Examen</dt><dd>' + (examDateHidden.value || '—') + ' ' + (examTimeHidden.value ? examTimeHidden.value.substring(0, 5) : '') + '</dd>';
    }
    const comboPreset = document.querySelector('input[name="combo_preset"]:checked');
    let comboLabel = '';
    if (comboPreset && comboPreset.value) {
      comboLabel = comboPreset.getAttribute('data-combo-name') || 'Combo';
    } else if (comboIdInput && comboIdInput.value) {
      comboLabel = 'Combo aplicado';
    } else if (selectedAddonIds().length) {
      comboLabel = 'Extras seleccionados';
    }
    if (comboLabel) {
      html += '<dt>Paquete</dt><dd>' + comboLabel + '</dd>';
    }
    html += '<dt>Forma de pago</dt><dd>' + payMethodLabel() + '</dd>';
    html += '<dt>Monto</dt><dd><strong>' + money(selectedPayTotal()) + '</strong></dd>';
    if ((payUi === 'transfer' || payUi === 'oxxo') && proofInput && proofInput.files.length) {
      html += '<dt>Comprobante</dt><dd>' + proofInput.files[0].name + '</dd>';
    }
    html += '</dl>';
    confirmSummary.innerHTML = html;
  }

  prevBtn.addEventListener('click', () => showStep(stepIndex - 1));
  nextBtn.addEventListener('click', () => {
    if (!validateStep(currentStep())) return;
    showStep(stepIndex + 1);
  });

  function activeMsiMonths() {
    const chip = msiChips && msiChips.querySelector('.msi-chip.active');
    return chip ? Number(chip.getAttribute('data-months') || 1) : 1;
  }

  function updateMsiDisplay() {
    if (!msiMonthlyLine || !msiChips) return;
    const months = activeMsiMonths();
    const plans = cardPlans();
    const plan = plans.find(p => Number(p.months) === months) || plans[0];
    if (plan) {
      const months = Number(plan.months);
      msiMonthlyLine.textContent = months <= 1
        ? '1 exhibición · Total ' + money(plan.total)
        : months + ' pagos aprox. de ' + money(plan.monthly_estimate) + ' · Total ' + money(plan.total);
    } else {
      msiMonthlyLine.textContent = '';
    }
  }

  function updateHint() {
    if (!payHint || !submitBtn) return;
    if (currentStep() !== 'pago' && currentStep() !== 'confirmar') {
      payHint.textContent = '';
      return;
    }
    if (payUi === 'msi') {
      payHint.textContent = '';
      submitBtn.textContent = 'Confirmar registro';
    } else if (payUi === 'oxxo') {
      payHint.textContent = 'Sube tu comprobante de depósito para revisión.';
      submitBtn.textContent = 'Confirmar registro';
    } else {
      payHint.textContent = '';
      submitBtn.textContent = 'Confirmar registro';
    }
  }

  function selectPayUi(ui, method) {
    payUi = ui;
    if (methodInput) methodInput.value = method;
    document.querySelectorAll('.pay-tile').forEach(t => {
      const on = t.getAttribute('data-ui') === ui;
      t.classList.toggle('active', on);
      t.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (transferPanel) transferPanel.hidden = ui !== 'transfer';
    if (oxxoPanel) oxxoPanel.hidden = ui !== 'oxxo';
    if (msiPanel) msiPanel.hidden = ui !== 'msi';
    if (proofInput) {
      const needsProof = ui === 'transfer' || ui === 'oxxo';
      if (proofPanel) proofPanel.hidden = !needsProof;
      proofInput.required = needsProof && currentStep() === 'pago';
      if (!needsProof) proofInput.value = '';
      if (proofFilename) proofFilename.textContent = 'Ningún archivo seleccionado';
    }
    if (ui === 'msi' && msiChips) {
      const chip = msiChips.querySelector('.msi-chip.active') || msiChips.querySelector('.msi-chip');
      if (chip) msiInput.value = chip.getAttribute('data-months') || '1';
      updateMsiDisplay();
    } else {
      msiInput.value = '1';
    }
    updatePriceSummary();
    updateHint();
  }

  document.querySelectorAll('.pay-tile').forEach(btn => {
    btn.addEventListener('click', () => selectPayUi(btn.getAttribute('data-ui'), btn.getAttribute('data-method')));
  });

  if (msiChips) {
    msiChips.addEventListener('click', e => {
      const chip = e.target.closest('.msi-chip');
      if (!chip) return;
      msiChips.querySelectorAll('.msi-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      msiInput.value = chip.getAttribute('data-months') || '3';
      updateMsiDisplay();
      updatePriceSummary();
    });
  }

  function renderMsiChips(plans) {
    if (!msiChips) return;
    const list = (Array.isArray(plans) ? plans : []).filter(p => Number(p.months) >= 1);
    if (list.length === 0) {
      msiChips.innerHTML = '<span class="muted">Tarjeta no disponible.</span>';
      return;
    }
    msiChips.innerHTML = list.map((p, i) => {
      const m = Number(p.months);
      const label = m <= 1 ? '1 exhibición' : m + ' meses';
      return '<button type="button" class="msi-chip' + (i === 0 ? ' active' : '') + '" data-months="' + m + '">'
        + '<span>' + label + '</span><small>Total ' + money(p.total) + '</small></button>';
    }).join('');
    msiInput.value = String(list[0].months || 1);
    updateMsiDisplay();
  }

  const comboIdInput = document.getElementById('combo_id');
  const comboHint = document.getElementById('combo-match-hint');

  function selectedAddonIds() {
    return Array.prototype.slice.call(document.querySelectorAll('.combo-addon:checked'))
      .map(el => el.value)
      .filter(Boolean);
  }

  const breakdownEl = document.getElementById('combo-breakdown');
  const savingsNoteEl = document.getElementById('combo-savings-note');

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function (ch) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]);
    });
  }

  function renderComboBreakdown(breakdown, quote) {
    if (!breakdownEl) return;
    if (!breakdown || !Array.isArray(breakdown.items) || !breakdown.items.length) {
      breakdownEl.hidden = true;
      breakdownEl.innerHTML = '';
      if (savingsNoteEl) {
        savingsNoteEl.hidden = true;
        savingsNoteEl.textContent = '';
      }
      return;
    }
    let html = '<table class="combo-breakdown-table"><tbody>';
    breakdown.items.forEach(function (it) {
      html += '<tr>'
        + '<td>' + escapeHtml(it.name) + '</td>'
        + '<td class="num solo">' + money(it.solo_price) + '</td>'
        + '<td class="num share">' + money(it.combo_share) + '</td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    const packageList = Number(breakdown.package_list_price ?? breakdown.combo_price ?? 0);
    const charged = Number(breakdown.charged ?? (quote && quote.charged) ?? packageList);
    const promoSavings = Number(breakdown.promo_savings || 0);
    const code = quote && quote.discount_code ? String(quote.discount_code) : '';
    if (promoSavings > 0.009 && code) {
      html += '<p class="muted" style="margin:.45rem 0 0;font-size:.75rem;line-height:1.35">'
        + 'Paquete a precio de lista: <strong>' + money(packageList) + '</strong>'
        + ' · Código ' + escapeHtml(code) + ': −' + money(promoSavings)
        + '</p>';
    }
    breakdownEl.innerHTML = html;
    breakdownEl.hidden = false;
    if (savingsNoteEl) {
      const packageSavings = Number(breakdown.savings || 0);
      const totalSavings = Number(breakdown.total_savings != null ? breakdown.total_savings : packageSavings);
      const pct = breakdown.total_savings_percent != null
        ? breakdown.total_savings_percent
        : breakdown.savings_percent;
      if (totalSavings > 0.009) {
        savingsNoteEl.hidden = false;
        let msg = 'Ahorro vs precios de lista: ' + money(totalSavings)
          + (pct ? (' (' + pct + '%)') : '');
        if (promoSavings > 0.009 && code) {
          msg = 'Ahorro del paquete: ' + money(packageSavings)
            + ' + código ' + code + ': ' + money(promoSavings)
            + ' = ' + money(totalSavings);
        }
        savingsNoteEl.textContent = msg;
      } else {
        savingsNoteEl.hidden = true;
        savingsNoteEl.textContent = '';
      }
    }
  }

  function refreshQuote() {
    // Partners no usan código promocional: su beneficio es el precio de nivel.
    const code = isPartnerCheckout ? '' : ((codeInput && codeInput.value ? codeInput.value : '').trim());
    const comboId = comboIdInput && comboIdInput.value ? comboIdInput.value : '';
    const addons = selectedAddonIds();
    let url;
    if (comboId || addons.length) {
      url = <?= json_encode(url('/api/cotizar-combo/')) ?> + encodeURIComponent(slug)
        + '?code=' + encodeURIComponent(code)
        + '&combo_id=' + encodeURIComponent(comboId)
        + '&addons=' + encodeURIComponent(addons.join(','));
    } else {
      url = <?= json_encode(url('/api/cotizar/')) ?> + encodeURIComponent(slug)
        + '?code=' + encodeURIComponent(code);
    }
    fetch(url)
      .then(r => r.json())
      .then(data => {
        if (!data.ok) {
          if (errEl) {
            errEl.style.display = 'block';
            errEl.textContent = data.error || 'Código inválido';
          }
          return;
        }
        if (errEl) errEl.style.display = 'none';
        quoteData = data.quote;
        if (labelEl) {
          // Combo: nombre del paquete. Sin combo: etiqueta del quote (lista / partner / promo).
          labelEl.textContent = data.matched && data.combo && data.combo.name
            ? String(data.combo.name)
            : String((data.quote && data.quote.label) || '');
        }
        if (comboIdInput && data.matched && data.combo_id) {
          comboIdInput.value = String(data.combo_id);
        } else if (comboIdInput && !data.matched && !comboId) {
          comboIdInput.value = '';
        }
        if (comboHint) {
          if (addons.length && !data.matched) {
            comboHint.textContent = 'Esa combinación aún no tiene combo definido en admin. Se cotiza solo el producto actual.';
            comboHint.style.color = '#b42318';
          } else if (data.matched) {
            comboHint.textContent = 'Combo aplicado. Abajo ves cada producto a precio de lista y su parte del paquete.';
            comboHint.style.color = '';
          } else {
            comboHint.textContent = '';
          }
        }
        renderComboBreakdown(data.matched ? (data.breakdown || null) : null, data.quote || null);
        renderMsiChips(data.quote.payment_options?.msi || data.quote.msi_plans || []);
        updatePriceSummary();
        updateMsiDisplay();
      })
      .catch(() => {});
  }

  document.querySelectorAll('input[name="combo_preset"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (!radio.checked) return;
      const addonIds = (radio.getAttribute('data-addon-ids') || '').split(',').filter(Boolean);
      document.querySelectorAll('.combo-addon').forEach(function (cb) {
        cb.checked = addonIds.indexOf(cb.value) !== -1;
      });
      if (comboIdInput) comboIdInput.value = radio.value || '';
      refreshQuote();
    });
  });
  document.querySelectorAll('.combo-addon').forEach(function (cb) {
    cb.addEventListener('change', function () {
      // Al armar a la carta, limpiamos preset fijo y resolvemos por set exacto
      document.querySelectorAll('input[name="combo_preset"]').forEach(function (r) {
        if (r.value === '') r.checked = true;
        else r.checked = false;
      });
      if (comboIdInput) comboIdInput.value = '';
      refreshQuote();
    });
  });

  applyBtn?.addEventListener('click', refreshQuote);
  if (codeInput) {
    codeInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); refreshQuote(); }
    });
  }

  if (proofPicker && proofInput) {
    const pickBtn = proofPicker.querySelector('.file-picker-btn');
    if (pickBtn) pickBtn.addEventListener('click', () => proofInput.click());
    proofInput.addEventListener('change', () => {
      if (proofFilename) {
        proofFilename.textContent = proofInput.files.length
          ? proofInput.files[0].name
          : 'Ningún archivo seleccionado';
      }
    });
  }

  document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', () => {
      const text = btn.getAttribute('data-copy') || '';
      const done = () => {
        const prev = btn.textContent;
        btn.textContent = 'Copiado';
        setTimeout(() => { btn.textContent = prev; }, 1400);
      };
      const fallback = () => {
        const tmp = document.createElement('textarea');
        tmp.value = text;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
        done();
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(fallback);
      } else {
        fallback();
      }
    });
  });

  if (!window.reglamentoWizard) {
    form.addEventListener('submit', function (e) {
      if (!validateAllBeforeSubmit()) e.preventDefault();
    });
  }

  function examAdvanceHint(days) {
    const n = Number(days || 0);
    if (n <= 0) return 'Puedes agendar desde hoy (sin días de antelación).';
    if (n === 1) return 'Agendar con 1 día de antelación';
    return 'Agendar con ' + n + ' días de antelación';
  }

  function setExamSurchargeNote(amount, label) {
    if (!examSurchargeNote) return;
    const n = Number(amount || 0);
    if (n > 0) {
      examSurchargeNote.hidden = false;
      examSurchargeNote.textContent = (label || 'Fecha extraordinaria') + ': +' + money(n) + ' (se suma al total).';
      quoteData.base = examBaseAmount + n;
      quoteData.charged = examBaseAmount + n;
      updatePriceSummary();
    } else {
      examSurchargeNote.hidden = true;
      examSurchargeNote.textContent = '';
      quoteData.base = examBaseAmount;
      quoteData.charged = examBaseAmount;
      updatePriceSummary();
    }
  }

  function syncExtraordinaryUi() {
    const on = !!(examExtraToggle && examExtraToggle.checked);
    if (examExtraFields) examExtraFields.hidden = !on;
    if (examDateSelect) examDateSelect.disabled = on;
    if (examTimeSelect) examTimeSelect.disabled = on || !examDateSelect || !examDateSelect.value;
    if (examKindHidden) examKindHidden.value = on ? 'extraordinary' : 'regular';
    if (on) {
      if (examDateHidden) examDateHidden.value = examExtraDate ? examExtraDate.value : '';
      if (examTimeHidden) examTimeHidden.value = examExtraTime ? examExtraTime.value : '';
      const amt = examExtraordinary && examExtraordinary.surcharge_amount
        ? Number(examExtraordinary.surcharge_amount) : 0;
      const label = examExtraordinary && examExtraordinary.surcharge_label
        ? examExtraordinary.surcharge_label : 'Fecha extraordinaria';
      setExamSurchargeNote(amt, label);
    } else {
      if (examDateHidden) examDateHidden.value = examDateSelect ? examDateSelect.value : '';
      if (examTimeHidden) examTimeHidden.value = examTimeSelect ? examTimeSelect.value : '';
      setExamSurchargeNote(0, '');
    }
  }

  function loadExamDates() {
    if (!needsExam) return;
    fetch(<?= json_encode(url('/api/examen-slots/')) ?> + encodeURIComponent(slug))
      .then(r => r.json())
      .then(data => {
        if (!data.ok) return;
        examMode = data.mode || 'window';
        examExtraordinary = data.extraordinary || null;
        examBaseAmount = Number(quoteData.base ?? quoteData.catalog ?? 0);
        if (examDateSelect && data.min_date) examDateSelect.min = data.min_date;
        if (examSlotHint && data.min_advance_days !== undefined && examMode !== 'dated_list') {
          examSlotHint.textContent = examAdvanceHint(data.min_advance_days);
        }
        if (examSessionSelect && Array.isArray(data.sessions)) {
          examSessionSelect.innerHTML = '<option value="">— elige convocatoria —</option>';
          data.sessions.forEach(function (s) {
            const opt = document.createElement('option');
            opt.value = s.id || s.value || '';
            opt.textContent = s.label || ((s.exam_date || '') + ' ' + (s.exam_time || ''));
            opt.dataset.date = s.exam_date || '';
            opt.dataset.time = s.exam_time || '';
            if (s.registration_deadline) {
              opt.textContent += ' · límite ' + s.registration_deadline;
            }
            examSessionSelect.appendChild(opt);
          });
        }
      });
  }

  const examDateWarn = document.getElementById('exam-date-warn');
  function setExamDateWarn(msg) {
    if (!examDateWarn) return;
    if (msg) {
      examDateWarn.hidden = false;
      examDateWarn.textContent = msg;
    } else {
      examDateWarn.hidden = true;
      examDateWarn.textContent = '';
    }
  }

  function loadExamSlots(date) {
    if (!examTimeSelect) return;
    examTimeSelect.innerHTML = '<option value="">— elige hora —</option>';
    examTimeSelect.disabled = true;
    setExamDateWarn('');
    if (!date) return;
    fetch(<?= json_encode(url('/api/examen-slots/')) ?> + encodeURIComponent(slug) + '?date=' + encodeURIComponent(date))
      .then(r => r.json())
      .then(data => {
        if (!data.ok || !data.slots) return;
        data.slots.forEach(s => {
          const opt = document.createElement('option');
          opt.value = s.value;
          opt.textContent = s.label;
          examTimeSelect.appendChild(opt);
        });
        const empty = data.slots.length === 0;
        examTimeSelect.disabled = empty || !!(examExtraToggle && examExtraToggle.checked);
        if (empty) {
          setExamDateWarn(data.unavailable_reason
            || 'Esa fecha no tiene horarios disponibles. Elige otro día.');
        }
      })
      .catch(function () {
        setExamDateWarn('No se pudieron cargar los horarios. Intenta de nuevo.');
      });
  }

  if (examDateSelect) {
    examDateSelect.addEventListener('change', () => {
      const d = examDateSelect.value;
      if (examDateHidden) examDateHidden.value = d;
      if (examTimeHidden) examTimeHidden.value = '';
      if (examKindHidden) examKindHidden.value = 'regular';
      loadExamSlots(d);
    });
  }
  if (examTimeSelect) {
    examTimeSelect.addEventListener('change', () => {
      if (examTimeHidden) examTimeHidden.value = examTimeSelect.value;
    });
  }
  if (examSessionSelect) {
    examSessionSelect.addEventListener('change', function () {
      const opt = examSessionSelect.options[examSessionSelect.selectedIndex];
      if (examSessionHidden) examSessionHidden.value = examSessionSelect.value || '';
      if (examDateHidden) examDateHidden.value = opt && opt.dataset.date ? opt.dataset.date : '';
      if (examTimeHidden) examTimeHidden.value = opt && opt.dataset.time ? opt.dataset.time : '';
      if (examKindHidden) examKindHidden.value = 'provider_session';
    });
  }
  if (examExtraToggle) {
    examExtraToggle.addEventListener('change', syncExtraordinaryUi);
  }
  if (examExtraDate) {
    examExtraDate.addEventListener('change', syncExtraordinaryUi);
    examExtraDate.addEventListener('input', syncExtraordinaryUi);
  }
  if (examExtraTime) {
    examExtraTime.addEventListener('change', syncExtraordinaryUi);
    examExtraTime.addEventListener('input', syncExtraordinaryUi);
  }

  renderMsiChips(quoteData.payment_options?.msi || quoteData.msi_plans || []);
  updatePriceSummary();
  selectPayUi('transfer', 'transfer_proof');
  showStep(0);
  loadExamDates();
})();
</script>
