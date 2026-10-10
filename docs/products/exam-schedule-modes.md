# Modos de agenda de examen

La configuración vive en `product_groups.config_json` → `schedule.mode`.
Siempre se guarda en `trackings.exam_date` / `exam_time` (placeholders `{{exam_date}}` / `{{exam_time}}`).

## Modos

### `window` (default · ELeT / Cambridge flexible)
- Días + ventana horaria continua + `min_advance_days`.
- Cambridge flexible sugerido: Lun–Vie 09:00–18:00, anticipo 15 días.
- El checkout **no** permite menos anticipo. El admin puede fijar cualquier fecha en el detalle del caso.

### `fixed_slots` (TOEFL)
- Horarios fijos por día de semana (`fixed_slots`: `dow` + `time`).
- Seed: sábados 11:00 y 13:00.
- Opcional `extraordinary`: el alumno pide otra fecha, paga `surcharge_amount` y queda `waiting_admin` hasta autorizar.

### `dated_list` (Cambridge fechas fijas / convocatorias con sede)
- Lista `sessions[]` con `exam_date`, `exam_time`, `registration_deadline`, `label`
  y opcionales `venue`, `city`, `address` (sede).
- En Admin → Grupos → Fechas: una línea
  `fecha|hora|límite|etiqueta|sede|ciudad|dirección`.
- Checkout: el alumno elige **sede** y luego ve solo las convocatorias abiertas de esa sede.
- API: `GET /api/examen-slots/{slug}?venue_id=…` filtra sesiones; sin `venue_id` lista sedes.
- Al comprar se guarda la sede en `trackings.extra_json.exam_schedule`
  (placeholders `{{exam_venue}}`, `{{exam_city}}`, `{{exam_address}}`, `{{exam_venue_line}}`).

### `venue_schedules` (UKS / sedes con regla propia)
- Lista `venues[]`; cada sede tiene `rule.type`:
  - `recurring` — DOWs + horas **fijas** o **ventana** + horizonte + deadline.
    - Fija: `2|10:00|16|previous_weekday|3|1` → martes 10:00; cierra el miércoles anterior.
    - Ventana (alumno elige hora): `2|10:00-15:00|16|previous_weekday|3|1|30`
      → martes 10:00–15:00, slots de 30 min. El API expone `venue_rule_type=recurring_window`.
    - DOW: `0`=dom … `6`=sáb (`1`=lun, `2`=mar).
    - Deadline alternativo: `days_before|0|8` = cierra 8 días antes del examen.
  - `dated` — convocatorias explícitas **sin** repetir sede en cada línea.
  - `open_window` — días + ventana Lun–Vie / sábado + `min_advance_days` + `slot_minutes`.
- Admin → Grupos → Fechas: modo **Sedes con regla propia**; bloques separados por línea en blanco
  (ver ayuda del textarea o `ProductAdminService::venuesToText`).
- Checkout: sede primero; según la regla muestra convocatorias (recurring fijo/dated)
  o fecha+hora (`open_window` / `recurring_window`).
- API: `venue_rule_type` en la respuesta; `venue_id` obligatorio al pedir slots de ventana.
- Vacaciones globales DOCEO bloquean fechas en todos los tipos.
- Motor: `VenueScheduleEngine` + `ExamScheduleService::MODE_VENUE_SCHEDULES`.
- Cutover UKS online → presencial: ver `docs/products/uks-presencial-cutover.md`.

## Admin
- **Grupos → Fechas y horarios**: selector de modo + textareas de slots/convocatorias/sedes.
- **Seguimiento**: panel «Autorizar fecha» si `extra_json.exam_schedule.status = pending_admin`.

## Checkout API
`GET /api/examen-slots/{slug}` responde `mode`, `venues`, `venue_rule_type`, `sessions`,
`extraordinary`, `slots`, etc.
