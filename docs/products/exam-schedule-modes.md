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

### `dated_list` (Cambridge convocatorias)
- Lista `sessions[]` con `exam_date`, `exam_time`, `registration_deadline`, `label`.
- El alumno elige solo convocatorias con inscripción abierta.
- El admin actualiza la lista cada ~6 meses en el grupo.

## Admin
- **Grupos → Fechas y horarios**: selector de modo + textareas de slots/convocatorias.
- **Seguimiento**: panel «Autorizar fecha» si `extra_json.exam_schedule.status = pending_admin`.

## Checkout API
`GET /api/examen-slots/{slug}` responde `mode`, `sessions` (si aplica), `extraordinary`, `slots`, etc.
