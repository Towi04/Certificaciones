# Inventario de códigos (iTEP y similares)

## Idea
Compras lotes al proveedor (p. ej. 10 folios+claves). Los subes en **Admin → Inventario**.
El sistema:

1. Al **confirmar pago** (con fecha de examen) **reserva** folio/clave del stock.
2. Programa el correo de acceso para **N días antes** del examen (default 3).
3. Si el examen es **hoy o en ≤ N días**: asigna (si falta) y envía el correo **ya**, sin admin.
4. Si hay **urgencia** (examen en ≤ ventana de urgencia) y stock 0: **reasigna** un código de un
   alumno con examen lejano (≥ 14 días), lo marca “esperando reposición”, y al subir un lote nuevo
   se lo **reponen** automáticamente.
5. Alerta por correo cuando el stock disponible ≤ umbral.
6. Al cargar **resultados** (+ folio CENNI) se envía `student_results_cenni`.

## Configuración (grupo)
En **Grupos → Fechas y horarios → Inventario**:

- Activar inventario
- Días antes para enviar acceso (3)
- Urgencia ≤ días (cuándo se permite quitar códigos de fechas lejanas)
- Validez alumno 6 meses / proveedor 12 meses
- Reasignación urgente
- Plantillas de acceso y de resultados/CENNI

## Paquete estrella (examen + prep + CENNI)
Si la compra es un **combo**:

- el **curso** Moodle se activa al confirmar pago (flujo normal de cursos)
- el **examen** entra al inventario (reserva códigos + correo N días antes)
- el **trámite CENNI** sigue su pipeline; al publicar resultados del examen se manda el correo
  con nivel/puntaje/URL y folio CENNI

## Operación
1. Compra 10 códigos al proveedor.
2. **Inventario → producto → Subir lote** (`folio,clave` por línea).
3. Cron: `php bin/process-scheduled-mails.php` cada 10–15 min
   (envía los correos de acceso programados).
4. Resultados: en el caso del alumno, panel **Resultados + CENNI (inventario)**.

## Prórroga / caducidad alumno
Los códigos quedan con `expires_at` = asignación + 6 meses (el proveedor suele dar 12).
Si el alumno no presenta, puedes cobrar prórroga (producto `extension`) o liberar el código
y venderlo a otro (reasignación manual o vía urgencia automática).
