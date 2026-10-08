/**
 * Tutorial ligero por vista del portal partner (burbujas ancladas a [data-tour]).
 */
(function (global) {
  'use strict';

  var STEPS = {
    dashboard: [
      { sel: '[data-tour="partner-nav"]', title: 'Menú partner', body: 'Desde aquí navegas alumnos, registro, avance, perfil y tu escuela.' },
      { sel: '[data-tour="students-header"]', title: 'Tu tablero', body: 'Aquí ves el resumen de tu código, crédito y accesos rápidos.' },
      { sel: '[data-tour="students-table"]', title: 'Cartera de alumnos', body: 'Abre cada caso para ver datos, códigos, correos y resultados.' }
    ],
    alumnos: [
      { sel: '[data-tour="students-header"]', title: 'Alumnos', body: 'Tu cartera: matrícula, examen, folio/clave y últimos correos de accesos.' },
      { sel: '[data-tour="register-cta"]', title: 'Registrar', body: 'Usa este botón para inscribir un alumno con el flujo completo.' },
      { sel: '[data-tour="students-table"]', title: 'Abrir ficha', body: 'Haz clic en «Abrir» para ver el detalle del caso.' }
    ],
    caso: [
      { sel: '[data-tour="case-registration"]', title: 'Datos del alumno', body: 'Siempre visibles. Solo se editan antes de asignar folio y clave.' },
      { sel: '[data-tour="case-codes"]', title: 'Códigos de acceso', body: 'Cuando DOCEO asigne folio/clave (y extra), aparecerán aquí.' },
      { sel: '[data-tour="case-mails"]', title: 'Correos al alumno', body: 'Historial de correos del caso (sin publicidad ni correos a proveedor).' },
      { sel: '[data-tour="case-results"]', title: 'Resultados', body: 'Nivel, puntaje, certificado y PDF cuando existan.' }
    ],
    registrar: [
      { sel: '[data-tour="register-header"]', title: 'Registrar alumno', body: 'Elige un producto del catálogo partner y completa el mismo flujo de compra.' },
      { sel: '[data-tour="partner-nav"]', title: 'Menú', body: 'También puedes volver a alumnos o ir a registro de grupo desde el menú lateral.' }
    ],
    'registrar-grupo': [
      { sel: '[data-tour="bulk-header"]', title: 'Registro de grupo', body: 'Un producto, una fecha, CSV de alumnos y un solo comprobante.' },
      { sel: '[data-tour="bulk-form"], [data-tour="bulk-proof"]', title: 'Pasos del lote', body: 'Primero revisas el CSV; luego subes el comprobante por el monto partner × N.' }
    ],
    avance: [
      { sel: '[data-tour="progress-stats"]', title: 'Tu avance', body: 'Ventas del mes/convenio y crédito. Los convenios especiales no ven la escala.' },
      { sel: '[data-tour="tier-progress"]', title: 'Niveles', body: 'Barra y metas Bronze / Silver / Gold de tu convenio.' }
    ],
    perfil: [
      { sel: '[data-tour="profile-readonly"]', title: 'Nivel y crédito', body: 'Estos datos son de solo lectura.' },
      { sel: '[data-tour="profile-form"]', title: 'Editar perfil', body: 'Actualiza nombre comercial, contacto, código de descuento y contraseña.' },
      { sel: '[data-tour="tutorial-reset"]', title: 'Reiniciar tutorial', body: 'Desde aquí puedes volver a ver las burbujas de ayuda.' }
    ],
    'mi-escuela': [
      { sel: '[data-tour="school-header"]', title: 'Directorio', body: 'Publica tu escuela como distribuidor autorizado (con aprobación de DOCEO).' },
      { sel: '[data-tour="school-form"]', title: 'Borrador', body: 'Guarda y envía a revisión. Los cambios nuevos no se publican hasta aprobarse.' }
    ]
  };

  var overlay, bubble, currentView, currentSteps, index, completeUrl, csrf;

  function qs(sel, root) {
    try { return (root || document).querySelector(sel); } catch (e) { return null; }
  }

  function resolveStep(step) {
    var parts = String(step.sel || '').split(',').map(function (s) { return s.trim(); });
    for (var i = 0; i < parts.length; i++) {
      var el = qs(parts[i]);
      if (el) return { el: el, title: step.title, body: step.body };
    }
    return null;
  }

  function ensureUi() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.id = 'partner-tour-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = '<div class="partner-tour-backdrop"></div>';
    document.body.appendChild(overlay);

    // Burbuja aparte del overlay: debe quedar SIEMPRE por encima del target
    // (p. ej. el sidebar sticky al que se le sube z-index al resaltar).
    bubble = document.createElement('div');
    bubble.className = 'partner-tour-bubble';
    bubble.setAttribute('role', 'dialog');
    bubble.setAttribute('aria-modal', 'true');
    bubble.setAttribute('aria-hidden', 'true');
    bubble.innerHTML = ''
      + '<div class="partner-tour-title"></div>'
      + '<div class="partner-tour-body"></div>'
      + '<div class="partner-tour-actions">'
      + '  <button type="button" class="btn btn-ghost btn-sm partner-tour-skip">Omitir</button>'
      + '  <button type="button" class="btn btn-accent btn-sm partner-tour-next">Siguiente</button>'
      + '</div>';
    document.body.appendChild(bubble);

    bubble.querySelector('.partner-tour-skip').addEventListener('click', finish);
    bubble.querySelector('.partner-tour-next').addEventListener('click', next);
    overlay.querySelector('.partner-tour-backdrop').addEventListener('click', finish);

    var style = document.createElement('style');
    style.textContent = ''
      // Capas: backdrop < target resaltado < burbuja (legible sobre el sidebar).
      + '#partner-tour-overlay{position:fixed;inset:0;z-index:99990;pointer-events:none;display:none}'
      + '#partner-tour-overlay.is-open{display:block;pointer-events:auto}'
      + '.partner-tour-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.35)}'
      + '.partner-tour-bubble{position:fixed;z-index:100010;max-width:320px;width:min(320px,calc(100vw - 24px));'
      + 'background:#fff;border-radius:14px;box-shadow:0 18px 50px rgba(15,23,42,.28);'
      + 'padding:1rem 1.05rem;border:1px solid #dbe3f0;display:none;pointer-events:auto}'
      + '.partner-tour-bubble.is-open{display:block}'
      + '.partner-tour-title{font-weight:700;color:#1e3a8a;margin-bottom:.35rem}'
      + '.partner-tour-body{font-size:.9rem;line-height:1.4;color:#334155;margin-bottom:.85rem}'
      + '.partner-tour-actions{display:flex;justify-content:flex-end;gap:.4rem}'
      + '.partner-tour-target{outline:3px solid #f59e0b;outline-offset:3px;border-radius:10px;'
      + 'position:relative;z-index:100000;isolation:isolate}'
      // No pisar sticky del menú lateral al resaltar.
      + '.side-nav.partner-tour-target{position:sticky;top:0}';
    document.head.appendChild(style);
  }

  function clearHighlight() {
    document.querySelectorAll('.partner-tour-target').forEach(function (el) {
      el.classList.remove('partner-tour-target');
    });
  }

  function placeBubble(el) {
    var rect = el.getBoundingClientRect();
    var gap = 12;
    var bubbleW = Math.min(320, window.innerWidth - 24);
    var bubbleH = bubble.offsetHeight || 180;
    var left;
    var top;

    // Sidebar / columna izquierda alta: anclar a la derecha del menú.
    var isSideNav = el.classList.contains('side-nav')
      || el.id === 'partner-side-nav'
      || (rect.height > window.innerHeight * 0.6 && rect.left < 80);

    if (isSideNav) {
      left = rect.right + gap;
      if (left + bubbleW > window.innerWidth - gap) {
        left = Math.max(gap, window.innerWidth - bubbleW - gap);
      }
      top = Math.min(Math.max(gap, rect.top + 72), window.innerHeight - bubbleH - gap);
    } else {
      left = Math.min(Math.max(gap, rect.left), window.innerWidth - bubbleW - gap);
      top = rect.bottom + gap;
      if (top + bubbleH > window.innerHeight - gap) {
        top = Math.max(gap, rect.top - bubbleH - gap);
      }
    }

    bubble.style.top = Math.round(top) + 'px';
    bubble.style.left = Math.round(left) + 'px';
  }

  function showStep() {
    clearHighlight();
    while (index < currentSteps.length) {
      var resolved = resolveStep(currentSteps[index]);
      if (resolved) {
        resolved.el.classList.add('partner-tour-target');
        // No scrollear el sidebar completo fuera de vista.
        if (!resolved.el.classList.contains('side-nav') && resolved.el.id !== 'partner-side-nav') {
          resolved.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        bubble.querySelector('.partner-tour-title').textContent = resolved.title;
        bubble.querySelector('.partner-tour-body').textContent = resolved.body;
        var nextBtn = bubble.querySelector('.partner-tour-next');
        nextBtn.textContent = index >= currentSteps.length - 1 ? 'Listo' : 'Siguiente';
        bubble.classList.add('is-open');
        bubble.setAttribute('aria-hidden', 'false');
        // Medir altura real tras pintar texto.
        requestAnimationFrame(function () { placeBubble(resolved.el); });
        return;
      }
      index++;
    }
    finish();
  }

  function next() {
    index++;
    if (index >= currentSteps.length) {
      finish();
      return;
    }
    showStep();
  }

  function postComplete() {
    if (!completeUrl) return;
    var body = new URLSearchParams();
    body.set('view', currentView || '');
    if (csrf) body.set('_csrf', csrf);
    try {
      fetch(completeUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
        credentials: 'same-origin'
      }).catch(function () {});
    } catch (e) {}
  }

  function finish() {
    clearHighlight();
    if (overlay) {
      overlay.classList.remove('is-open');
      overlay.setAttribute('aria-hidden', 'true');
    }
    if (bubble) {
      bubble.classList.remove('is-open');
      bubble.setAttribute('aria-hidden', 'true');
    }
    postComplete();
  }

  function start(view) {
    currentView = view;
    currentSteps = STEPS[view] || [];
    if (!currentSteps.length) return;
    ensureUi();
    index = 0;
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    showStep();
  }

  function maybeStart(view, state) {
    state = state || {};
    var views = state.views || state || {};
    if (views[view]) return;
    // Pequeña pausa para layout/nav.
    setTimeout(function () { start(view); }, 350);
  }

  global.DoceoPartnerTour = {
    maybeStart: maybeStart,
    start: start,
    configure: function (opts) {
      completeUrl = opts && opts.completeUrl ? opts.completeUrl : completeUrl;
      csrf = opts && opts.csrf ? opts.csrf : csrf;
    }
  };
})(window);
