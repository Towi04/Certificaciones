-- Campos personalizados capturados en checkout (JSON por alumno)
ALTER TABLE students
  ADD COLUMN extra_fields_json JSON NULL AFTER nationality;
