-- Convenios especiales (tipo CNCM): códigos + columnas de precio en products/combos.
-- partners.tier pasa de ENUM fijo a VARCHAR para aceptar nuevos códigos.

CREATE TABLE IF NOT EXISTS partner_special_tiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  price_column VARCHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_partner_special_tiers_code (code),
  UNIQUE KEY uq_partner_special_tiers_col (price_column)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO partner_special_tiers (code, label, price_column, is_active, sort_order)
SELECT 'cncm', 'CNCM', 'price_cncm', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM partner_special_tiers WHERE code = 'cncm');

-- Ampliar partners.tier para códigos nuevos (conserva valores actuales).
ALTER TABLE partners
  MODIFY COLUMN tier VARCHAR(40) NOT NULL DEFAULT 'c';
