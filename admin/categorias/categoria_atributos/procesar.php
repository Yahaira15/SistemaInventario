<?php

declare(strict_types=1);
require_once __DIR__ . '/../../auditoria/cambios.php';

require_once __DIR__ . '/../../../auth/sesion.php';
require_once __DIR__ . '/../../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();
require_once __DIR__ . '/../../../auth/formularios.php';
unset($_SESSION['mensajeError'], $_SESSION['mensajeExito']);

const RUTA_CATEGORIA_ATRIBUTOS = '/sistemaInventario/administracion/categorias/atributos';

function volverACategoriaAtributo(int $idCategoria): never
{
    header('Location: ' . urlRetornoFormulario('categoria_atributos', RUTA_CATEGORIA_ATRIBUTOS, ['categoria' => (int) $idCategoria]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

$csrfToken = textoEntrada($_POST, 'csrf_token');

if (empty($_SESSION['csrf_categoria_atributos']) || !hash_equals($_SESSION['csrf_categoria_atributos'], $csrfToken)) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

$accion = textoEntrada($_POST, 'accion');

if (!in_array($accion, ['asignar', 'actualizar', 'cambiar_estado'], true)) {
    http_response_code(400);
    exit('Acción no válida.');
}

$idCategoria = filter_input(INPUT_POST, 'id_categoria', FILTER_VALIDATE_INT);

if (!$idCategoria || $idCategoria < 1) {
    http_response_code(400);
    exit('Categoría no válida.');
}

$conexion = require __DIR__ . '/../../../config/conexion.php';

$consultaCategoria = $conexion->prepare(
    'SELECT estado
     FROM categorias
     WHERE id_categoria = :id_categoria
     LIMIT 1'
);

$consultaCategoria->execute([
    'id_categoria' => $idCategoria,
]);

$estadoCategoria = $consultaCategoria->fetchColumn();

if ($estadoCategoria === false) {
    $_SESSION['mensajeError'] = 'La categoría no existe.';
    volverACategoriaAtributo($idCategoria);
}

if ($estadoCategoria !== 'ACTIVO') {
    $_SESSION['mensajeError'] = 'No puedes modificar los atributos de una categoría inactiva.';

    header('Location: /sistemaInventario/administracion/categorias');
    exit;
}

if ($accion === 'asignar') {
    $idAtributo = filter_input(INPUT_POST, 'id_atributo', FILTER_VALIDATE_INT);
    $requerido = filter_input(INPUT_POST, 'requerido', FILTER_VALIDATE_INT);

    if (!$idAtributo || !in_array($requerido, [0, 1], true)) {
        $_SESSION['mensajeError'] = 'Revisa los datos de la asignación.';
        volverACategoriaAtributo($idCategoria);
    }

    try {
        $verificar = $conexion->prepare(
            'SELECT c.id_categoria, a.id_atributo
             FROM categorias AS c
             CROSS JOIN atributos AS a
             WHERE c.id_categoria = :id_categoria
               AND a.id_atributo = :id_atributo
               AND a.estado = "ACTIVO"
             LIMIT 1'
        );
        $verificar->execute([
            'id_categoria' => $idCategoria,
            'id_atributo' => $idAtributo,
        ]);

        if (!$verificar->fetch()) {
            $_SESSION['mensajeError'] = 'La categoría o el atributo no están disponibles.';
            volverACategoriaAtributo($idCategoria);
        }

        $conexion->beginTransaction();

        $bloquearCategoria = $conexion->prepare(
            'SELECT id_categoria
            FROM categorias
            WHERE id_categoria = :id_categoria
            FOR UPDATE'
        );

        $bloquearCategoria->execute([
            'id_categoria' => $idCategoria,
        ]);

        $consultaOrden = $conexion->prepare(
            'SELECT COALESCE(MAX(orden_visual), 0) + 1
            FROM categoria_atributos
            WHERE id_categoria = :id_categoria'
        );

        $consultaOrden->execute([
            'id_categoria' => $idCategoria,
        ]);

        $ordenVisual = (int) $consultaOrden->fetchColumn();

        $asignar = $conexion->prepare(
            'INSERT INTO categoria_atributos
                (id_categoria, id_atributo, requerido, orden_visual, estado)
             VALUES
                (:id_categoria, :id_atributo, :requerido, :orden_visual, "ACTIVO")'
        );
        $asignar->execute([
            'id_categoria' => $idCategoria,
            'id_atributo' => $idAtributo,
            'requerido' => $requerido,
            'orden_visual' => $ordenVisual,
        ]);

        $idCategoriaAtributo = (int) $conexion->lastInsertId();
        registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'categoria_atributos', $idCategoriaAtributo, null);
        $conexion->commit();

        $_SESSION['mensajeExito'] = 'Atributo asignado correctamente.';
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        if ($error->getCode() === '23000') {
            $_SESSION['mensajeError'] = 'El atributo ya está asignado a esta categoría.';
        } else {
            error_log('Error al asignar el atributo: ' . $error->getMessage());
            $_SESSION['mensajeError'] = 'No fue posible asignar el atributo.';
        }
    }

    volverACategoriaAtributo($idCategoria);
}

$idCategoriaAtributo = filter_input(INPUT_POST, 'id_categoria_atributo', FILTER_VALIDATE_INT);

if (!$idCategoriaAtributo) {
    $_SESSION['mensajeError'] = 'La asignación seleccionada no es válida.';
    volverACategoriaAtributo($idCategoria);
}

if ($accion === 'actualizar') {
    $requerido = filter_input(INPUT_POST, 'requerido', FILTER_VALIDATE_INT);
    $estado = strtoupper(trim(textoEntrada($_POST, 'estado')));

    if (!in_array($requerido, [0, 1], true) || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
        $_SESSION['mensajeError'] = 'Revisa la configuración del atributo.';
        volverACategoriaAtributo($idCategoria);
    }

    try {
        $conexion->beginTransaction();
        $antesAuditoria = consultarRegistroAuditoria($conexion, 'categoria_atributos', $idCategoriaAtributo);
    if ((int) $antesAuditoria['id_categoria'] !== $idCategoria) {
        throw new DomainException('La configuración no pertenece a esta categoría.');
    }
    $actualizar = $conexion->prepare(
            'UPDATE categoria_atributos
             SET requerido = :requerido, estado = :estado
             WHERE id_categoria_atributo = :id_categoria_atributo
               AND id_categoria = :id_categoria'
        );
        $actualizar->execute([
            'requerido' => $requerido,
            'estado' => $estado,
            'id_categoria_atributo' => $idCategoriaAtributo,
            'id_categoria' => $idCategoria,
        ]);

        registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'categoria_atributos', $idCategoriaAtributo, $antesAuditoria);
        $conexion->commit();
    $_SESSION['mensajeExito'] = $actualizar->rowCount() === 1
            ? 'Configuración actualizada correctamente.'
            : 'No se realizaron cambios.';
    } catch (Throwable $error) {
    if ($conexion->inTransaction()) { $conexion->rollBack(); }
        error_log('Error al actualizar la asignación: ' . $error->getMessage());
        $_SESSION['mensajeError'] = 'No fue posible actualizar la configuración.';
    }

    volverACategoriaAtributo($idCategoria);
}

$estado = strtoupper(trim(textoEntrada($_POST, 'estado')));

if (!in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
    $_SESSION['mensajeError'] = 'El estado solicitado no es válido.';
    volverACategoriaAtributo($idCategoria);
}

try {
    $conexion->beginTransaction();
    $antesAuditoria = consultarRegistroAuditoria($conexion, 'categoria_atributos', $idCategoriaAtributo);
    if ((int) $antesAuditoria['id_categoria'] !== $idCategoria) {
        throw new DomainException('La configuración no pertenece a esta categoría.');
    }
    $actualizarEstado = $conexion->prepare(
        'UPDATE categoria_atributos
         SET estado = :estado
         WHERE id_categoria_atributo = :id_categoria_atributo
           AND id_categoria = :id_categoria'
    );
    $actualizarEstado->execute([
        'estado' => $estado,
        'id_categoria_atributo' => $idCategoriaAtributo,
        'id_categoria' => $idCategoria,
    ]);

    registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'categoria_atributos', $idCategoriaAtributo, $antesAuditoria);
    $conexion->commit();
    $_SESSION['mensajeExito'] = $actualizarEstado->rowCount() === 1
        ? 'Estado actualizado correctamente.'
        : 'La asignación no existe o ya tenía ese estado.';
} catch (Throwable $error) {
    if ($conexion->inTransaction()) { $conexion->rollBack(); }
    error_log('Error al cambiar el estado de la asignación: ' . $error->getMessage());
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado.';
}

volverACategoriaAtributo($idCategoria);
