-- Perfil público de escuela / directorio de distribuidores autorizados.

ALTER TABLE partners
  ADD COLUMN directory_logo_path VARCHAR(255) NULL AFTER logo_path,
  ADD COLUMN directory_phone VARCHAR(40) NULL AFTER directory_logo_path,
  ADD COLUMN directory_address VARCHAR(255) NULL AFTER directory_phone,
  ADD COLUMN directory_maps_url VARCHAR(512) NULL AFTER directory_address,
  ADD COLUMN directory_description VARCHAR(500) NULL AFTER directory_maps_url,
  ADD COLUMN publish_status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft'
    AFTER directory_description,
  ADD COLUMN publish_requested_at DATETIME NULL AFTER publish_status,
  ADD COLUMN publish_reviewed_at DATETIME NULL AFTER publish_requested_at,
  ADD COLUMN publish_reviewed_by BIGINT UNSIGNED NULL AFTER publish_reviewed_at,
  ADD COLUMN publish_note VARCHAR(500) NULL AFTER publish_reviewed_by,
  ADD COLUMN published_json JSON NULL AFTER publish_note,
  ADD COLUMN pending_json JSON NULL AFTER published_json;

-- Setting: partner_directory_public_enabled = 0|1 (apagado por defecto).
INSERT INTO settings (setting_key, setting_value)
VALUES ('partner_directory_public_enabled', '0')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
