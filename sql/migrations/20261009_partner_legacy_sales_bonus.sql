-- Ajuste temporal: certificaciones históricas (fuera del PDV) que cuentan para el convenio.
ALTER TABLE partners
  ADD COLUMN legacy_sales_bonus INT UNSIGNED NOT NULL DEFAULT 0 AFTER notes;
