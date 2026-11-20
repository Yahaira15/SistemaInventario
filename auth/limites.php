<?php

declare(strict_types=1);

const MAX_FALLOS_LOGIN_IDENTIFICADOR = 5;
const MAX_FALLOS_LOGIN_IP = 20;
const MINUTOS_BLOQUEO_LOGIN = 15;

const MAX_RECUPERACIONES_CORREO = 3;
const MAX_RECUPERACIONES_IP = 10;
const MINUTOS_RECUPERACION_CORREO = 30;
const MINUTOS_RECUPERACION_IP = 60;

function direccionIpCliente(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function loginEstaBloqueado(PDO $conexion, string $cedula): bool
{
    $consulta = $conexion->prepare(
        'SELECT
            SUM(identificador_hash = :identificador_hash) AS fallos_identificador,
            SUM(direccion_ip = :direccion_ip) AS fallos_ip
         FROM intentos_login
         WHERE fecha_intento >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $consulta->execute([
        'identificador_hash' => hash('sha256', $cedula),
        'direccion_ip' => direccionIpCliente(),
    ]);
    $intentos = $consulta->fetch();

    return (int) ($intentos['fallos_identificador'] ?? 0) >= MAX_FALLOS_LOGIN_IDENTIFICADOR
        || (int) ($intentos['fallos_ip'] ?? 0) >= MAX_FALLOS_LOGIN_IP;
}

function registrarFalloLogin(PDO $conexion, string $cedula): void
{
    $insertar = $conexion->prepare(
        'INSERT INTO intentos_login (identificador_hash, direccion_ip)
         VALUES (:identificador_hash, :direccion_ip)'
    );
    $insertar->execute([
        'identificador_hash' => hash('sha256', $cedula),
        'direccion_ip' => direccionIpCliente(),
    ]);
}

function limpiarFallosLogin(PDO $conexion, string $cedula): void
{
    $eliminar = $conexion->prepare(
        'DELETE FROM intentos_login WHERE identificador_hash = :identificador_hash'
    );
    $eliminar->execute([
        'identificador_hash' => hash('sha256', $cedula),
    ]);
}

function registrarSolicitudRecuperacion(PDO $conexion, string $correo): bool
{
    $correoHash = hash('sha256', $correo);
    $direccionIp = direccionIpCliente();

    $insertar = $conexion->prepare(
        'INSERT INTO intentos_recuperacion (correo_hash, direccion_ip)
         VALUES (:correo_hash, :direccion_ip)'
    );
    $insertar->execute([
        'correo_hash' => $correoHash,
        'direccion_ip' => $direccionIp,
    ]);

    $consultaCorreo = $conexion->prepare(
        'SELECT COUNT(*)
         FROM intentos_recuperacion
         WHERE correo_hash = :correo_hash
           AND fecha_intento >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)'
    );
    $consultaCorreo->execute(['correo_hash' => $correoHash]);

    $consultaIp = $conexion->prepare(
        'SELECT COUNT(*)
         FROM intentos_recuperacion
         WHERE direccion_ip = :direccion_ip
           AND fecha_intento >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)'
    );
    $consultaIp->execute(['direccion_ip' => $direccionIp]);

    return (int) $consultaCorreo->fetchColumn() <= MAX_RECUPERACIONES_CORREO
        && (int) $consultaIp->fetchColumn() <= MAX_RECUPERACIONES_IP;
}

function eliminarIntentosAntiguos(PDO $conexion): void
{
    $conexion->exec(
        'DELETE FROM intentos_login
         WHERE fecha_intento < DATE_SUB(NOW(), INTERVAL 1 DAY)'
    );
    $conexion->exec(
        'DELETE FROM intentos_recuperacion
         WHERE fecha_intento < DATE_SUB(NOW(), INTERVAL 2 DAY)'
    );
}
