-- Tarjetas de producto insertables en plantillas de correo vía {{placeholder}}.
CREATE TABLE IF NOT EXISTS mail_product_cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placeholder VARCHAR(60) NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  layout ENUM('square','wide','row') NOT NULL DEFAULT 'wide',
  badge_mode ENUM('discount','banner','both','none') NOT NULL DEFAULT 'discount',
  badge_text VARCHAR(80) NULL,
  show_description TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_product_cards_placeholder (placeholder),
  KEY idx_mail_product_cards_product (product_id),
  KEY idx_mail_product_cards_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
