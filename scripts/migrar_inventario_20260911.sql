-- MariaDB 10.4. Migracion de una sola ejecucion.
-- Respaldo previo: respaldo_inventario_20260911.sql.
-- productos.codigo se conserva por compatibilidad con el PHP actual.
-- Antes de habilitar cambios de codigo en la interfaz, adaptar ese PHP
-- a producto_codigos y retirar la columna antigua en otra migracion.
-- No se deducen compras ni bajas a partir de fecha_creacion o estado.

ALTER TABLE productos
    ADD UNIQUE KEY uq_producto_categoria (id_producto, id_categoria),
    ADD CONSTRAINT fk_producto_area FOREIGN KEY (id_area)
        REFERENCES areas (id_area) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE codigos_inventario
    ADD UNIQUE KEY uq_codigo_categoria (id_codigo, id_categoria),
    ADD CONSTRAINT fk_codigo_categoria FOREIGN KEY (id_categoria)
        REFERENCES categorias (id_categoria) ON DELETE RESTRICT ON UPDATE CASCADE;

CREATE TABLE producto_codigos (
    id_producto_codigo INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    id_codigo INT NOT NULL,
    id_categoria INT NOT NULL,
    fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_fin DATETIME NULL DEFAULT NULL,
    origen ENUM('MIGRACION','ASIGNACION') NOT NULL DEFAULT 'ASIGNACION',
    producto_vigente INT GENERATED ALWAYS AS
        (CASE WHEN fecha_fin IS NULL THEN id_producto ELSE NULL END) PERSISTENT,
    codigo_vigente INT GENERATED ALWAYS AS
        (CASE WHEN fecha_fin IS NULL THEN id_codigo ELSE NULL END) PERSISTENT,
    UNIQUE KEY uq_producto_codigo_vigente (producto_vigente),
    UNIQUE KEY uq_codigo_producto_vigente (codigo_vigente),
    CONSTRAINT ck_codigo_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio),
    CONSTRAINT fk_historial_producto_categoria FOREIGN KEY (id_producto, id_categoria)
        REFERENCES productos (id_producto, id_categoria) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_historial_codigo_categoria FOREIGN KEY (id_codigo, id_categoria)
        REFERENCES codigos_inventario (id_codigo, id_categoria) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

ALTER TABLE producto_asignaciones
    ADD COLUMN asignacion_vigente TINYINT GENERATED ALWAYS AS
        (CASE WHEN fecha_devolucion IS NULL THEN 1 ELSE NULL END) PERSISTENT,
    ADD UNIQUE KEY uq_asignacion_usuario_vigente (id_producto, id_usuario, asignacion_vigente),
    ADD CONSTRAINT ck_asignacion_fechas
        CHECK (fecha_devolucion IS NULL OR fecha_devolucion >= fecha_asignacion);

CREATE TABLE inventario_movimientos (
    id_movimiento INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    tipo_movimiento ENUM('ENTRADA','BAJA') NOT NULL,
    fecha_movimiento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    motivo VARCHAR(500) NOT NULL,
    id_usuario_registro INT UNSIGNED NOT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_movimiento_producto_fecha (id_producto, fecha_movimiento),
    CONSTRAINT ck_movimiento_motivo CHECK (CHAR_LENGTH(TRIM(motivo)) > 0),
    CONSTRAINT fk_movimiento_producto FOREIGN KEY (id_producto)
        REFERENCES productos (id_producto) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_movimiento_usuario FOREIGN KEY (id_usuario_registro)
        REFERENCES usuarios (id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

START TRANSACTION;
INSERT INTO codigos_inventario (codigo, id_categoria)
SELECT codigo, id_categoria FROM productos;

-- Fecha de inicio = fecha de migracion, no fecha historica supuesta.
INSERT INTO producto_codigos (id_producto, id_codigo, id_categoria, origen)
SELECT p.id_producto, c.id_codigo, p.id_categoria, 'MIGRACION'
FROM productos p JOIN codigos_inventario c ON c.codigo = p.codigo;
COMMIT;

CREATE VIEW codigos_inventario_disponibles AS
SELECT c.id_codigo, c.codigo, c.id_categoria
FROM codigos_inventario c
WHERE NOT EXISTS (
    SELECT 1 FROM producto_codigos pc
    WHERE pc.codigo_vigente = c.id_codigo
);
