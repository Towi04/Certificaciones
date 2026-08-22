# ELET (ELeT) — Ficha de producto (Fase 0 completa)

## Arquitectura de productos

| Código | Tipo | Nombre comercial | Catálogo | Seguimiento |
|--------|------|------------------|----------|-------------|
| `ELET-UKS` | `certification` | **ELET** | Sí (estrella) | Pipeline `elet_uks` |
| `ELET-CENNI` | `procedure` | Trámite CENNI (ELeT) | No | Pipeline `elet_cenni_uks` |

**CENNI incluido en el precio** del ELET, pero el alumno **decide después del examen** si usa el trámite UKS o lo omite (a veces prefiere hacerlo por su cuenta). Si no inicia el trámite en **15 días** posteriores al examen, se cancela automáticamente.

## Identidad

- **Nombre comercial:** ELET
- **Proveedor examen:** UKS
- **Trámite CENNI:** lo realiza **UKS** (no DOCEO)
- **Certificador:** UKS / SEP-CENNI
- **Categoría:** `english_adult` · **Audiencia:** 14+ · **Estrella:** sí

## Descripción comercial

El ELeT es un examen de acreditación del nivel de inglés computarizado y con resultados inmediatos. Alineado al marco común europeo y al programa CENNI de la SEP.

**Características:** CEFR A1− a C1+ (CENNI 2–16), 75 min, 4 secciones (Reading, Listening, Use of English, Writing), resultados inmediatos, aprobado COPEI.

**Beneficios:** resultados inmediatos, constancia UKS, opción de trámite CENNI sin costo adicional (constancia 1 año).

## Registro (checkout)

### Campos obligatorios

| Campo | Obligatorio |
|-------|-------------|
| Correo | Sí |
| Nombre(s) | Sí |
| Apellido paterno | Sí |
| Apellido materno | No |
| Teléfono | Sí |

No se piden otros campos (sin CURP, fecha de nacimiento, etc.).

### Reglamento (antes del pago)

