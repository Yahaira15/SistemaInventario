<?php

declare(strict_types=1);
require_once __DIR__ . '/../../auditoria/cambios.php';

/** Registra la baja completa. Una fecha nula utiliza la hora del servidor de BD. */
function darDeBajaProducto(PDO $conexion, int $id, int $version, ?string $fecha, string $motivo, int $administrador): void
{
    $motivo = trim($motivo);
    if ($id < 1 || $version < 1) {
        throw new DomainException('El equipo o su versión no son válidos.');
    }
    if ($fecha !== null) {
        $fecha = str_replace('T', ' ', $fecha);
        if (strlen($fecha) === 16) {
            $fecha .= ':00';
        }
        $instante = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fecha);
        if (!$instante || $instante->format('Y-m-d H:i:s') !== $fecha) {
            throw new DomainException('Indica una fecha y hora de baja válidas.');
        }
    }
    if ($motivo === '' || mb_strlen($motivo, 'UTF-8') > 500) {
        throw new DomainException('El motivo de baja es obligatorio y admite máximo 500 caracteres.');
    }
    $conexion->beginTransaction();
    try {
        $antesAuditoria = consultarRegistroAuditoria($conexion, 'productos', $id);
        $q = $conexion->prepare('SELECT * FROM productos WHERE id_producto = ? FOR UPDATE');
        $q->execute([$id]);
        $producto = $q->fetch(PDO::FETCH_ASSOC);
        if (!$producto) {
            throw new DomainException('El equipo no existe.');
        }
        if ($producto['estado'] !== 'ACTIVO') {
            throw new DomainException('El equipo ya está dado de baja.');
        }
        if ((int)$producto['version'] !== $version) {
            throw new DomainException('El equipo cambió. Revisa sus datos antes de confirmar la baja.');
        }
        $ahora = (string)$conexion->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
        $fecha ??= $ahora;
        if ($fecha > $ahora || $fecha < $producto['fecha_creacion']) {
            throw new DomainException('La baja no puede ser futura ni anterior al registro del equipo.');
        }
        $q = $conexion->prepare('SELECT id_usuario FROM usuarios WHERE id_usuario = ? AND rol = "ADMINISTRADOR" AND estado = "ACTIVO" FOR UPDATE');
        $q->execute([$administrador]);
        if (!$q->fetchColumn()) {
            throw new DomainException('El administrador no está habilitado para registrar la baja.');
        }
        foreach (['producto_asignaciones' => ['fecha_asignacion', 'fecha_devolucion'], 'producto_codigos' => ['fecha_inicio', 'fecha_fin']] as $tabla => [$inicio,$fin]) {
            $q = $conexion->prepare("SELECT $inicio, $fin FROM $tabla WHERE id_producto = ? FOR UPDATE");
            $q->execute([$id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $registro) {
                if ($fecha < $registro[$inicio] || ($registro[$fin] !== null && $fecha < $registro[$fin])) {
                    throw new DomainException('La baja no puede ser anterior al historial de asignaciones o códigos del equipo.');
                }
            }
        }
        $q = $conexion->prepare('SELECT tipo_movimiento, fecha_movimiento FROM inventario_movimientos WHERE id_producto = ? FOR UPDATE');
        $q->execute([$id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $movimiento) {
            if ($movimiento['tipo_movimiento'] === 'BAJA') {
                throw new DomainException('El equipo ya tiene una baja registrada.');
            }
            if ($fecha < $movimiento['fecha_movimiento']) {
                throw new DomainException('La baja no puede ser anterior a un movimiento registrado del equipo.');
            }
        }
        $q = $conexion->prepare('INSERT INTO inventario_movimientos (id_producto,tipo_movimiento,fecha_movimiento,motivo,id_usuario_registro) VALUES (?,"BAJA",?,?,?)');
        $q->execute([$id, $fecha, $motivo, $administrador]);
        $q = $conexion->prepare('UPDATE producto_asignaciones SET fecha_devolucion = ? WHERE id_producto = ? AND fecha_devolucion IS NULL');
        $q->execute([$fecha, $id]);
        $q = $conexion->prepare('UPDATE producto_codigos SET fecha_fin = ? WHERE id_producto = ? AND fecha_fin IS NULL');
        $q->execute([$fecha, $id]);

        $q = $conexion->prepare(
            'UPDATE productos 
             SET estado = "INACTIVO",
                disponible = 0,
                version = version + 1
             WHERE id_producto = ?'
        );

        $q->execute([$id]);

        registrarCambioAuditoria($conexion, $administrador, 'productos', $id, $antesAuditoria, ['motivo_baja' => $motivo, 'fecha_baja' => $fecha]);
        $conexion->commit();
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $error;
    }
}
