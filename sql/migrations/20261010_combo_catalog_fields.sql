-- Combos en catálogo: resumen HTML, imagen propia y visibilidad pública.
ALTER TABLE combos
  ADD COLUMN short_description TEXT NULL AFTER description,
  ADD COLUMN logo_path VARCHAR(255) NULL AFTER short_description,
  ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;
