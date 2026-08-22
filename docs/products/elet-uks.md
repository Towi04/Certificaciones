# ELET (ELeT) — Ficha de producto

> Fase 0 — Definición acordada. Implementación de checkout/combo pendiente (Fase 1).

## Arquitectura de productos

| Código | Tipo | Nombre comercial | Visible catálogo | Seguimiento |
|--------|------|------------------|------------------|-------------|
| `ELET-UKS` | `certification` | **ELET** | Sí (estrella) | Pipeline certificación UKS |
| `ELET-CENNI` | `procedure` | Trámite CENNI (ELeT) | No (solo add-on) | Pipeline trámite CENNI |

**Combo:** al adquirir ELET-UKS el alumno puede marcar si desea incluir el trámite CENNI **sin costo adicional**. Si lo elige, se crean **dos seguimientos independientes** (certificación + trámite).

## Identidad

- **Nombre comercial:** ELET
- **Proveedor:** UKS
- **Certificador:** UKS (examen) / SEP-CENNI (trámite)
- **Categoría:** `english_adult`
- **Audiencia:** 14 años en adelante
- **Estado:** activo, visible, producto estrella

## Descripción comercial

El ELeT es un examen de acreditación del nivel de inglés computarizado y con resultados inmediatos. Se encuentra alineado al marco común europeo y al programa de Certificación Nacional del Nivel de Idioma (CENNI) de la SEP.

### Características

- Examen de acreditación para personas de **14 años en adelante**
- Reactivos alineados al **Marco Común Europeo (CEFR)**, niveles **A1− a C1+** (CENNI niveles 2–16)
- Inglés adaptado a necesidades de uso global
- **Resultado al instante** al terminar el examen
- Aprobado por **COPEI**
- Duración aproximada: **75 minutos**
- Cuatro secciones: Reading, Listening, Use of English, Writing

### Beneficios

- Resultados inmediatos
- Constancia UKS
- Combo opcional: trámite CENNI sin costo adicional (constancia CENNI validez 1 año)

## Agenda del examen

| Día | Horario |
|-----|---------|
| Lunes–viernes | 10:00 – 17:30 |
| Sábado | 08:00 – 12:00 |

### Reglas de antelación

- **Normal:** agendar con **2 días** de antelación
- **Excepción:** 1 día de antelación si la solicitud es **antes de las 16:00** y **admin autoriza**

## Precios (ELET-UKS)

| Concepto | Monto (MXN) |
|----------|-------------|
| Precio de lista (`catalog_price`) | $1,500 |
| Precio público DOCEO (`public_price`) | $1,350 |
| Costo DOCEO / UKS (`cost_price`) | $846 |
| Partner A | $1,300 |
| Partner B | $1,250 |
| Partner C | $1,200 |
| CNCM | $846 |

**ELET-CENNI:** $0 cuando se incluye como add-on del combo.

## Pipelines

- **ELET-UKS:** `cert_basic` (certificación)
- **ELET-CENNI:** `cenni_doceo` (trámite)

## Pendiente por definir (Fase 0)

- [ ] Campos exactos de checkout (¿CURP, fecha nacimiento, sexo?)
- [ ] Documentos en checkout vs post-pago (INE, acta, etc.)
- [ ] Métodos de pago permitidos (MSI meses, SPEI, OXXO)
- [ ] Si $1,350 es neto DOCEO o bruto alumno (comisión OpenPay)
- [ ] UI del checkbox CENNI en checkout y creación de 2da compra/tracking
- [ ] Pasos finos del pipeline ELET-UKS (¿códigos inventario UKS?)
- [ ] Reglas de venta para partners en este producto
- [ ] Export UKS / lotes de códigos
