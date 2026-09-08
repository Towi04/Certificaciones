-- Segundo logo de proveedor (con denominación / wordmark).
-- Idempotente.

SET @db := DATABASE();

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'logo_wordmark_path'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE suppliers ADD COLUMN logo_wordmark_path VARCHAR(255) NULL AFTER logo_path',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
