<?php

declare(strict_types=1);

// Impide ejecutar esta herramienta desde el navegador.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Exige todos los argumentos indicados en el mensaje de uso.
if ($argc !== 7) {
    fwrite(STDERR, "Uso: php scripts/crear_administrador.php" . "CEDULA NOMBRE APELLIDO CORREO AREA CONTRASENA\n");
    exit(1);
}

// Abre la conexión antes de asignar la contraseña para evitar colisiones de variables.
$conexion = require __DIR__ . '/../config/conexion.php';

// Separa los argumentos recibidos desde la terminal.
[, $cedula, $nombre, $apellido, $correo, $area, $contrasena] = $argv;

$cedula = strtoupper(trim($cedula));
$nombre = trim($nombre);
$apellido = trim($apellido);
$correo = strtolower(trim($correo));
$area = trim($area);
$rol = 'ADMINISTRADOR';

if (!preg_match('/^[0-9]{4,20}$/D', $cedula) || strlen($contrasena) < 8) {
    fwrite(STDERR, "La cédula debe tener entre 4 y 20 dígitos y la contraseña al menos 8 caracteres.\n");
    exit(1);
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "El correo no es válido.\n");
    exit(1);
}

// Genera y valida el hash antes de escribir en la base de datos.
$hashContrasena = password_hash($contrasena, PASSWORD_DEFAULT);

if (!password_verify($contrasena, $hashContrasena)) {
    fwrite(STDERR, "No se pudo generar un hash válido para la contraseña.\n");
    exit(1);
}

try {
    // Usuario y cuenta deben crearse juntos; por eso se usa una transacción.
    $conexion->beginTransaction();

    $buscarArea = $conexion->prepare(
        'SELECT id_area
         FROM areas
         WHERE nombre = :nombre
           AND estado = "ACTIVO"
         LIMIT 1'
    );
    $buscarArea->execute(['nombre' => $area]);
    $idArea = $buscarArea->fetchColumn();

    if ($idArea === false) {
        throw new DomainException('El área indicada no existe o está inactiva.');
    }

    $crearUsuario = $conexion->prepare(
        'INSERT INTO usuarios (cedula, nombre, apellido, correo, id_area, rol, estado)
         VALUES (:cedula, :nombre, :apellido, :correo, :id_area, "ADMINISTRADOR", "ACTIVO")'
    );
    $crearUsuario->execute([
        'cedula' => $cedula,
        'nombre' => $nombre,
        'apellido' => $apellido,
        'correo' => $correo,
        'id_area' => (int) $idArea,
    ]);

    // Recupera el ID generado para relacionar la cuenta de acceso.
    $idUsuario = (int) $conexion->lastInsertId();
    $crearCuenta = $conexion->prepare(
        'INSERT INTO cuentas_acceso (id_usuario, contrasena, estado)
         VALUES (:id_usuario, :contrasena, "ACTIVO")'
    );
    $crearCuenta->execute([
        'id_usuario' => $idUsuario,
        'contrasena' => $hashContrasena,
    ]);

    // Lee nuevamente el hash para confirmar que se almacenó correctamente.
    $verificarCuenta = $conexion->prepare(
        'SELECT contrasena FROM cuentas_acceso WHERE id_usuario = :id_usuario'
    );
    $verificarCuenta->execute(['id_usuario' => $idUsuario]);
    $hashGuardado = $verificarCuenta->fetchColumn();

    if (!is_string($hashGuardado) || !password_verify($contrasena, $hashGuardado)) {
        throw new RuntimeException('La contraseña no pudo almacenarse correctamente.');
    }

    $conexion->commit();
    fwrite(STDOUT, "Administrador creado correctamente.\n");
} catch (Throwable $error) {
    // Revierte cualquier inserción parcial cuando ocurre un error.
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    fwrite(STDERR, "No se pudo crear el administrador: {$error->getMessage()}\n");
    exit(1);
}
