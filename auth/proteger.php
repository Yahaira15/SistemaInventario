<?php

declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
require_once __DIR__ . '/entrada.php';

function exigirAdministrador(): void
{
    $limiteInactividad = 30 * 60;
    $ultimaActividad = (int) ($_SESSION['ultima_actividad'] ?? 0);

    if (
        empty($_SESSION['administrador_id'])
        || empty($_SESSION['cuenta_id'])
        || ($_SESSION['rol'] ?? '') !== 'ADMINISTRADOR'
        || $ultimaActividad === 0
        || time() - $ultimaActividad > $limiteInactividad
    ) {
        destruirSesionActual();
        header('Location: /sistemaInventario/?sesion=expirada');
        exit;
    }

    $conexion = require __DIR__ . '/../config/conexion.php';
    $consulta = $conexion->prepare(
        'SELECT c.estado AS cuenta_estado, 
                u.estado AS usuario_estado, 
                u.rol, u.nombre, u.apellido
         FROM cuentas_acceso AS c
         INNER JOIN usuarios AS u ON u.id_usuario = c.id_usuario
         WHERE c.id_cuenta = :id_cuenta
           AND u.id_usuario = :id_usuario
         LIMIT 1'
    );
    $consulta->execute([
        'id_cuenta' => $_SESSION['cuenta_id'],
        'id_usuario' => $_SESSION['administrador_id'],
    ]);
    $cuenta = $consulta->fetch();

    if (
        !$cuenta
        || $cuenta['rol'] !== 'ADMINISTRADOR'
        || $cuenta['cuenta_estado'] !== 'ACTIVO'
        || $cuenta['usuario_estado'] !== 'ACTIVO'
    ) {
        destruirSesionActual();
        header('Location: /sistemaInventario/?sesion=invalida');
        exit;
    }

    $_SESSION['ultima_actividad'] = time();
    $_SESSION['administrador_nombre'] = trim($cuenta['nombre'] . ' ' . $cuenta['apellido']);

    if (empty($_SESSION['csrf_logout'])) {
        $_SESSION['csrf_logout'] = bin2hex(random_bytes(32));
    }

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}
