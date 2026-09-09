-- Filtros de catálogo derivados de certificadoras + vínculo a productos.
-- Los filtros se crean/actualizan también en runtime (CatalogFilterRepository::syncCertifierFilters).

INSERT INTO catalog_filters (slug, label, filter_group, sort_order, is_active, show_in_catalog)
SELECT
  CONCAT('cert-', c.code) AS slug,
  c.name AS label,
  'Certificadora' AS filter_group,
  50 + c.id AS sort_order,
  c.is_active AS is_active,
  c.is_active AS show_in_catalog
FROM certifiers c
WHERE c.code IS NOT NULL AND c.code <> ''
  AND NOT EXISTS (
    SELECT 1 FROM catalog_filters cf WHERE cf.slug = CONCAT('cert-', c.code)
  );

INSERT IGNORE INTO product_catalog_filters (product_id, filter_id)
SELECT p.id, cf.id
FROM products p
INNER JOIN certifiers c ON c.id = p.certifier_id
INNER JOIN catalog_filters cf
  ON cf.slug = CONCAT('cert-', c.code)
 AND cf.filter_group = 'Certificadora';
