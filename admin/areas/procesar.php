<?php

declare(strict_types=1);
require_once __DIR__ . '/../auditoria/cambios.php';

// Inicia la sesión y restringe este procesador a administradores autenticados.
require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/../inventario/desactivacion.php';

iniciarSesionSegura();
exigirAdministrador();
require_once __DIR__ . '/../../auth/formularios.php';
unset($_SESSION['mensajeError'], $_SESSION['mensajeExito']);

const RUTA_AREAS = '/sistemaInventario/administracion/areas';

function volverAAreas(): never
{
    $contexto = ($_POST['accion'] ?? '') === 'crear' && !empty($_SESSION['mensajeExito'])
        ? ['crear' => 1] : [];
    header('Location: ' . urlRetornoFormulario('areas', RUTA_AREAS, $contexto));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

$csrfToken = textoEntrada($_POST, 'csrf_token');

if (empty($_SESSION['csrf_areas']) || !hash_equals($_SESSION['csrf_areas'], $csrfToken)) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

// Limita la solicitud a las acciones conocidas por este procesador.
$accion = textoEntrada($_POST, 'accion');


if (!in_array($accion, ['crear', 'actualizar', 'cambiar_estado'], true)) {
    http_response_code(400);
    exit('Acción no válida.');
}

$conexion = require __DIR__ . '/../../config/conexion.php';

/*
    CREACIÓN Y ACTUALIZACIÓN
    Estas acciones comparten la recepción y validación de los datos.
*/
if ($accion === 'crear' || $accion === 'actualizar') {
    $idArea = $accion === 'actualizar'
    ? filter_input(INPUT_POST, 'id_area', FILTER_VALIDATE_INT)
    : null;
    
    // Normaliza los valores antes de validarlos y enviarlos a la base de datos.
    $nombre = trim(textoEntrada($_POST, 'nombre'));
    $estado = $accion === 'actualizar'
        ? textoEntrada($_POST, 'estado') : 'ACTIVO';

    if (!preg_match("/^[\p{L}\p{N} .,'&()\/-]+$/u", $nombre)) {
        $_SESSION['mensajeError'] = 'El nombre del área contiene caracteres no permitidos.';
        volverAAreas();
    }

    $nombre = preg_replace('/\s+/u', ' ', trim(textoEntrada($_POST, 'nombre')));

    if (
        ($accion === 'actualizar' && !$idArea)
        || $nombre === ''
        || mb_strlen($nombre) < 2
        || mb_strlen($nombre) > 80
        || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)
    ) {
        $_SESSION['mensajeError'] = 'El nombre del área debe tener como mínimo 2 carácteres';
        volverAAreas();
    }

    try {
        $conexion->beginTransaction();
        $antesAuditoria = consultarRegistroAuditoria($conexion, 'areas', $idArea ?: null);

        if ($accion === 'actualizar') {
            $buscarArea = $conexion->prepare(
                'SELECT estado
                 FROM areas
                 WHERE id_area = :id_area
                 LIMIT 1
                 FOR UPDATE'
            );
            $buscarArea->execute(['id_area' => $idArea]);

            $estadoAreaActual = $buscarArea->fetchColumn();

            if ($estadoAreaActual === false) {
                throw new RuntimeException('El area que intentas editar no existe.');
            }

            if ($estadoAreaActual === 'ACTIVO' && $estado === 'INACTIVO') {
                exigirCatalogoSinAsignaciones($conexion, 'area', $idArea);
                $contarUsuarios = $conexion->prepare(
                    'SELECT COUNT(*)
                     FROM usuarios
                     WHERE id_area = :id_area'
                );
                $contarUsuarios->execute(['id_area' => $idArea]);
                $totalUsuariosAsociados = (int) $contarUsuarios->fetchColumn();

                if ($totalUsuariosAsociados > 0) {
                    throw new DomainException(
                        'No puedes desactivar esta área porque tiene '
                        . $totalUsuariosAsociados
                        . ($totalUsuariosAsociados === 1
                            ? ' usuario asociado. Reasígnalo primero.'
                            : ' usuarios asociados. Reasígnalos primero.')
                    );
                }
            }
        }

        if ($accion === 'crear') {
            $verificarNombre = $conexion->prepare(
                'SELECT id_area
                 FROM areas
                 WHERE nombre = :nombre 
                 LIMIT 1'
            );
            $verificarNombre->execute([
                'nombre' => $nombre
            ]);
        } else {
            $verificarNombre = $conexion->prepare(
                'SELECT id_area
                 FROM areas
                 WHERE nombre = :nombre
                    AND id_area <> :id_area
                 LIMIT 1'
            );
            $verificarNombre->execute([
                'nombre' => $nombre,
                'id_area' => $idArea
            ]);
        }

        if ($verificarNombre->fetchColumn() !== false) {
            throw new DomainException(
                'Ya existe un área registrada con este nombre.'
            );
        }

        if ($accion === 'crear') {
            $guardarArea = $conexion->prepare(
                'INSERT INTO areas 
                    (
                    nombre,
                    estado
                    )
                 VALUES 
                 (
                    :nombre,
                    "ACTIVO"
                 )'
            );

            $guardarArea->execute([
                'nombre' => $nombre
            ]);

            $idArea = (int) $conexion->lastInsertId();
        } else {
            $guardarArea = $conexion->prepare(
                'UPDATE areas 
                 SET nombre = :nombre,
                     estado = :estado
                 WHERE id_area = :id_area'
            );

            $guardarArea->execute([
                'id_area' => $idArea,
                'nombre' => $nombre,
                'estado' => $estado
            ]);
        }

        registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'areas', (int) $idArea, $antesAuditoria);
        $conexion->commit();

        $_SESSION['mensajeExito'] = $accion === 'crear'
            ? 'Área creada correctamente.'
            : 'Área actualizada correctamente.';
       
    } catch (DomainException | RuntimeException $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        $_SESSION['mensajeError'] = $error->getMessage();
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        error_log('Error al guardar el área: ' . $error->getMessage());
        $_SESSION['mensajeError'] = $error->getCode() === '23000'
            ? 'Ya existe un área registrada con este nombre.'
            : 'No fue posible guardar los datos del área.';
    }

    volverAAreas();
}

