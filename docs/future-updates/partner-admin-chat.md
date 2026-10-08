# Futura actualización: chat interno Partner ↔ Admin

**Estado:** diferido (no implementar ahora)  
**Prioridad:** baja / backlog  
**Última nota:** 2026-10-08

## Objetivo

Canal de mensajería interna entre el portal partner y el panel admin (equipo DOCEO), para soporte operativo sin depender solo del correo.

## Alcance v1 (cuando se retome)

- Solo **partner ↔ admin**
- Un hilo por partner (`type = partner_admin`)
- Bandeja compartida entre admins (cualquier admin puede leer/responder)
- Texto plano; badges de no leídos; polling ligero (sin WebSockets)
- Sin adjuntos ni aviso por correo en la primera entrega

## Fuera de alcance (fase posterior)

- Alumno ↔ partner
- Alumno ↔ DOCEO
- Adjuntos, asignación a un agente, tickets por tema

## Enfoque técnico (borrador)

Tablas propuestas:

- `support_threads` — `type`, `partner_id`, `student_user_id` (nullable), `status`, `last_message_at`
- `support_messages` — `thread_id`, `sender_user_id`, `sender_role`, `body`, `created_at`
- `support_thread_reads` — `thread_id`, `user_id`, `last_read_at`

Rutas tentativas:

- Partner: `/partner/mensajes` (+ poll JSON)
- Admin: `/admin/mensajes` (inbox) y `/admin/mensajes/{id}` (hilo)

Capas alineadas al repo: migración + `schema.sql`, `SupportChatService`, vistas partner/admin, badges en layouts, tutorial partner `mensajes`.

Diseñar el `type` del hilo desde el día 1 para no rehacer el modelo cuando entren alumnos.

## Criterios de aceptación (v1)

1. Partner envía mensaje → aparece en bandeja admin.
2. Admin responde → partner lo ve en su chat.
3. Badges de no leídos se limpian al abrir/leer.
4. Un partner no ve hilos de otros partners.
5. Sin UI ni rutas de chat para alumnos.

## Riesgos a recordar

- Polling: intervalo 15–20 s y pausar con pestaña oculta.
- Rate limit + límite de caracteres.
- No mezclar con el centro de notificaciones (menú «Mensajes» distinto).

## Relación con trabajo reciente

El centro de notificaciones partner (accesos / resultados) es independiente; este chat sería otro canal de comunicación humana.
