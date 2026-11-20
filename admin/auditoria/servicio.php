<?php

declare(strict_types=1);

function registrarEventoAuditoria(
    PDO $conexion,
    int $idAdministrador,
    string $modulo,
    string $accion,
    string $entidad,
    int $idRegistro,
    ?array $datosAnteriores,
    ?array $datosNuevos
): void {
    if (!$conexion->inTransaction()) {
        throw new LogicException(
            'La auditoría debe registrarse dentro de una transacción.'
        );
    }

    $consulta = $conexion->prepare(
        'SELECT CONCAT(nombre, " ", apellido)
         FROM usuarios
         WHERE id_usuario = ?
           AND rol = "ADMINISTRADOR"
           AND estado = "ACTIVO"
         FOR UPDATE'
    );


    $consulta->execute([$idAdministrador]);
    $nombreAdministrador = $consulta->fetchColumn();

    if ($nombreAdministrador === false) {
        throw new DomainException(
            'El administrador no está habilitado para registrar la operación.'
        );
    }

    $opcionesJson = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    $insertar = $conexion->prepare(
        'INSERT INTO auditoria_eventos (
            id_administrador,
            administrador_nombre,
            modulo,
            accion,
            entidad,
            id_registro,
            datos_anteriores,
            datos_nuevos
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insertar->execute([
        $idAdministrador,
        $nombreAdministrador,
        $modulo,
        $accion,
        $entidad,
        $idRegistro,
        $datosAnteriores === null
            ? null
            : json_encode($datosAnteriores, $opcionesJson),
        $datosNuevos === null
            ? null
            : json_encode($datosNuevos, $opcionesJson),
    ]);
}
