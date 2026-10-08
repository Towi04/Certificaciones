-- iTEP usa inventario de folio/clave; quitar reglamento+firma legado del expediente.
UPDATE product_groups
SET config_json = JSON_SET(
  COALESCE(config_json, JSON_OBJECT()),
  '$.registration_docs',
  JSON_ARRAY()
)
WHERE code = 'itep-exams'
  AND (
    JSON_EXTRACT(config_json, '$.registration_docs') IS NULL
    OR JSON_LENGTH(JSON_EXTRACT(config_json, '$.registration_docs')) > 0
  );
