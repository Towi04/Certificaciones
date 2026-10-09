# Fase 8 — QA cutover e integración (última)

Checklist operativa para validar el paquete UKS presencial / Cambridge por sede / CENNI
antes del lunes de cutover.

## Herramientas automáticas

```bash
# Parche seguro de config faltante (Fase 7)
php bin/ensure-cutover-config.php

# Readiness: motores + grupos + plantillas
php bin/cutover-readiness.php

# Matriz A–D a nivel servicios (lógica; marca MANUAL lo que pide UI/BD)
php bin/cutover-phase8-matrix.php
```

Exit `0` en readiness + matrix = listo a nivel código/config.
Los pasos marcados `MANUAL` en la matrix (correo real, approve UI, compra combo)
se ejecutan en staging/prod con la checklist abajo.

## Matriz QA manual

### A) UKS ELeT — docs + gate (hoy, aún online)

| # | Caso | Esperado |
|---|------|----------|
| A1 | Checkout **sin** INE | No deja pagar / confirmar |
| A2 | Checkout **con** INE + reglamento | Compra OK; doc `ine` queda `pending` |
| A3 | Operaciones → enviar solicitud UKS **antes** de aprobar INE | Error de gate / doc no aprobado |
| A4 | Aprobar INE en el caso → reenviar solicitud | Correo con `{{doc_ine_url}}` / `{{student_docs_html}}` |
| A5 | Rechazar INE con motivo | Alumno recibe `student_document_rejected`; puede re-subir → `pending` |
| A6 | Operaciones → **Docs por revisar** | Aparece el caso con badge Docs |

### B) Cambridge — sede A vs B

| # | Caso | Esperado |
|---|------|----------|
| B1 | Grupo `cambridge-fixed` (o prueba) con **2 sedes** | Checkout: elige sede → solo convocatorias de esa sede |
| B2 | Compra sede A | `extra_json.exam_schedule` tiene `venue` / `city` / `address` / `venue_id` |
| B3 | Compra sede B | Misma estructura; sede distinta a B2 |
| B4 | Correo prueba con `{{exam_venue_line}}` | Muestra sede correcta |

### C) CENNI — post-pago

| # | Caso | Esperado |
|---|------|----------|
| C1 | Comprar trámite CENNI (solo) | Paga **sin** subir docs en checkout |
| C2 | Portal alumno | Pide INE, CURP, solicitud, certificado/constancia |
| C3 | Subir docs → admin aprueba | Contador X/Y llega a listo |
| C4 | Enviar correo `cenni_solicitud` (o paso proveedor) | Links `{{doc_*_url}}` a docs **aprobados** |

### D) Combo examen + CENNI

Ver política en `docs/products/combo-elet-cenni.md`.

| # | Caso | Esperado |
|---|------|----------|
| D1 | Compra combo ELeT (+ CENNI si aplica) | Un pago; se crean casos según items del combo |
| D2 | Docs del **examen** | INE before_payment (grupo UKS) |
| D3 | Docs del **CENNI** | After_payment en el caso del trámite |
| D4 | Gate proveedor del examen | Solo exige docs del producto examen (INE), no los del CENNI |

## Checklist ops — día del cutover UKS presencial

1. [ ] `php bin/ensure-cutover-config.php` y `php bin/cutover-readiness.php` en verde
2. [ ] Backup mental/nota de la agenda actual de `uks-elet` (modo + JSON)
3. [ ] Grupo UKS → Fechas → `venue_schedules` (o `dated_list`) + sedes reales
4. [ ] Texto ayuda checkout presencial
5. [ ] Docs: INE before_payment + gate ON; Reglamento en su pestaña
6. [ ] Progreso: desactivar folio/clave del día si ya no aplica
7. [ ] Plantilla cita presencial (sede) lista; `uks_solicitud` con INE
8. [ ] Smoke A2–A4 + B1–B2 en staging/prod controlado
9. [ ] Comunicar a ops: pestaña **Docs por revisar** antes de enviar a UKS
10. [ ] Casos online futuros: folio/clave viejo o reagendar uno a uno

Detalle de agenda: `docs/products/uks-presencial-cutover.md`.

## Rollback (si UKS sigue online o hay incidente)

1. Grupo `uks-elet` → Fechas → volver modo **Ventana** (`window`) con Lun–Vie/sábado y antelación 2 días  
   (o restaurar el JSON de agenda que anotaste en el paso 2).
2. Reactivar paso/acción de **folio y clave** en Progreso si lo quitaste.
3. Mantener INE + gate (no hace daño en online; solo añade control de calidad).
4. Plantilla `student_elet_exam_access` sigue disponible para casos online.
5. No borres sedes del textarea: puedes dejarlas guardadas y cambiar solo el `schedule_mode`.

## Criterios de aceptación del plan maestro

- [x] Admin configura docs sin JSON (UI)
- [x] UKS/Cambridge: sin INE no paga
- [x] CENNI: paga y sube después
- [x] `{{doc_ine_url}}` en plantilla proveedor
- [x] Checkout sede → disponibilidad
- [x] Gate ops docs aprobados
- [x] Matriz lógica A–D (`php bin/cutover-phase8-matrix.php`)
- [ ] Pasos MANUAL de la matriz (correo/UI/compra) el día del cutover

## Fases

| Fase | Estado |
|------|--------|
| 0–7 + gate | Hechas |
| **8 QA** | Herramientas + matriz lógica listas; MANUAL el día del cutover |
