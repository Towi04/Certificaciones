-- Hotfix: INE antes de pagar (UKS / Cambridge) y docs CENNI después de pagar.
-- Idempotente: solo escribe si el grupo aún no tiene required_docs/registration_docs
-- con código "ine" (o student_docs_timing ya definido).

-- UKS ELeT: before_payment + INE
UPDATE product_groups
SET config_json = JSON_SET(
  COALESCE(config_json, JSON_OBJECT()),
  '$.student_docs_timing', 'before_payment',
  '$.required_docs', JSON_ARRAY(
    JSON_OBJECT(
      'code', 'ine',
      'label', 'INE o pasaporte escaneado (PDF)',
      'description', 'PDF con ambos lados, nítido y completo. No fotos borrosas ni recortes.',
      'required', true,
      'accept', '.pdf',
      'include_in_provider_mail', true,
      'require_for_provider_send', true
    )
  ),
  '$.registration_docs', JSON_ARRAY()
)
WHERE code IN ('uks-elet')
  AND (
    JSON_EXTRACT(config_json, '$.student_docs_timing') IS NULL
    OR (
      JSON_SEARCH(JSON_EXTRACT(config_json, '$.required_docs'), 'one', 'ine', NULL, '$[*].code') IS NULL
      AND JSON_SEARCH(JSON_EXTRACT(config_json, '$.registration_docs'), 'one', 'ine', NULL, '$[*].code') IS NULL
    )
  );

-- Cambridge (códigos habituales del seeder): before_payment + INE
UPDATE product_groups
SET config_json = JSON_SET(
  COALESCE(config_json, JSON_OBJECT()),
  '$.student_docs_timing', 'before_payment',
  '$.required_docs', JSON_ARRAY(
    JSON_OBJECT(
      'code', 'ine',
      'label', 'INE o pasaporte escaneado (PDF)',
      'description', 'PDF con ambos lados, nítido y completo. No fotos borrosas ni recortes.',
      'required', true,
      'accept', '.pdf',
      'include_in_provider_mail', true,
      'require_for_provider_send', true
    )
  ),
  '$.registration_docs', JSON_ARRAY()
)
WHERE code IN ('cambridge-fixed', 'cambridge-flexible', 'cambridge', 'lf-cambridge')
  AND (
    JSON_EXTRACT(config_json, '$.student_docs_timing') IS NULL
    OR (
      JSON_SEARCH(JSON_EXTRACT(config_json, '$.required_docs'), 'one', 'ine', NULL, '$[*].code') IS NULL
      AND JSON_SEARCH(JSON_EXTRACT(config_json, '$.registration_docs'), 'one', 'ine', NULL, '$[*].code') IS NULL
    )
  );

-- CENNI ELeT: after_payment + paquete básico
UPDATE product_groups
SET config_json = JSON_SET(
  COALESCE(config_json, JSON_OBJECT()),
  '$.student_docs_timing', 'after_payment',
  '$.required_docs', JSON_ARRAY(),
  '$.registration_docs', JSON_ARRAY(
    JSON_OBJECT(
      'code', 'ine',
      'label', 'INE / pasaporte',
      'description', 'Ambos lados en un PDF legible.',
      'required', true,
      'accept', '.pdf',
      'include_in_provider_mail', true,
      'require_for_provider_send', true
    ),
    JSON_OBJECT(
      'code', 'birth_certificate',
      'label', 'Acta de nacimiento',
      'description', 'PDF legible del acta.',
      'required', true,
      'accept', '.pdf',
      'include_in_provider_mail', true,
      'require_for_provider_send', false
    ),
    JSON_OBJECT(
      'code', 'photo',
      'label', 'Fotografía',
      'description', 'Fondo blanco, rostro visible (JPG/PNG).',
      'required', true,
      'accept', '.jpg,.jpeg,.png',
      'include_in_provider_mail', true,
      'require_for_provider_send', false
    )
  ),
  '$.doceo_collects_docs', true
)
WHERE code IN ('uks-elet-cenni')
  AND JSON_EXTRACT(config_json, '$.student_docs_timing') IS NULL
  AND (
    JSON_EXTRACT(config_json, '$.registration_docs') IS NULL
    OR JSON_LENGTH(JSON_EXTRACT(config_json, '$.registration_docs')) = 0
  );
