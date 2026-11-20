<?php
declare(strict_types=1);

function restablecerContrasena(PDO $conexion, int $idRecuperacion, int $idCuenta, string $contrasena): void
{
    $hash = password_hash($contrasena, PASSWORD_DEFAULT);
    $conexion->beginTransaction();
    try {
        // Comprueba la vigencia al guardar, incluso si el formulario se abrió antes.
        // El UPDATE condicional impide que dos solicitudes consuman el mismo enlace.
        $utilizarToken = $conexion->prepare(
            'UPDATE recuperacion_contrasena
             SET estado = "UTILIZADO", fecha_utilizacion = NOW()
             WHERE id_recuperacion = ? AND id_cuenta = ? AND estado = "PENDIENTE"
               AND fecha_utilizacion IS NULL AND fecha_vencimiento >= NOW()'
        );
        $utilizarToken->execute([$idRecuperacion, $idCuenta]);
        if ($utilizarToken->rowCount() !== 1) {
            throw new DomainException('El enlace no es válido o ya venció.');
        }
        $actualizarCuenta = $conexion->prepare('UPDATE cuentas_acceso SET contrasena = ? WHERE id_cuenta = ?');
        $actualizarCuenta->execute([$hash, $idCuenta]);
        if ($actualizarCuenta->rowCount() !== 1) {
            throw new DomainException('La cuenta ya no está disponible.');
        }
        $conexion->commit();
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $error;
    }
}
