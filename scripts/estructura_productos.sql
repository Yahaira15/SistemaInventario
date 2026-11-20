-- Esquema actual para instalaciones nuevas. Crear antes categorias y areas.
-- Para la instalación existente, ejecutar productos_version.sql.
-- La ubicación se representa mediante id_area; no se convierte texto a IDs automáticamente.
CREATE TABLE IF NOT EXISTS productos (
    id_producto INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_categoria INT NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    estado ENUM('ACTIVO', 'INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    id_area INT NOT NULL,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    token_creacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY uq_producto_token_creacion (token_creacion),
    INDEX idx_producto_categoria (id_categoria),
    INDEX idx_producto_id_area (id_area),
    CONSTRAINT fk_producto_categoria FOREIGN KEY (id_categoria) REFERENCES categorias (id_categoria) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_producto_area FOREIGN KEY (id_area) REFERENCES areas (id_area) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