$idArea = filter_input(INPUT_POST, 'id_area', FILTER_VALIDATE_INT);
$estado = textoEntrada($_POST, 'estado');
if (!$idArea || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado.';
    volverAAreas();
}

try {
    $conexion->beginTransaction();
    $antesAuditoria = consultarRegistroAuditoria($conexion, 'areas', $idArea ?: null);

    $buscarArea = $conexion->prepare(
        'SELECT estado
         FROM areas
         WHERE id_area = :id_area
         LIMIT 1
         FOR UPDATE'
    );
    $buscarArea->execute(['id_area' => $idArea]);
    $estadoActual = $buscarArea->fetchColumn();

    if ($estadoActual === false) {
        throw new RuntimeException('El área no existe.');
    }

    if ($estadoActual === $estado) {
        throw new DomainException('El área ya tenía el estado seleccionado.');
    }

    if ($estado === 'INACTIVO') {
        exigirCatalogoSinAsignaciones($conexion, 'area', $idArea);
        $contarUsuarios = $conexion->prepare(
            'SELECT COUNT(*)
             FROM usuarios
             WHERE id_area = :id_area'
        );
        $contarUsuarios->execute(['id_area' => $idArea]);
        $totalUsuariosAsociados = (int) $contarUsuarios->fetchColumn();

        if ($totalUsuariosAsociados > 0) {
            throw new DomainException(
                'No puedes desactivar esta área porque tiene '
                . $totalUsuariosAsociados
                . ($totalUsuariosAsociados === 1
                    ? ' usuario asociado. Reasígnalo primero.'
                    : ' usuarios asociados. Reasígnalos primero.')
            );
        }
    }

    $actualizar = $conexion->prepare(
        'UPDATE areas
         SET estado = :estado
         WHERE id_area = :id_area'
    );
    $actualizar->execute([
        'estado' => $estado,
        'id_area' => $idArea,
    ]);

    registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'areas', (int) $idArea, $antesAuditoria);
    $conexion->commit();
    $_SESSION['mensajeExito'] = 'Estado actualizado correctamente.';
} catch (DomainException | RuntimeException $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $_SESSION['mensajeError'] = $error->getMessage();
} catch (Throwable $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    error_log('Error al cambiar el estado del área: ' . $error->getMessage());
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado.';
}

volverAAreas();
