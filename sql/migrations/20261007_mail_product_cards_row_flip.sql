-- Layout horizontal invertido + banner wide a sangre (enum).
ALTER TABLE mail_product_cards
  MODIFY COLUMN layout ENUM('square','square_desc','wide','row','row_flip') NOT NULL DEFAULT 'wide';
