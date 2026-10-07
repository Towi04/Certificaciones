-- Estilo por tarjeta (color y texto del botón).
ALTER TABLE mail_product_cards
  ADD COLUMN accent_color VARCHAR(7) NOT NULL DEFAULT '#315285' AFTER custom_image_path;
ALTER TABLE mail_product_cards
  ADD COLUMN cta_label VARCHAR(80) NOT NULL DEFAULT 'Ver en catálogo' AFTER accent_color;
