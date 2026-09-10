-- Documentos internos del proveedor (contratos, tarifas, guías, etc.).
CREATE TABLE IF NOT EXISTS supplier_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  original_name VARCHAR(255) NOT NULL DEFAULT '',
  storage_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL DEFAULT '',
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_supplier_documents_supplier (supplier_id, created_at),
  CONSTRAINT fk_supplier_documents_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
