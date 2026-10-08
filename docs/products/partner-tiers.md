# Programa de niveles partner

## Qué hace
- Admin define umbrales de ventas (certificaciones pagadas) para **Bronze / Silver / Gold**.
- Define un **periodo de convenio** (inicio/término). Al cerrar, se evalúa si el partner sube, se mantiene o baja.
- El portal partner muestra progreso del **mes** y del **convenio**, metas y riesgo de bajar.
- Convenios especiales (p. ej. **CNCM**, o cualquier partner con “Participa en programa” desmarcado) **no ven la escala** ni se reevalúan automáticamente.
- Aviso por correo (`partner_low_sales_warning`) a quienes van muy bajos tras N meses del convenio.

## Dónde configurar
- Admin → Partners → **Niveles / metas** (`/admin/partners/niveles`)
- En la misma pantalla: **Convenios especiales** (crear CNCM-like con precio propio)
- Ficha del partner: checkbox de programa + fechas de convenio propias (opcionales)
- Correos → plantilla `partner_low_sales_warning`

## Convenios especiales (tipo CNCM)
- Se crean con código + nombre; el sistema agrega columna `price_special_{codigo}`
  (CNCM conserva `price_cncm`).
- Aparecen en: ficha partner, precios masivos, producto, combo y CSV.
- No participan en la escala Bronze/Silver/Gold ni ven el ranking en el portal.

## Cron
`php bin/process-scheduled-mails.php` también:
1. Evalúa niveles si ya pasó la fecha de término del periodo global.
2. Envía avisos de bajas ventas (una vez por partner/periodo).

## Conteo de ventas
Ítems de compra pagados (`purchases.status = paid`) cuyo producto es `type = certification`, atribuidos al `partner_id` de la compra.
