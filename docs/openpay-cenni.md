# OpenPay SPEI + seguimiento CENNI

## Sandbox vs producción

Con `OPENPAY_SANDBOX=true` (y llaves de sandbox) OpenPay **no** entrega CLABEs bancarias reales.
Es normal ver algo como `000000000000000001`, convenio CIE de prueba (`1411217`) y referencias largas de laboratorio.
Un SPEI con dinero real **no** liquidará esos cargos de prueba.

La salud del sistema en verde solo confirma que la API responde con tus llaves actuales.
Para cobrar de verdad:

1. Cuenta OpenPay en producción + llaves live en `.env` (`OPENPAY_SANDBOX=false`, `OPENPAY_API_BASE=https://api.openpay.mx/v1`).
2. Nombre comercial / beneficiario en el dashboard OpenPay: **Instituto DOCEO** (afecta el PDF que genera OpenPay).
3. Webhook productivo registrado (ver abajo).

En el PDV el beneficiario mostrado en correos y en la ficha Doceo (`/pago/spei?id=…`) sale de `OPENPAY_BENEFICIARY_NAME` (por defecto *Instituto DOCEO*).

## Configurar el webhook (recomendado)

Endpoint del PDV:

```
POST https://pdv.institutodoceo.com/webhooks/openpay
```

(`APP_URL` + `/webhooks/openpay`. También responde `GET` con un ping JSON.)

### Desde el panel (más fácil)

1. Despliega el código y confirma que `GET /webhooks/openpay` responde `{"ok":true,...}`.
2. Entra a **Admin → OpenPay**.
3. Pulsa **Registrar webhook en OpenPay**.
4. OpenPay hace un POST de verificación a tu URL; si responde 200, el webhook suele quedar `verified`.
5. En **Salud** la tarjeta “OpenPay webhook” debe quedar OK.

Eventos que registra el botón: `charge.succeeded`, `spei.received`, `charge.created`, `charge.failed`, `charge.cancelled`.

Opcional en `.env` (Basic Auth del webhook):

```
OPENPAY_WEBHOOK_USER=...
OPENPAY_WEBHOOK_PASSWORD=...
```

Si los defines, el botón los envía a OpenPay y el endpoint del PDV los exige.

### Desde el dashboard OpenPay

1. Dashboard → Webhooks → Agregar → URL anterior.
2. OpenPay envía `{"type":"verification","verification_code":"..."}`.
3. El código aparece en **Admin → OpenPay** (y en `storage/logs/openpay-webhook-verification.txt`).
4. En el dashboard: Verificar e introduce el código.

### Qué hace el PDV al recibir el pago

`charge.succeeded` / `spei.received` → busca el caso por `openpay_charge_id` u `order_id`, marca pagado y envía plantilla `pago_confirmado`.

## CLABE única por alumno

Al abrir un caso (vitrina o admin) el sistema crea un cargo OpenPay `method=bank_account` y guarda:

- CLABE, banco, convenio/referencia, monto, charge id, PDF SPEI (OpenPay)
- Ficha propia Doceo con logo: `/pago/spei?id={caseId}` (imprimible / guardar PDF del navegador)

Monto = `public_price` + fee CENNI (si aplica y no está incluido) + aplicación extraordinaria (si aplica).

Migraciones: `sql/migration_openpay_cenni.sql`, luego `sql/migration_branding_spei.sql`.

## CENNI: dos caminos

| `cenni_process` | Producto típico | Docs del alumno |
|-----------------|-----------------|-----------------|
| `uks_external` | ELET | En plataforma UKS (enlace/QR de la constancia). Doceo solo monitorea y avisa. |
| `doceo_managed` | iTEP, Oxford, etc. | Subida en portal alumno → Doceo gestiona ante SEP |
| `none` | Sin CENNI | — |

En el caso admin puedes actualizar estatus (`awaiting_uks_upload` → … → `issued`) y notificar al alumno. Al marcar `issued` se usa la plantilla `cenni_emitido` (agradecimiento + invitación a la plataforma).
