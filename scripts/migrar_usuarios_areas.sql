-- Normaliza la relación usuarios-áreas conservando todos los datos existentes.
-- Ejecutar una sola vez sobre la base inventarioequipos.

START TRANSACTION;

INSERT INTO areas (nombre, estado)
SELECT DISTINCT u.area, 'ACTIVO'
FROM usuarios AS u
LEFT JOIN areas AS a ON a.nombre = u.area
WHERE a.id_area IS NULL;

UPDATE usuarios AS u
INNER JOIN areas AS a ON a.nombre = u.area
SET u.id_area = a.id_area;

COMMIT;

ALTER TABLE areas
    MODIFY nombre VARCHAR(80) NOT NULL,
    ADD CONSTRAINT uq_areas_nombre UNIQUE (nombre);

ALTER TABLE usuarios
    DROP INDEX uq_usuario_area,
    ADD INDEX idx_usuarios_id_area (id_area),
    ADD CONSTRAINT fk_usuarios_areas
        FOREIGN KEY (id_area)
        REFERENCES areas (id_area)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    DROP COLUMN area;
