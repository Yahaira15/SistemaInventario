<?php

declare(strict_types=1);
require_once __DIR__ . '/../../auditoria/cambios.php';

require_once __DIR__ . '/../../../auth/sesion.php';
require_once __DIR__ . '/../../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();
require_once __DIR__ . '/../../../auth/formularios.php';
unset($_SESSION['mensajeError'], $_SESSION['mensajeExito']);

const RUTA_OPCIONES = '/sistemaInventario/administracion/atributos/opciones';

function volverAOpciones(int $idAtributo): never
{
    header('Location: ' . urlRetornoFormulario('atributo_opciones', RUTA_OPCIONES, ['atributo' => (int) $idAtributo]));
    exit;
}

/**
 * Mantiene utilizable una lista asignada mientras el atributo esté activo.
 * Debe ejecutarse dentro de una transacción que bloquee primero el atributo.
 */
function impedirDesactivarUltimaOpcionActiva(PDO $conexion, int $idAtributo, int $idOpcion): void
{
    $consulta = $conexion->prepare(
        'SELECT
            a.estado AS estado_atributo,
            ao.estado AS estado_opcion,
            EXISTS(
                SELECT 1 FROM categoria_atributos AS ca
                WHERE ca.id_atributo = a.id_atributo
                  AND ca.estado = "ACTIVO"
            ) AS esta_asignado,
            (
                SELECT COUNT(*) FROM atributo_opciones AS otra
                WHERE otra.id_atributo = a.id_atributo
                  AND otra.estado = "ACTIVO"
                  AND otra.id_atributo_opcion <> ao.id_atributo_opcion
            ) AS otras_activas
         FROM atributos AS a
         INNER JOIN atributo_opciones AS ao
            ON ao.id_atributo = a.id_atributo
         WHERE a.id_atributo = :id_atributo
           AND ao.id_atributo_opcion = :id_atributo_opcion'
    );
    $consulta->execute([
        'id_atributo' => $idAtributo,
        'id_atributo_opcion' => $idOpcion,
    ]);
    $proteccion = $consulta->fetch();

    if (
        $proteccion
        && $proteccion['estado_atributo'] === 'ACTIVO'
        && $proteccion['estado_opcion'] === 'ACTIVO'
        && (int) $proteccion['esta_asignado'] === 1
        && (int) $proteccion['otras_activas'] === 0
    ) {
        $conexion->rollBack();
        $_SESSION['mensajeError'] = 'No puedes desactivar la última opción de un atributo activo y asignado.';
        volverAOpciones($idAtributo);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

$csrfToken = textoEntrada($_POST, 'csrf_token');

if (empty($_SESSION['csrf_atributo_opciones']) || !hash_equals($_SESSION['csrf_atributo_opciones'], $csrfToken)) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

$accion = textoEntrada($_POST, 'accion');
if (!in_array($accion, ['crear', 'actualizar', 'cambiar_estado'], true)) {
    http_response_code(400);
    exit('Acción no válida.');
}

$idAtributo = filter_input(INPUT_POST, 'id_atributo', FILTER_VALIDATE_INT);
if (!$idAtributo) {
    http_response_code(400);
    exit('Atributo no válido.');
}

$conexion = require __DIR__ . '/../../../config/conexion.php';
$consultaAtributo = $conexion->prepare(
    'SELECT id_atributo, estado
     FROM atributos
     WHERE id_atributo = :id_atributo
       AND tipo_dato = "SELECCION"
     LIMIT 1'
);
$consultaAtributo->execute(['id_atributo' => $idAtributo]);
$atributo = $consultaAtributo->fetch();

if (!$atributo) {
    http_response_code(400);
    exit('El atributo no admite opciones.');
}

if ($atributo['estado'] !== 'ACTIVO') {
    $_SESSION['mensajeError'] = 'Activa el atributo antes de administrar sus opciones.';
    header('Location: /sistemaInventario/administracion/atributos');
    exit;
}

if ($accion === 'crear' || $accion === 'actualizar') {
    $idOpcion = $accion === 'actualizar'
        ? filter_input(INPUT_POST, 'id_atributo_opcion', FILTER_VALIDATE_INT)
        : null;
    $valor = trim(textoEntrada($_POST, 'valor'));
    $estado = $accion === 'actualizar'
        ? strtoupper(trim(textoEntrada($_POST, 'estado')))
        : 'ACTIVO';

    if (($accion === 'actualizar' && !$idOpcion) || $valor === '' || mb_strlen($valor) > 100 || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
        $_SESSION['mensajeError'] = 'Revisa los datos de la opción.';
        volverAOpciones($idAtributo);
    }
    
    try {
        if ($accion === 'crear') {
            $conexion->beginTransaction();
            $bloqueoAuditoria = $conexion->prepare('SELECT id_atributo FROM atributos WHERE id_atributo = ? FOR UPDATE');
            $bloqueoAuditoria->execute([$idAtributo]);
            $antesAuditoria = consultarRegistroAuditoria($conexion, 'atributo_opciones', $idOpcion ?: null);
            if ($antesAuditoria !== null && (int) $antesAuditoria['id_atributo'] !== $idAtributo) {
                throw new DomainException('La opción no pertenece a este atributo.');
            }

            // Serializa las creaciones del mismo atributo para evitar órdenes repetidos.
            $bloquearAtributo = $conexion->prepare(
                'SELECT id_atributo
                 FROM atributos
                 WHERE id_atributo = :id_atributo
                 FOR UPDATE'
            );
            $bloquearAtributo->execute(['id_atributo' => $idAtributo]);

            $consultaOrden = $conexion->prepare(
                'SELECT COALESCE(MAX(orden_visual), 0) + 1
                 FROM atributo_opciones
                 WHERE id_atributo = :id_atributo'
            );
            $consultaOrden->execute(['id_atributo' => $idAtributo]);
            $ordenVisual = (int) $consultaOrden->fetchColumn();

            $guardar = $conexion->prepare(
                'INSERT INTO atributo_opciones (id_atributo, valor, orden_visual, estado)
                 VALUES (:id_atributo, :valor, :orden_visual, "ACTIVO")'
            );
            $guardar->execute([
                'id_atributo' => $idAtributo,
                'valor' => $valor,
                'orden_visual' => $ordenVisual,
            ]);

            $idOpcion = (int) $conexion->lastInsertId();
            registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'atributo_opciones', (int) $idOpcion, $antesAuditoria);
            $conexion->commit();
            $_SESSION['mensajeExito'] = 'Opción creada correctamente.';
        } else {
            $conexion->beginTransaction();
            $bloqueoAuditoria = $conexion->prepare('SELECT id_atributo FROM atributos WHERE id_atributo = ? FOR UPDATE');
            $bloqueoAuditoria->execute([$idAtributo]);
            $antesAuditoria = consultarRegistroAuditoria($conexion, 'atributo_opciones', $idOpcion ?: null);
            if ($antesAuditoria !== null && (int) $antesAuditoria['id_atributo'] !== $idAtributo) {
                throw new DomainException('La opción no pertenece a este atributo.');
            }
            if ($estado === 'INACTIVO') {
                $bloquearAtributo = $conexion->prepare(
                    'SELECT id_atributo FROM atributos
                     WHERE id_atributo = :id_atributo FOR UPDATE'
                );
                $bloquearAtributo->execute(['id_atributo' => $idAtributo]);
                impedirDesactivarUltimaOpcionActiva($conexion, $idAtributo, $idOpcion);
            }

            $guardar = $conexion->prepare(
                'UPDATE atributo_opciones
                 SET valor = :valor, estado = :estado
                 WHERE id_atributo_opcion = :id_atributo_opcion
                   AND id_atributo = :id_atributo'
            );
            $guardar->execute([
                'valor' => $valor,
                'estado' => $estado,
                'id_atributo_opcion' => $idOpcion,
                'id_atributo' => $idAtributo,
            ]);
            if ($conexion->inTransaction()) {
                registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'atributo_opciones', (int) $idOpcion, $antesAuditoria);
                $conexion->commit();
            }
            $_SESSION['mensajeExito'] = $guardar->rowCount() === 1 ? 'Opción actualizada correctamente.' : 'No se realizaron cambios.';
        }
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        if ($error->getCode() === '23000') {
            $_SESSION['mensajeError'] = 'Ya existe una opción con ese valor.';
        } else {
            error_log('Error al guardar opción: ' . $error->getMessage());
            $_SESSION['mensajeError'] = 'No fue posible guardar la opción.';
        }
    }

    volverAOpciones($idAtributo);
}

