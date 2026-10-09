# Política: combo / paquete ELeT + CENNI

## Qué es cada pieza

| Producto | Tipo | Docs | Agenda |
|----------|------|------|--------|
| `ELET-UKS` | Examen | INE **antes de pagar** (grupo `uks-elet`) | Hoy online; cutover → sedes |
| `ELET-CENNI` | Trámite | INE, CURP, solicitud, certificado/constancia **después de pagar** (grupo `uks-elet-cenni`) | N/A |

El precio del ELeT puede **incluir** el derecho a trámite CENNI; el alumno decide si lo usa
después del examen (`starts_after: exam_completed`, plazo típico 15 días).

## Compra

1. **Solo ELeT:** un caso de examen. Docs = INE en checkout. Reglamento en su paso.
2. **Combo / paquete con CENNI:** un pago; se generan los trackings de los ítems del combo.
   - El wizard de agenda/docs del checkout usa el producto de **examen** (INE + fecha/sede).
   - El trámite CENNI **no** pide esos docs en el checkout; aparecen en el portal del caso CENNI.
3. **Solo CENNI** (si se vende aparte): paga sin docs; sube en portal.

## Gates y proveedor

- Gate de aprobación y `require_for_provider_send` aplican **por producto/grupo**.
- Enviar solicitud UKS del **examen** exige INE del examen aprobado (no los docs CENNI).
- El correo `cenni_solicitud` (si se usa) exige los docs del trámite aprobados.

## Operación recomendada

1. Confirmar pago del paquete.
2. Revisar/aprobar **INE del examen** (Operaciones → Docs por revisar).
3. Enviar solicitud al proveedor del examen.
4. Tras el examen, si el alumno inicia CENNI: revisar docs del caso trámite y enviar/tramitar.

## Nota histórica

Antes, parte de la documentación CENNI se subía solo en la plataforma UKS
(`doceo_collects_docs: false`). Con el grupo actual `doceo_collects_docs: true`,
DOCEO puede pedir y aprobar esos archivos en el portal. Si UKS vuelve a capturarlos
solo en su sitio, vacía `registration_docs` del grupo CENNI y documenta el enlace UKS
en el progreso.
