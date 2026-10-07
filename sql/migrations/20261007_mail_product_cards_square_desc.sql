-- Layout: cuadro pequeño + descripción
ALTER TABLE mail_product_cards
  MODIFY COLUMN layout ENUM('square','square_desc','wide','row') NOT NULL DEFAULT 'wide';
