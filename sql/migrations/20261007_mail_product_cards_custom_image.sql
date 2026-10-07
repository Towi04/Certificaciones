-- Imagen opcional por tarjeta de correo (si vacío usa el logo del producto).
-- En instalaciones nuevas ya viene en CREATE TABLE; este ALTER es para DBs previas.
-- Si la columna ya existe, ignora el error al aplicar a mano.
ALTER TABLE mail_product_cards
  ADD COLUMN custom_image_path VARCHAR(255) NULL AFTER badge_text;
