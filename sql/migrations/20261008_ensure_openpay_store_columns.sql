-- Idempotente: columnas OXXO / tienda OpenPay (por si 20260822 no corrió).
-- El runtime en PurchaseRepository también las asegura al cobrar.

SET @db := DATABASE();

SET @has_ref := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'openpay_store_reference'
);
SET @sql_ref := IF(
  @has_ref = 0,
  'ALTER TABLE purchases ADD COLUMN openpay_store_reference VARCHAR(50) NULL AFTER openpay_clabe',
  'SELECT 1'
);
PREPARE stmt_ref FROM @sql_ref;
EXECUTE stmt_ref;
DEALLOCATE PREPARE stmt_ref;

SET @has_barcode := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'openpay_barcode_url'
);
SET @sql_barcode := IF(
  @has_barcode = 0,
  'ALTER TABLE purchases ADD COLUMN openpay_barcode_url VARCHAR(512) NULL AFTER openpay_store_reference',
  'SELECT 1'
);
PREPARE stmt_barcode FROM @sql_barcode;
EXECUTE stmt_barcode;
DEALLOCATE PREPARE stmt_barcode;

ALTER TABLE purchases
  MODIFY payment_method ENUM(
    'none','openpay_spei','openpay_card','openpay_store',
    'transfer_proof','partner_account','credit'
  ) NOT NULL DEFAULT 'none';
