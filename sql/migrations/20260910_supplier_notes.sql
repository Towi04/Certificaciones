-- Notas internas en proveedores (si la columna aún no existe).
SET @db := DATABASE();

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'notes'
);
SET @sql := IF(
  @exists = 0,
  'ALTER TABLE suppliers ADD COLUMN notes TEXT NULL AFTER platform_url',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
