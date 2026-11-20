<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Aísla todas las escrituras de auditoría y crea un ejecutor ficticio. */
function prepararAuditoriaPruebas(PDO $conexion): void
{
    foreach (['usuarios', 'auditoria_eventos'] as $tabla) {
        $ddl = $conexion->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
        $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
        $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
        $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
        $conexion->exec($ddl);
    }
    $conexion->exec("INSERT INTO usuarios
        (id_usuario, cedula, nombre, apellido, correo, id_area, rol, estado)
        VALUES (1, '1000', 'Administrador', 'Prueba', 'admin@example.test', 1, 'ADMINISTRADOR', 'ACTIVO')");
}
