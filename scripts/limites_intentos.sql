CREATE TABLE IF NOT EXISTS intentos_login (
    id_intento BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identificador_hash CHAR(64) NOT NULL,
    direccion_ip VARCHAR(45) NOT NULL,
    fecha_intento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_intento),
    INDEX idx_login_identificador_fecha (identificador_hash, fecha_intento),
    INDEX idx_login_ip_fecha (direccion_ip, fecha_intento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

CREATE TABLE IF NOT EXISTS intentos_recuperacion (
    id_intento BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    correo_hash CHAR(64) NOT NULL,
    direccion_ip VARCHAR(45) NOT NULL,
    fecha_intento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_intento),
    INDEX idx_recuperacion_correo_fecha (correo_hash, fecha_intento),
    INDEX idx_recuperacion_ip_fecha (direccion_ip, fecha_intento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
