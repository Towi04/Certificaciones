-- Descripción corta con HTML (negritas / estilos sencillos); deja de limitarse a VARCHAR(255).
ALTER TABLE products
  MODIFY COLUMN short_description TEXT NULL;
