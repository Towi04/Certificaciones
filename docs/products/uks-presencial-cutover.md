# UKS ELeT: cutover de online (folio/clave) → presencial por sedes

UKS avisó que dejará de aplicar ELeT en línea y pasará a **sedes presenciales**
(similar a Cambridge con convocatorias). Mientras dura la migración del proveedor,
**seguimos con el flujo actual** (ventana de horarios + folio/clave del día).

Este documento es la checklist para el día del cambio, sin tocar producción antes.

## Qué ya soporta el PDV (sin cambiar UKS aún)

| Pieza | Estado |
|-------|--------|
| Modo agenda `dated_list` (convocatorias con fecha/hora/límite) | Listo (Cambridge) |
| Campos opcionales de sede en cada convocatoria (`venue` / `city` / `address`) | Listo |
| Checkout: alumno elige convocatoria y ve la sede en el selector | Listo |
| Placeholders de correo `{{exam_venue}}`, `{{exam_city}}`, `{{exam_address}}`, `{{exam_venue_line}}` | Listo |
| Flujo actual UKS online (ventana + folio/clave + CSV) | Sin cambios |

## Qué NO hay que hacer todavía

- No cambiar el grupo `uks-elet` a `dated_list` hasta que UKS cierre online.
- No quitar el paso de folio/clave mientras aún se apliquen exámenes en línea.
- No borrar plantillas `student_elet_exam_access` ni export/import UKS.

## Día del cutover (cuando UKS diga “ya solo presencial”)

### 1) Grupo UKS → Fechas y horarios
1. Modo de agenda → **Lista de convocatorias / sedes**.
2. Cargar sedes/convocatorias, una línea por fecha:

```
YYYY-MM-DD|HH:MM|YYYY-MM-DD|Etiqueta|Nombre sede|Ciudad|Dirección
```

Ejemplo:

```
2026-11-15|10:00|2026-10-20|Noviembre León|Campus Centro|León, Gto.|Av. Ejemplo 123
```

3. En checkout el alumno elige **sede** y luego la convocatoria de esa sede.
4. Actualizar texto de ayuda del checkout (presencial, llevar ID, llegada anticipada, etc.).
5. Ajustar antelación / días según reglas nuevas de UKS.
6. En **Docs → Documentos del alumno**: INE/pasaporte PDF **antes de pagar** (ver `docs/products/student-docs.md`).

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

1. En un grupo de prueba (o Cambridge) carga 2 convocatorias con sede.
2. Compra de prueba → verifica que el selector muestra sede y que el caso guarda
   `extra_json.exam_schedule.venue|city|address`.
3. Envía un correo de prueba con `{{exam_venue_line}}`.
4. Cuando UKS confirme fecha de corte, replica la config en `uks-elet`.

## Resumen

- **Hoy:** no cambies nada de UKS; el online sigue igual.
- **Listo desde ya:** cargar sedes vía convocatorias + correos con sede (mismo mecanismo Cambridge).
- **Al cutover:** cambiar modo de agenda del grupo, cargar sedes, quitar folio/clave del progreso y actualizar plantillas.
