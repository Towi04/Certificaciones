-- Varias certificadoras por proveedor (ETC→Microsoft/Adobe/…, Creative→Cambridge/Michigan, etc.)
CREATE TABLE IF NOT EXISTS supplier_certifiers (
  supplier_id BIGINT UNSIGNED NOT NULL,
  certifier_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, certifier_id),
  KEY idx_supplier_certifiers_certifier (certifier_id),
  CONSTRAINT fk_sc_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sc_certifier FOREIGN KEY (certifier_id) REFERENCES certifiers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
