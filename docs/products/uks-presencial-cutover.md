# UKS ELeT: cutover de online (folio/clave) → presencial por sedes

UKS avisó que dejará de aplicar ELeT en línea y pasará a **sedes presenciales**
(similar a Cambridge con convocatorias). Mientras dura la migración del proveedor,
**seguimos con el flujo actual** (ventana de horarios + folio/clave del día).

Este documento es la checklist para el día del cambio, sin tocar producción antes.

## Qué ya soporta el PDV (sin cambiar UKS aún)

| Pieza | Estado |
|-------|--------|
| Modo agenda `dated_list` (convocatorias con fecha/hora/límite) | Listo (Cambridge) |
| Modo agenda `venue_schedules` (regla por sede: recurring / dated / open_window) | Listo |
| Campos opcionales de sede en cada convocatoria (`venue` / `city` / `address`) | Listo |
| Checkout: alumno elige sede y luego fecha/convocatoria | Listo |
| Placeholders de correo `{{exam_venue}}`, `{{exam_city}}`, `{{exam_address}}`, `{{exam_venue_line}}` | Listo |
| Docs INE antes de pagar + gate de aprobación + `{{doc_ine_url}}` | Listo (Fase 7) |
| Parche seguro `php bin/ensure-cutover-config.php` | Listo |
| Flujo actual UKS online (ventana + folio/clave + CSV) | Sin cambios |

Detalle Fase 7: `docs/products/cutover-phase7.md`.

## Qué NO hay que hacer todavía

- No cambiar el grupo `uks-elet` a `venue_schedules` / `dated_list` hasta que UKS cierre online.
- No quitar el paso de folio/clave mientras aún se apliquen exámenes en línea.
- No borrar plantillas `student_elet_exam_access` ni export/import UKS.

## Día del cutover (cuando UKS diga “ya solo presencial”)

### 1) Grupo UKS → Fechas y horarios
1. Modo de agenda → **Sedes con regla propia** (`venue_schedules`).
   Si UKS solo manda fechas sueltas (sin patrón semanal), puedes usar
   **Lista de convocatorias** (`dated_list`) como Cambridge.
2. Con `venue_schedules` + regla **recurring** (ej. martes 10:00, cierra el miércoles anterior):

```
leon-centro|Campus Centro|León, Gto.|Av. Ejemplo 123|recurring
2|10:00|16|previous_weekday|3|1
```

   Con fechas fijas por sede (`dated`):

```
leon-centro|Campus Centro|León, Gto.|Av. Ejemplo 123|dated
2026-11-15|10:00|2026-10-20|Noviembre
```

3. En checkout el alumno elige **sede** y luego la fecha/convocatoria de esa sede.
4. Actualizar texto de ayuda del checkout (presencial, llevar ID, llegada anticipada, etc.).
5. Ajustar horizonte / deadline / anticipo según reglas nuevas de UKS.
6. En **Docs → Documentos del alumno**: INE/pasaporte PDF **antes de pagar** (ver `docs/products/student-docs.md`).
7. Detalle de modos: `docs/products/exam-schedule-modes.md`.

### 2) Progreso y acciones
1. Quitar o desactivar la acción de **capturar/enviar folio y clave del día**
   (ya no aplica en presencial).
2. Mantener:
   - Confirmación de pago
   - Solicitud a proveedor (si UKS sigue pidiendo aviso/CSV)
   - Confirmación de aplicación del examen
   - Resultados / import CSV
3. Opcional: paso de correo al alumno con sede (`{{exam_venue_line}}`) tras confirmar pago
   o al acercarse la fecha (plantilla nueva o adaptar la de acceso).

### 3) Correos
1. Crear/adaptar plantilla de “cita presencial” (sin folio/clave; con sede/dirección).
2. Retirar del flujo la plantilla `student_elet_exam_access` (folio + clave + URL online)
   cuando ya no haya casos online pendientes.
3. Placeholders útiles:
   - `{{exam_date}}` `{{exam_time}}`
   - `{{exam_venue}}` `{{exam_city}}` `{{exam_address}}`
   - `{{exam_venue_line}}` (todo junto)

### 4) Operación
1. Dejar de capturar folio/clave del día en Operaciones para ELeT.
2. Seguir usando export/import UKS si el proveedor lo mantiene; si cambia el CSV,
   actualizar plantillas en Admin → Exportaciones.
3. Casos **ya comprados online** con fecha futura: cerrarlos con el flujo viejo
   (folio/clave) o reagendarlos a una sede presencial caso por caso.

### 5) Inventario
El inventario automático de códigos **no** es el flujo UKS actual ni el presencial.
No hace falta activarlo para ELeT a menos que UKS pase a venderte lotes de códigos
(como iTEP). En presencial por sede, normalmente **no**.

## Prueba seca (antes del anuncio público)

Checklist completa (matriz A–D, rollback, combo): **`docs/products/cutover-phase8-qa.md`**.

```bash
php bin/ensure-cutover-config.php
php bin/cutover-readiness.php
php bin/cutover-phase8-matrix.php
```

1. En un grupo de prueba (o Cambridge) carga 2 convocatorias con sede.
2. Compra de prueba → verifica que el selector muestra sede y que el caso guarda
   `extra_json.exam_schedule.venue|city|address`.
3. Envía un correo de prueba con `{{exam_venue_line}}` y con `{{doc_ine_url}}` (INE aprobado).
4. Cuando UKS confirme fecha de corte, replica la config en `uks-elet`.

## Resumen

- **Hoy:** no cambies la agenda de UKS a presencial; el online sigue igual.
- **Listo desde ya:** docs INE + gate, sedes (`venue_schedules` / `dated_list`), placeholders.
- **Al cutover:** cambiar modo de agenda del grupo, cargar sedes, quitar folio/clave del progreso y actualizar plantillas.
- **QA / rollback:** `docs/products/cutover-phase8-qa.md`.
