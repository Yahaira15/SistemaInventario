<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../auth/limites.php';
$conexion = require __DIR__ . '/../config/conexion.php';

$_SERVER['REMOTE_ADDR'] = '192.0.2.123';
$cedulaPrueba = 'PRUEBA-LIMITE-' . bin2hex(random_bytes(4));
$correoPrueba = 'limite-' . bin2hex(random_bytes(4)) . '@example.com';

$conexion->beginTransaction();

try {
    for ($numero = 1; $numero <= MAX_FALLOS_LOGIN_IDENTIFICADOR; $numero++) {
        registrarFalloLogin($conexion, $cedulaPrueba);
    }

    if (!loginEstaBloqueado($conexion, $cedulaPrueba)) {
        throw new RuntimeException('El límite de login no se activó.');
    }

    for ($numero = 1; $numero <= MAX_RECUPERACIONES_CORREO; $numero++) {
        if (!registrarSolicitudRecuperacion($conexion, $correoPrueba)) {
            throw new RuntimeException('La recuperación se bloqueó antes del límite.');
        }
    }

    if (registrarSolicitudRecuperacion($conexion, $correoPrueba)) {
        throw new RuntimeException('El límite de recuperación no se activó.');
    }

    $conexion->rollBack();
    fwrite(STDOUT, "Los límites funcionan correctamente.\n");
} catch (Throwable $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
