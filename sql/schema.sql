-- Instituto DOCEO — schema inicial del catálogo / PDV
-- MariaDB / MySQL 8+  |  charset utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('admin','partner','student') NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(120) NOT NULL DEFAULT '',
  last_name_p VARCHAR(120) NOT NULL DEFAULT '',
  last_name_m VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(40) NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partners (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  tier ENUM('cncm','a','b','c') NOT NULL DEFAULT 'c',
  logo_path VARCHAR(255) NULL,
  credit_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_partners_code (code),
  UNIQUE KEY uq_partners_user (user_id),
  CONSTRAINT fk_partners_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  curp VARCHAR(20) NULL,
  birth_date DATE NULL,
  sex ENUM('F','M','X') NULL,
  nationality VARCHAR(80) NULL DEFAULT 'México',
  signature_image_path VARCHAR(255) NULL,
  address_street VARCHAR(190) NULL,
  address_int VARCHAR(40) NULL,
  address_colony VARCHAR(120) NULL,
  address_city VARCHAR(120) NULL,
  address_state VARCHAR(120) NULL,
  address_zip VARCHAR(20) NULL,
  address_country VARCHAR(80) NULL DEFAULT 'México',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_students_user (user_id),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(40) NOT NULL,
  website VARCHAR(255) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_suppliers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  role_label VARCHAR(80) NOT NULL,
  name VARCHAR(190) NOT NULL DEFAULT '',
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_scontact_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  login_url VARCHAR(255) NULL,
  username VARCHAR(190) NULL,
  password_enc TEXT NULL,
  notes TEXT NULL,
  CONSTRAINT fk_saccount_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certifiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(40) NOT NULL,
  logo_path VARCHAR(255) NULL,
  UNIQUE KEY uq_certifiers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NULL,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(40) NOT NULL,
  -- JSON con reglas heredables: docs, schedule, reschedule, moodle, shipping, etc.
  config_json JSON NULL,
  UNIQUE KEY uq_pgroups_code (code),
  CONSTRAINT fk_pgroups_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_group_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  certifier_id BIGINT UNSIGNED NULL,
  type ENUM('certification','course','procedure','shipping','extension','other') NOT NULL DEFAULT 'certification',
  category ENUM('it','english_adult','english_kids','teaching','other') NOT NULL DEFAULT 'other',
  code VARCHAR(60) NOT NULL,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  short_description VARCHAR(255) NULL,
  description TEXT NULL,
  benefits_html TEXT NULL,
  audience ENUM('adult','kids','any') NOT NULL DEFAULT 'any',
  level_label VARCHAR(80) NULL,
  is_star TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  logo_path VARCHAR(255) NULL,
  sample_certificate_path VARCHAR(255) NULL,
  -- precios
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  public_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  catalog_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  price_cncm DECIMAL(12,2) NULL,
  price_partner_a DECIMAL(12,2) NULL,
  price_partner_b DECIMAL(12,2) NULL,
  price_partner_c DECIMAL(12,2) NULL,
  -- moodle / curso
  platform_type ENUM('none','moodle','provider') NOT NULL DEFAULT 'none',
  moodle_course_id INT NULL,
  access_months INT NOT NULL DEFAULT 6,
  extension_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  provider_course_url VARCHAR(255) NULL,
  -- exam / schedule overrides (JSON)
  config_json JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_code (code),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_type (type),
  KEY idx_products_star (is_star, is_active),
  CONSTRAINT fk_products_group FOREIGN KEY (product_group_id) REFERENCES product_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_certifier FOREIGN KEY (certifier_id) REFERENCES certifiers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_star TINYINT(1) NOT NULL DEFAULT 0,
  public_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  catalog_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  price_cncm DECIMAL(12,2) NULL,
  price_partner_a DECIMAL(12,2) NULL,
  price_partner_b DECIMAL(12,2) NULL,
  price_partner_c DECIMAL(12,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_combos_code (code),
  UNIQUE KEY uq_combos_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combo_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  combo_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_combo_product (combo_id, product_id),
  CONSTRAINT fk_citem_combo FOREIGN KEY (combo_id) REFERENCES combos(id) ON DELETE CASCADE,
  CONSTRAINT fk_citem_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discount_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  type ENUM('promo_doceo','partner','partner_seasonal','campaign') NOT NULL,
  partner_id BIGINT UNSIGNED NULL,
  -- discount_mode: to_public (baja al público), percent, fixed, partner_public (alumno paga público)
  discount_mode ENUM('to_public','percent','fixed','partner_public') NOT NULL DEFAULT 'to_public',
  discount_value DECIMAL(12,2) NULL,
  applies_to_combos TINYINT(1) NOT NULL DEFAULT 1,
  combo_override_json JSON NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_discount_codes (code),
  CONSTRAINT fk_dcode_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pipeline_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(190) NOT NULL,
  product_type ENUM('certification','course','procedure','shipping','extension','other') NOT NULL,
  UNIQUE KEY uq_pipeline_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pipeline_steps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pipeline_template_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(60) NOT NULL,
  label VARCHAR(190) NOT NULL,
  actor ENUM('system','admin','student','partner','provider') NOT NULL DEFAULT 'admin',
  sort_order INT NOT NULL DEFAULT 0,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_pstep (pipeline_template_id, code),
  CONSTRAINT fk_pstep_tpl FOREIGN KEY (pipeline_template_id) REFERENCES pipeline_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  matricula VARCHAR(40) NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  partner_id BIGINT UNSIGNED NULL,
  discount_code_id BIGINT UNSIGNED NULL,
  combo_id BIGINT UNSIGNED NULL,
  status ENUM('draft','awaiting_docs','awaiting_payment','payment_review','paid','cancelled','refunded') NOT NULL DEFAULT 'draft',
  payment_method ENUM('none','openpay_spei','openpay_card','openpay_store','transfer_proof','partner_account','credit') NOT NULL DEFAULT 'none',
  currency CHAR(3) NOT NULL DEFAULT 'MXN',
  catalog_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  charged_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  card_msi_months TINYINT UNSIGNED NULL,
  partner_price_amount DECIMAL(12,2) NULL,
  partner_credit_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  openpay_charge_id VARCHAR(80) NULL,
  openpay_clabe VARCHAR(30) NULL,
  openpay_store_reference VARCHAR(50) NULL,
  openpay_barcode_url VARCHAR(512) NULL,
  payment_proof_path VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_purchases_matricula (matricula),
  KEY idx_purchases_student (student_user_id),
  KEY idx_purchases_partner (partner_id),
  KEY idx_purchases_status (status),
  CONSTRAINT fk_purchases_student FOREIGN KEY (student_user_id) REFERENCES users(id),
  CONSTRAINT fk_purchases_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchases_dcode FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchases_combo FOREIGN KEY (combo_id) REFERENCES combos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  unit_public_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_charged_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_pitem_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_pitem_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trackings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  purchase_item_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  partner_id BIGINT UNSIGNED NULL,
  pipeline_template_id BIGINT UNSIGNED NULL,
  current_step_code VARCHAR(60) NULL,
  status ENUM('open','waiting_admin','waiting_student','waiting_partner','waiting_provider','completed','cancelled') NOT NULL DEFAULT 'open',
  exam_date DATE NULL,
  exam_time TIME NULL,
  exam_date_2 DATE NULL,
  exam_time_2 TIME NULL,
  folio VARCHAR(80) NULL,
  access_key VARCHAR(120) NULL,
  zoom_url VARCHAR(255) NULL,
  extra_json JSON NULL,
  moodle_username VARCHAR(80) NULL,
  moodle_password VARCHAR(80) NULL,
  moodle_access_starts_at DATETIME NULL,
  moodle_access_ends_at DATETIME NULL,
  cenni_folio VARCHAR(80) NULL,
  results_url VARCHAR(255) NULL,
  results_level VARCHAR(40) NULL,
  results_score DECIMAL(8,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_trackings_status (status),
  KEY idx_trackings_exam (exam_date),
  KEY idx_trackings_student (student_user_id),
  KEY idx_trackings_partner (partner_id),
  KEY idx_trackings_product (product_id),
  CONSTRAINT fk_track_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_track_pitem FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_track_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_track_student FOREIGN KEY (student_user_id) REFERENCES users(id),
  CONSTRAINT fk_track_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL,
  CONSTRAINT fk_track_pipeline FOREIGN KEY (pipeline_template_id) REFERENCES pipeline_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  media_type ENUM('image','video') NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  caption VARCHAR(255) NULL,
  storage_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_product_media_product (product_id, is_active, sort_order),
  CONSTRAINT fk_product_media_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracking_step_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_id BIGINT UNSIGNED NOT NULL,
  step_code VARCHAR(60) NOT NULL,
  note TEXT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tslog_tracking FOREIGN KEY (tracking_id) REFERENCES trackings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_id BIGINT UNSIGNED NULL,
  purchase_id BIGINT UNSIGNED NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  doc_type VARCHAR(60) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(255) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_docs_status (status),
  CONSTRAINT fk_docs_tracking FOREIGN KEY (tracking_id) REFERENCES trackings(id) ON DELETE CASCADE,
  CONSTRAINT fk_docs_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_docs_student FOREIGN KEY (student_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_lots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL DEFAULT '',
  purchased_at DATE NULL,
  cost_total DECIMAL(12,2) NULL,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ilot_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lot_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  code_primary VARCHAR(120) NOT NULL,
  code_secondary VARCHAR(120) NULL,
  code_extra VARCHAR(120) NULL,
  meta_json JSON NULL,
  status ENUM('available','assigned','expired','void') NOT NULL DEFAULT 'available',
  assigned_tracking_id BIGINT UNSIGNED NULL,
  assigned_at DATETIME NULL,
  expires_at DATE NULL,
  UNIQUE KEY uq_inv_primary (product_id, code_primary),
  KEY idx_inv_status (product_id, status),
  CONSTRAINT fk_icode_lot FOREIGN KEY (lot_id) REFERENCES inventory_lots(id) ON DELETE CASCADE,
  CONSTRAINT fk_icode_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_icode_tracking FOREIGN KEY (assigned_tracking_id) REFERENCES trackings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  trigger_mode ENUM('automatic','manual') NOT NULL DEFAULT 'manual',
  required_fields_json JSON NULL,
  include_partner_logo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_mail_tpl (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mail_template_id BIGINT UNSIGNED NULL,
  purchase_id BIGINT UNSIGNED NULL,
  tracking_id BIGINT UNSIGNED NULL,
  to_email VARCHAR(190) NOT NULL,
  cc_email VARCHAR(255) NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NULL,
  status ENUM('sent','failed') NOT NULL,
  error_message TEXT NULL,
  triggered_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mail_logs_created (created_at),
  CONSTRAINT fk_mlog_tpl FOREIGN KEY (mail_template_id) REFERENCES mail_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS export_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(190) NOT NULL,
  supplier_id BIGINT UNSIGNED NULL,
  file_type ENUM('xlsx','csv') NOT NULL DEFAULT 'xlsx',
  storage_path VARCHAR(255) NOT NULL,
  delivery ENUM('email_attach','download') NOT NULL DEFAULT 'download',
  batch_by ENUM('none','exam_date') NOT NULL DEFAULT 'none',
  mapping_json JSON NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_export_tpl (code),
  CONSTRAINT fk_export_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(190) NOT NULL,
  supplier_id BIGINT UNSIGNED NULL,
  file_type ENUM('xlsx','csv') NOT NULL DEFAULT 'csv',
  match_field VARCHAR(60) NOT NULL DEFAULT 'matricula',
  mapping_json JSON NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_import_tpl (code),
  CONSTRAINT fk_import_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  direction ENUM('to_student','to_partner','from_student','from_partner') NOT NULL DEFAULT 'to_student',
  student_user_id BIGINT UNSIGNED NULL,
  partner_id BIGINT UNSIGNED NULL,
  status ENUM('quoted','awaiting_payment','paid','label_created','in_transit','delivered','cancelled') NOT NULL DEFAULT 'quoted',
  carrier VARCHAR(80) NULL,
  service VARCHAR(120) NULL,
  envia_rate DECIMAL(12,2) NULL,
  charged_amount DECIMAL(12,2) NULL,
  tracking_number VARCHAR(120) NULL,
  label_url VARCHAR(255) NULL,
  address_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ship_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ship_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipment_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  tracking_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NULL,
  CONSTRAINT fk_sitem_ship FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  CONSTRAINT fk_sitem_track FOREIGN KEY (tracking_id) REFERENCES trackings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Ajustes por defecto
INSERT INTO settings (setting_key, setting_value) VALUES
  ('catalog_markup_percent', '10'),
  ('shipping_fixed_price', '300'),
  ('shipping_overage_percent', '50'),
  ('default_course_extension_percent', '50'),
  ('default_student_password', 'Doceo*1234')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
