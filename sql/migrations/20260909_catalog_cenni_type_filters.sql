-- Filtros de catálogo: tipos de documento CENNI.
-- También se aseguran en runtime (CatalogFilterRepository::ensureCenniTypeFilters).

INSERT INTO catalog_filters (slug, label, filter_group, sort_order, is_active, show_in_catalog)
SELECT v.slug, v.label, v.filter_group, v.sort_order, 1, 1
FROM (
  SELECT 'cenni-constancia' AS slug, 'Constancia CENNI' AS label, 'CENNI' AS filter_group, 5 AS sort_order
  UNION ALL
  SELECT 'cenni-certificado', 'Certificado CENNI', 'CENNI', 6
  UNION ALL
  SELECT 'cenni-diploma', 'Diploma CENNI', 'CENNI', 7
) AS v
WHERE NOT EXISTS (
  SELECT 1 FROM catalog_filters cf WHERE cf.slug = v.slug
);
