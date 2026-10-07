-- Posición del % de descuento sobre la imagen de la tarjeta de correo.
ALTER TABLE mail_product_cards
  ADD COLUMN discount_badge_position ENUM(
    'top_right',
    'top_left',
    'bottom_right',
    'bottom_left'
  ) NOT NULL DEFAULT 'top_right' AFTER badge_text;