- **Único documento requerido** en el registro.
- El alumno debe leer y **firmar digitalmente** el reglamento **antes** de completar la compra.
- La **firma va como última página del PDF** (un solo archivo).
- Plantilla en sistema: `/assets/reglamentos/elet-reglamento.pdf`
- Fuente original: [Google Drive](https://drive.google.com/file/d/1sfP7zSPlqqpBdYaHUmz-_kM_BijRZDHW/view?usp=sharing)

**No se puede registrar sin reglamento firmado.**

## Examen

- **Modalidad:** solo online.
- **URL examen (todos):** https://exam.elet.com.mx/
- **Sin Zoom.** Admin captura **folio** y **clave del día**; se envían al alumno junto con el link antes de la fecha.
- El alumno **elige fecha y hora al comprar**.
- **Horarios (bloques de 30 min):**
  - Lunes–viernes: 10:00 – 17:30
  - Sábado: 08:00 – 12:00
- **Antelación:** 2 días (excepción 1 día si solicita antes de 16:00 y admin autoriza).
- **Reagenda:** permitida antes o después de la fecha si no se presentó o no podrá presentarse.

## Trámite CENNI (post-examen)

- Pipeline **aparte**, inicia **después del examen** si el alumno lo desea.
- DOCEO **no recibe** INE/CURP/solicitud CENNI; el alumno los sube en la **plataforma UKS** (enlace único por alumno).
- Admin importa folio CENNI desde **CSV de UKS** y lo publica para seguimiento.
- Consulta alumno: https://cennisistema.sep.gob.mx/cenni/consulta/consultaEstatus.jsp
- **Plazo:** 15 días desde el examen para iniciar; si no, cancelación automática.
- Si el alumno omite el trámite, el caso ELET-UKS puede darse por concluido al terminar el examen.

## Precios (ELET-UKS)

| Concepto | Monto (MXN) |
|----------|-------------|
| Lista (`catalog_price`) | $1,500 |
| Público DOCEO (`public_price`) | $1,350 |
| Costo DOCEO | $846 |
| Partner A / B / C | $1,300 / $1,250 / $1,200 |
| CNCM | $846 |

**Precio al alumno incluye comisión OpenPay** según método (SPEI/OXXO/tarjeta) y meses MSI.

## Pagos

| Prioridad | Método |
|-----------|--------|
| 1 (default) | SPEI |
| 2 | OXXO |
| 3 | Tarjeta / MSI |

- **MSI:** 3, 6, 9 y 12 meses (sin monto mínimo; el alumno cubre comisión).
- **Partners:** mismas opciones; normalmente SPEI + comprobante. También pagan comisión OpenPay en MSI.
- **Modelo partner:** paga precio de su nivel; código de descuento al alumno = precio público DOCEO; diferencia = saldo a favor del partner.

## Pipeline ELET-UKS (`elet_uks`)

1. **Registro** — reglamento firmado + datos + pago (checkout)
2. **Confirmación de pago** (admin)
3. **Solicitud a UKS** (admin)
4. **Asignación de códigos** — folio + clave del día (admin)
5. **Publicación de resultados** (admin)
6. **Completado**

## Pipeline CENNI ELET (`elet_cenni_uks`)

1. **Inicio trámite** — alumno decide (≤15 días post-examen)
2. **Documentos en UKS** — enlace único alumno
3. **Folio CENNI** — admin (import CSV UKS)
4. **Seguimiento SEP** — alumno
5. **Completado / cancelado**

## Integraciones

| Integración | ELET |
|-------------|------|
| Moodle | No |
| Inventario códigos | No (UKS asigna por alumno tras registro) |
| Export UKS | Sí — plantilla `uks_elet_registro` (CSV Instituto DOCEO) |
| Import UKS | Sí — plantilla `uks_elet_reporte` (resultados + docs CENNI + folio) |
| Email examen programado | No (accesos van en correo de folio/clave) |
| Email pago confirmado | Sí — al alumno tras confirmar pago |
| Email solicitud UKS | Sí — automático a UKS al confirmar pago |

## Partners

Pueden vender ELET y **todos** los demás productos.

## Export UKS (`uks_elet_registro`)

Plantilla oficial **Plantilla Instituto DOCEO.csv** con columnas:

| Columna | Campo DOCEO |
|---------|-------------|
| Matrícula | `matricula` |
| Apellido Paterno | `last_name_p` |
| Apellido Materno | `last_name_m` |
| Nombre(s) | `first_name` |
| Correo Electrónico | `email` |

**Cuándo exportar:** casos ELET-UKS con pago confirmado en pasos `confirm_pago` o `solicitud_uks`.

**Admin:**
- `/admin/exportaciones` — descarga por lote (fecha de examen) o pendientes
- Desde el caso del alumno — botón «Descargar CSV UKS (este alumno)»

Archivo de referencia: `storage/templates/uks_elet_registro.csv`

## Import UKS (`uks_elet_reporte`)

Plantilla **Reporte Instituto DOCEO ELET** (CSV que descargas de UKS). Ejemplo: `storage/templates/uks_elet_reporte_ejemplo.csv`

**Datos que importa (por matrícula):**

| Columna UKS | Uso en DOCEO |
|-------------|--------------|
| Folio | Folio UKS del examen |
| Realizado | Fecha examen realizado |
| Nivel Alcanzado / Puntaje | Resultados |
| Certificado | URL del certificado |
| Documentación | Estatus general docs CENNI |
| Doc. Solicitud Cenni / CURP / INE | Aprobado ✔ o rechazado |
| Folio CENNI | Folio para consulta SEP (~15 días) |

**Al importar:**
- Actualiza el caso ELET-UKS (y ELET-CENNI si existe)
- El alumno ve resultados y estatus CENNI en su panel
- Correo al alumno si cambian documentos CENNI o se publica folio CENNI

**Admin:** `/admin/exportaciones` → sección «Importar reporte UKS»

## Implementación pendiente (Fase 1+)

- [x] UI checkout: reglamento PDF + firma digital + append última página
- [x] Selector fecha/hora (slots 30 min) en checkout
- [ ] SPEI como método default en UI
- [ ] Creación tracking CENNI post-examen + plazo 15 días
- [x] Campos admin: folio, clave del día (+ correo accesos examen)
- [x] Export plantilla UKS (`uks_elet_registro`)
- [x] Import reporte UKS (`uks_elet_reporte`) + panel alumno CENNI
- [ ] Reagenda alumno
