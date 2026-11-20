<?php

declare(strict_types=1);

/** Valida dentro de la misma transacción que desactivará al usuario. */
function exigirUsuarioSinAsignaciones(PDO $conexion, int $idUsuario): void
{
    if (!$conexion->inTransaction()) {
        throw new LogicException('La validación requiere una transacción.');
    }

    // Asignar un producto también bloquea al usuario antes de insertar.
    $usuario = $conexion->prepare(
        'SELECT id_usuario FROM usuarios WHERE id_usuario = ? FOR UPDATE'
    );
    $usuario->execute([$idUsuario]);
    if ($usuario->fetchColumn() === false) {
        throw new DomainException('El usuario no existe.');
    }

    // Lectura actual: no usa una instantánea anterior de la transacción.
    $asignaciones = $conexion->prepare(
        'SELECT id_producto_asignacion FROM producto_asignaciones
         WHERE id_usuario = ? AND fecha_devolucion IS NULL FOR UPDATE'
    );
    $asignaciones->execute([$idUsuario]);
    $total = count($asignaciones->fetchAll(PDO::FETCH_COLUMN));

    if ($total > 0) {
        throw new DomainException(
            'No puedes desactivar este usuario porque tiene ' . $total
            . ($total === 1
                ? ' producto asignado. Primero debes desasignarlo'
                : ' productos asignados. Primero debes desasignarlos')
            . ' desde Inventario, registrando la devolución.'
        );
    }
}
