-- Filtros configurables del catálogo y etiquetas por producto

CREATE TABLE IF NOT EXISTS catalog_filters (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  filter_group VARCHAR(60) NULL DEFAULT 'general',
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  show_in_catalog TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catalog_filters_slug (slug),
  KEY idx_catalog_filters_sort (sort_order, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_catalog_filters (
  product_id BIGINT UNSIGNED NOT NULL,
  filter_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, filter_id),
  KEY idx_pcf_filter (filter_id),
  CONSTRAINT fk_pcf_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pcf_filter FOREIGN KEY (filter_id) REFERENCES catalog_filters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO catalog_filters (slug, label, filter_group, sort_order, is_active, show_in_catalog) VALUES
  ('english_adult', 'Inglés adultos', 'Idioma', 10, 1, 1),
  ('english_kids', 'Inglés menores', 'Idioma', 20, 1, 1),
  ('french_adult', 'Francés adultos', 'Idioma', 25, 1, 1),
  ('it', 'Informática', 'Área', 30, 1, 1),
  ('teaching', 'Enseñanza', 'Área', 40, 1, 1),
  ('other', 'Otros', 'Área', 90, 1, 1);

INSERT IGNORE INTO product_catalog_filters (product_id, filter_id)
SELECT p.id, cf.id
FROM products p
JOIN catalog_filters cf ON cf.slug = p.category
WHERE p.category IN ('it', 'english_adult', 'english_kids', 'teaching', 'other');