$idOpcion = filter_input(INPUT_POST, 'id_atributo_opcion', FILTER_VALIDATE_INT);
$estado = strtoupper(trim(textoEntrada($_POST, 'estado')));

if (!$idOpcion || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado.';
    volverAOpciones($idAtributo);
}

try {
    $conexion->beginTransaction();
            $bloqueoAuditoria = $conexion->prepare('SELECT id_atributo FROM atributos WHERE id_atributo = ? FOR UPDATE');
            $bloqueoAuditoria->execute([$idAtributo]);
            $antesAuditoria = consultarRegistroAuditoria($conexion, 'atributo_opciones', $idOpcion ?: null);
            if ($antesAuditoria !== null && (int) $antesAuditoria['id_atributo'] !== $idAtributo) {
                throw new DomainException('La opción no pertenece a este atributo.');
            }
    if ($estado === 'INACTIVO') {
        $bloquearAtributo = $conexion->prepare(
            'SELECT id_atributo FROM atributos
             WHERE id_atributo = :id_atributo FOR UPDATE'
        );
        $bloquearAtributo->execute(['id_atributo' => $idAtributo]);
        impedirDesactivarUltimaOpcionActiva($conexion, $idAtributo, $idOpcion);
    }

    $actualizar = $conexion->prepare(
        'UPDATE atributo_opciones
         SET estado = :estado
         WHERE id_atributo_opcion = :id_atributo_opcion
           AND id_atributo = :id_atributo'
    );
    $actualizar->execute([
        'estado' => $estado,
        'id_atributo_opcion' => $idOpcion,
        'id_atributo' => $idAtributo,
    ]);
    if ($conexion->inTransaction()) {
        registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'atributo_opciones', (int) $idOpcion, $antesAuditoria);
        $conexion->commit();
    }
    $_SESSION['mensajeExito'] = $actualizar->rowCount() === 1 ? 'Estado actualizado correctamente.' : 'La opción no existe o ya tenía ese estado.';
} catch (Throwable $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('Error al cambiar estado de opción: ' . $error->getMessage());
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado.';
}

volverAOpciones($idAtributo);
