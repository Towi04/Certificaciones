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

### `dated_list` (Cambridge / UKS presencial por sedes)
- Lista `sessions[]` con `exam_date`, `exam_time`, `registration_deadline`, `label`
  y opcionales `venue`, `city`, `address` (sede).
- En Admin → Grupos → Fechas: una línea
  `fecha|hora|límite|etiqueta|sede|ciudad|dirección`.
- Checkout: el alumno elige **sede** y luego ve solo las convocatorias abiertas de esa sede.
- API: `GET /api/examen-slots/{slug}?venue_id=…` filtra sesiones; sin `venue_id` lista sedes.
- Al comprar se guarda la sede en `trackings.extra_json.exam_schedule`
  (placeholders `{{exam_venue}}`, `{{exam_city}}`, `{{exam_address}}`, `{{exam_venue_line}}`).
- Cutover UKS online → presencial: ver `docs/products/uks-presencial-cutover.md`.
- Pendiente (fase posterior): reglas recurrentes por sede (p. ej. todos los martes) sin
  teclear cada fecha; hoy se cargan sesiones explícitas en el textarea.

## Admin
- **Grupos → Fechas y horarios**: selector de modo + textareas de slots/convocatorias.
- **Seguimiento**: panel «Autorizar fecha» si `extra_json.exam_schedule.status = pending_admin`.

## Checkout API
`GET /api/examen-slots/{slug}` responde `mode`, `sessions` (si aplica), `extraordinary`, `slots`, etc.
