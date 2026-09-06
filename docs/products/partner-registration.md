# Registro de alumnos por partner

El partner **no usa un formulario corto**. Registra alumnos con el **mismo checkout**
que un alumno público (`/adquirir/{slug}`), con precio de su nivel.

## Flujo

1. Portal partner → **Registrar alumno** (o **Catálogo**).
2. Elige el producto en tarjetas (no en un combo box). Si hay combos, se eligen en el paso **Paquete** del checkout.
3. Completa el wizard:
   - Datos del alumno (campos del grupo: CURP, etc. si aplican)
   - Reglamento: **firma digital** (pasa iPad/mouse al alumno) **o** descarga + sube PDF escaneado
   - Agenda de examen (si aplica, con reglas de anticipación)
   - Paquete/combo (si hay ofertas)
   - Pago (transferencia, OXXO, tarjeta/MSI según config del grupo)
4. Al terminar, el partner permanece en su sesión y abre el **caso** con el progreso/pipeline correcto.

## Dónde se configura el proceso

El proceso (campos, reglamento, pagos, pipeline) vive en el **grupo del producto**
(Admin → Grupos), igual que para compra directa del alumno.

## Código promocional del partner

Al **crear o editar** un partner, su `code` se sincroniza automáticamente en
`discount_codes` (`type=partner`, `discount_mode=partner_public`).

| Quién | Qué paga | Crédito al partner |
|-------|----------|--------------------|
| Alumno público con el código del partner en checkout | Precio **público** (igual que promo DOCEO) | `público − precio del nivel del partner` |
| Partner logueado registrando alumno | Precio de su **nivel** | `0` (ya compra al costo) |

El catálogo/setup también hace un backfill de partners existentes
(`PartnerAdminService::syncAllPartnerPromoCodes`). Si falta la fila en
`discount_codes`, el checkout aún acepta el código leyendo `partners.code`.

## Notas

- El endpoint `POST /partner/registrar` quedó deprecado (redirige al catálogo/picker).
- `PartnerRegistrationService` se conserva por compatibilidad, pero el camino recomendado es el checkout completo.
