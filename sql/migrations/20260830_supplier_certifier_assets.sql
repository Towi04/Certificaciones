-- Logos y enlaces de proveedor / casa certificadora
-- Idempotente: solo agrega columnas si faltan (MySQL 8+ / MariaDB 10.3+).

SET @db := DATABASE();

-- suppliers.logo_path
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'logo_path'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE suppliers ADD COLUMN logo_path VARCHAR(255) NULL AFTER website',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- suppliers.platform_url
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'platform_url'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE suppliers ADD COLUMN platform_url VARCHAR(255) NULL AFTER logo_path',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- certifiers.website
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'certifiers' AND COLUMN_NAME = 'website'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE certifiers ADD COLUMN website VARCHAR(255) NULL AFTER logo_path',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- certifiers.platform_url
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'certifiers' AND COLUMN_NAME = 'platform_url'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE certifiers ADD COLUMN platform_url VARCHAR(255) NULL AFTER website',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- certifiers.notes
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'certifiers' AND COLUMN_NAME = 'notes'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE certifiers ADD COLUMN notes TEXT NULL AFTER platform_url',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- certifiers.is_active
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'certifiers' AND COLUMN_NAME = 'is_active'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE certifiers ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
