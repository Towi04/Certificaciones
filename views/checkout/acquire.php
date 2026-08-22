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

$catalogPrice = (float) ($quote['catalog'] ?? $product['catalog_price'] ?? 0);
$basePrice = (float) ($quote['base'] ?? $catalogPrice);

$wizardSteps = ['datos'];
if (!empty($reglamento)) {
    $wizardSteps[] = 'reglamento';
}
if ($needsExam) {
    $wizardSteps[] = 'agenda';
}
$wizardSteps[] = 'pago';
$wizardSteps[] = 'confirmar';

$stepLabels = [
    'datos' => 'Datos',
    'reglamento' => 'Reglamento',
    'agenda' => 'Agenda',
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

            <form method="post" action="<?= e(url('/adquirir/' . $product['slug'])) ?>" enctype="multipart/form-data" class="checkout-form panel" id="checkout-form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="payment_method" id="payment_method" value="<?= $openpayReady ? 'openpay_spei' : 'transfer_proof' ?>">
                <input type="hidden" name="card_msi_months" id="card_msi_months" value="3">
                <?php if ($needsExam): ?>
                    <input type="hidden" name="exam_date" id="exam_date" value="">
                    <input type="hidden" name="exam_time" id="exam_time" value="">
                <?php endif; ?>

                <div class="wizard-step active" data-step="datos">
                    <h2 class="step-title">Tus datos</h2>
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
                            <?php if ($field['type'] === 'select' && $code === 'sex'): ?>
                                <label><?= e($field['label']) ?><?= $req ? ' *' : '' ?>
                                    <select name="sex" <?= $req ? 'required' : '' ?>>
                                        <option value="">—</option>
                                        <option value="F">Femenino</option>
                                        <option value="M">Masculino</option>
                                        <option value="X">Otro / X</option>
                                    </select>
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
                    <div class="wizard-step" data-step="agenda" hidden>
                        <h2 class="step-title">Agenda tu examen</h2>
                        <p class="muted" style="font-size:.88rem;margin-top:0">
                            El examen ELeT es en línea. Elige fecha y hora disponible (bloques de 30 minutos).
                        </p>
                        <div class="form-grid" style="max-width:480px">
                            <label>Fecha del examen *
                                <select id="exam_date_select">
                                    <option value="">— elige fecha —</option>
                                </select>
                            </label>
                            <label>Hora *
                                <select id="exam_time_select" disabled>
                                    <option value="">— elige hora —</option>
                                </select>
                            </label>
                        </div>
                        <p class="muted" id="exam-slot-hint" style="font-size:.82rem;margin-top:.5rem"></p>
                    </div>
                <?php endif; ?>

                <div class="wizard-step" data-step="pago" hidden>
                    <h2 class="step-title">Forma de pago</h2>
                    <p class="muted" style="margin-top:0;font-size:.88rem">Elige cómo realizarás tu pago.</p>

                    <div class="pay-tiles" role="group" aria-label="Método de pago">
                        <?php if ($openpayReady): ?>
                            <button type="button" class="pay-tile active" data-method="openpay_spei" data-ui="spei" aria-pressed="true">
                                <span class="pay-tile-icon" aria-hidden="true">🏦</span>
                                <span class="pay-tile-label">SPEI</span>
                                <span class="pay-tile-sub">CLABE única OpenPay</span>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="pay-tile<?= $openpayReady ? '' : ' active' ?>" data-method="transfer_proof" data-ui="transfer" aria-pressed="<?= $openpayReady ? 'false' : 'true' ?>">
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
                                <span class="pay-tile-label">Meses</span>
                                <span class="pay-tile-sub">MSI con tarjeta</span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($openpayReady): ?>
                        <div id="pay-spei-panel" class="pay-panel">
                            <p class="muted" style="font-size:.88rem;margin:.75rem 0 .5rem">
                                Al confirmar tu registro generaremos una CLABE SPEI única para este caso. No necesitas subir comprobante.
                            </p>
                            <p class="muted" style="font-size:.82rem;margin:.5rem 0 0">
                                OpenPay notificará automáticamente a DOCEO cuando el pago quede aplicado.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div id="pay-transfer-panel" class="pay-panel" <?= $openpayReady ? 'hidden' : '' ?>>
                        <p class="muted" style="font-size:.88rem;margin:.75rem 0 .5rem">
                            Transfiere el monto indicado en el panel derecho y sube tu comprobante.
                        </p>
                        <?php if (!empty($bank['clabe'])): ?>
                            <ul class="deposit-info">
                                <li>Banco: <strong><?= e($bank['bank']) ?></strong></li>
                                <li>CLABE: <strong class="mono"><?= e($bank['clabe']) ?></strong></li>
                                <li>Titular: <strong><?= e($bank['holder']) ?></strong></li>
                            </ul>
                        <?php endif; ?>
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

                    <div id="pay-oxxo-panel" class="pay-panel" hidden>
                        <p class="muted" style="font-size:.88rem;margin:.75rem 0 .5rem">
                            Realiza un depósito en OXXO (o tienda afiliada) a esta tarjeta:
                        </p>
                        <div class="deposit-card-box">
                            <span class="muted" style="font-size:.82rem">Número de tarjeta</span>
                            <span class="mono deposit-card-num"><?= e($depositCard) ?></span>
                        </div>
                        <p class="muted" style="font-size:.82rem;margin:.5rem 0 0">
                            Deposita el monto exacto del panel derecho. Al confirmar tu registro recibirás las instrucciones con tu matrícula.
                        </p>
                    </div>

                    <div id="pay-msi-panel" class="pay-panel" hidden>
                        <p class="muted" style="font-size:.82rem;margin:.75rem 0 .4rem">¿A cuántos meses?</p>
                        <div class="msi-chips" id="msi-chips"></div>
                        <p class="msi-monthly-line" id="msi-monthly-line"></p>
                        <p class="muted" style="font-size:.82rem;margin:.35rem 0 0">
                            Tu banco difiere el cobro mensual; OpenPay autoriza el total con tarjeta.
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
            <div class="sidebar-promo">
                <label for="promo_code">Código promocional</label>
                <div class="sidebar-promo-row">
                    <input type="text" name="promo_code" id="promo_code" placeholder="Opcional" form="checkout-form" style="text-transform:uppercase">
                    <button type="button" class="btn btn-primary btn-sm" id="apply-promo">Aplicar</button>
                </div>
                <p class="muted" id="quote-error" style="color:#b00020;display:none;margin:.4rem 0 0;font-size:.82rem"></p>
            </div>
            <div class="sidebar-price">
                <div class="muted" style="font-size:.82rem" id="price-label"><?= e($quote['label'] ?? 'Precio de lista') ?></div>
                <div class="sidebar-price-row">
                    <span class="price" id="price-list"><?= money($catalogPrice) ?></span>
                    <span id="price-arrow" style="display:none;color:var(--doceo-muted)">→</span>
                    <span class="price" id="price-final" style="display:none"></span>
                </div>
                <p class="muted" style="font-size:.78rem;margin:.5rem 0 0" id="sidebar-pay-note">Total a pagar</p>
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

.deposit-info { font-size:.85rem; margin:.5rem 0 .75rem; padding-left:1.1rem; }
.mono { font-family:ui-monospace,monospace; letter-spacing:.03em; }
.deposit-card-box {
  padding:1rem 1.15rem; border-radius:12px; background:#f4f7fb; border:1px solid #d5deea;
  display:flex; flex-direction:column; gap:.35rem;
}
.deposit-card-num { font-size:1.15rem; font-weight:700; color:var(--doceo-blue); }

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
}
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
  const depositCard = <?= json_encode($depositCard, JSON_UNESCAPED_UNICODE) ?>;

  let quoteData = <?= json_encode($quote, JSON_UNESCAPED_UNICODE) ?>;
  let payUi = openpayReady ? 'spei' : 'transfer';
  let stepIndex = 0;

  const form = document.getElementById('checkout-form');
  const codeInput = document.getElementById('promo_code');
  const applyBtn = document.getElementById('apply-promo');
  const priceList = document.getElementById('price-list');
  const priceFinal = document.getElementById('price-final');
  const priceArrow = document.getElementById('price-arrow');
  const labelEl = document.getElementById('price-label');
  const errEl = document.getElementById('quote-error');
  const methodInput = document.getElementById('payment_method');
  const msiInput = document.getElementById('card_msi_months');
  const msiChips = document.getElementById('msi-chips');
  const msiMonthlyLine = document.getElementById('msi-monthly-line');
  const speiPanel = document.getElementById('pay-spei-panel');
  const transferPanel = document.getElementById('pay-transfer-panel');
  const oxxoPanel = document.getElementById('pay-oxxo-panel');
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
  const examDateSelect = document.getElementById('exam_date_select');
  const examTimeSelect = document.getElementById('exam_time_select');
  const examSlotHint = document.getElementById('exam-slot-hint');

  if (!form || !prevBtn || !nextBtn || !submitBtn) {
    console.error('[checkout] Formulario o botones del wizard no encontrados.');
    return;
  }

  function money(n) {
    return '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function baseAmount() { return Number(quoteData.base ?? quoteData.catalog ?? 0); }
  function catalogAmount() { return Number(quoteData.catalog ?? 0); }

  function updatePriceSummary() {
    const catalog = catalogAmount();
    const base = baseAmount();
    const hasDiscount = base > 0 && base < catalog - 0.009;
    priceList.textContent = money(catalog);
    if (hasDiscount) {
      priceList.classList.add('price-strike');
      priceArrow.style.display = '';
      priceFinal.style.display = '';
      priceFinal.textContent = money(base);
    } else {
      priceList.classList.remove('price-strike');
      priceArrow.style.display = 'none';
      priceFinal.style.display = 'none';
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
    if (currentStep() === 'confirmar') buildConfirmSummary();
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
    if (payUi === 'spei') return 'SPEI OpenPay (CLABE única)';
    if (payUi === 'transfer') return 'Transferencia SPEI (con comprobante)';
    if (payUi === 'oxxo') return 'Depósito OXXO · tarjeta ' + depositCard;
    if (payUi === 'msi') {
      const m = activeMsiMonths();
      return 'Tarjeta · ' + m + ' MSI';
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
      if (payUi === 'transfer' && proofInput && !proofInput.files.length) {
        alert('Sube el comprobante de tu transferencia.');
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
    html += '<dt>Forma de pago</dt><dd>' + payMethodLabel() + '</dd>';
    html += '<dt>Monto</dt><dd><strong>' + money(baseAmount()) + '</strong></dd>';
    if (payUi === 'transfer' && proofInput && proofInput.files.length) {
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
    return chip ? Number(chip.getAttribute('data-months') || 3) : 3;
  }

  function updateMsiDisplay() {
    if (!msiMonthlyLine || !msiChips) return;
    const months = activeMsiMonths();
    const plans = quoteData.payment_options?.msi || quoteData.msi_plans || [];
    const plan = plans.find(p => Number(p.months) === months) || plans[0];
    if (plan && Number(plan.months) > 1) {
      msiMonthlyLine.textContent = 'Mensualidades de ' + money(plan.monthly_estimate);
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
      payHint.textContent = 'Al confirmar irás a OpenPay para pagar con tarjeta.';
      submitBtn.textContent = 'Confirmar y pagar con tarjeta';
    } else if (payUi === 'spei') {
      payHint.textContent = 'Al confirmar verás tu CLABE SPEI única para completar el pago.';
      submitBtn.textContent = 'Confirmar y obtener CLABE';
    } else if (payUi === 'oxxo') {
      payHint.textContent = 'Al confirmar verás las instrucciones de depósito con tu matrícula.';
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
    if (speiPanel) speiPanel.hidden = ui !== 'spei';
    if (transferPanel) transferPanel.hidden = ui !== 'transfer';
    if (oxxoPanel) oxxoPanel.hidden = ui !== 'oxxo';
    if (msiPanel) msiPanel.hidden = ui !== 'msi';
    if (proofInput) {
      proofInput.required = ui === 'transfer' && currentStep() === 'pago';
      if (ui !== 'transfer') proofInput.value = '';
      if (proofFilename) proofFilename.textContent = 'Ningún archivo seleccionado';
    }
    if (ui === 'msi' && msiChips) {
      const chip = msiChips.querySelector('.msi-chip.active') || msiChips.querySelector('.msi-chip');
      if (chip) msiInput.value = chip.getAttribute('data-months') || '3';
      updateMsiDisplay();
    } else {
      msiInput.value = '1';
    }
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
    });
  }

  function renderMsiChips(plans) {
    if (!msiChips) return;
    const list = (Array.isArray(plans) ? plans : []).filter(p => Number(p.months) > 1);
    if (list.length === 0) {
      msiChips.innerHTML = '<span class="muted">MSI no disponible.</span>';
      return;
    }
    msiChips.innerHTML = list.map((p, i) => {
      const m = Number(p.months);
      return '<button type="button" class="msi-chip' + (i === 0 ? ' active' : '') + '" data-months="' + m + '">' + m + ' meses</button>';
    }).join('');
    msiInput.value = String(list[0].months || 3);
    updateMsiDisplay();
  }

  function refreshQuote() {
    const code = (codeInput.value || '').trim();
    fetch(<?= json_encode(url('/api/cotizar/')) ?> + encodeURIComponent(slug) + '?code=' + encodeURIComponent(code))
      .then(r => r.json())
      .then(data => {
        if (!data.ok) {
          errEl.style.display = 'block';
          errEl.textContent = data.error || 'Código inválido';
          return;
        }
        errEl.style.display = 'none';
        quoteData = data.quote;
        labelEl.textContent = data.quote.label || 'Precio de lista';
        renderMsiChips(data.quote.payment_options?.msi || data.quote.msi_plans || []);
        updatePriceSummary();
        updateMsiDisplay();
      })
      .catch(() => {});
  }

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

  if (!window.reglamentoWizard) {
    form.addEventListener('submit', function (e) {
      if (!validateAllBeforeSubmit()) e.preventDefault();
    });
  }

  function loadExamDates() {
    if (!needsExam || !examDateSelect) return;
    fetch(<?= json_encode(url('/api/examen-slots/')) ?> + encodeURIComponent(slug))
      .then(r => r.json())
      .then(data => {
        if (!data.ok || !data.dates) return;
        examDateSelect.innerHTML = '<option value="">— elige fecha —</option>';
        data.dates.forEach(d => {
          const opt = document.createElement('option');
          opt.value = d;
          const label = new Date(d + 'T12:00:00').toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
          opt.textContent = label;
          examDateSelect.appendChild(opt);
        });
        if (examSlotHint) examSlotHint.textContent = 'Anticipo mínimo: desde ' + (data.min_date || '');
      });
  }

  function loadExamSlots(date) {
    if (!examTimeSelect) return;
    examTimeSelect.innerHTML = '<option value="">— elige hora —</option>';
    examTimeSelect.disabled = true;
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
        examTimeSelect.disabled = data.slots.length === 0;
      });
  }

  if (examDateSelect) {
    examDateSelect.addEventListener('change', () => {
      const d = examDateSelect.value;
      if (examDateHidden) examDateHidden.value = d;
      if (examTimeHidden) examTimeHidden.value = '';
      loadExamSlots(d);
    });
  }
  if (examTimeSelect) {
    examTimeSelect.addEventListener('change', () => {
      if (examTimeHidden) examTimeHidden.value = examTimeSelect.value;
    });
  }

  renderMsiChips(quoteData.payment_options?.msi || quoteData.msi_plans || []);
  updatePriceSummary();
  selectPayUi(openpayReady ? 'spei' : 'transfer', openpayReady ? 'openpay_spei' : 'transfer_proof');
  showStep(0);
  loadExamDates();
})();
</script>
