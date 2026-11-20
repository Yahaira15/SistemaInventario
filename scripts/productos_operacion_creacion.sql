-- La clave identifica un envío, no el contenido ni el código del equipo.
-- NULL conserva la compatibilidad de los registros existentes.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS token_creacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_producto_token_creacion (token_creacion);
