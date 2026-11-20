<?php

declare(strict_types=1);

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/../../auth/formularios.php';
require_once __DIR__ . '/servicio.php';

iniciarSesionSegura();
exigirAdministrador();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || empty($_SESSION['csrf_productos']) || !hash_equals($_SESSION['csrf_productos'], $token)) {
    http_response_code(403);
    exit('Solicitud no válida.');
}
$accion = $_POST['accion'] ?? '';
if (!in_array($accion, ['crear', 'actualizar', 'dar_baja'], true)) {
    http_response_code(400);
    exit('Acción no válida.');
}

unset($_SESSION['mensajeError'], $_SESSION['mensajeExito']);
$conexion = require __DIR__ . '/../../config/conexion.php';
try {
    $id = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT) ?: 0;
    if ($accion === 'dar_baja') {
        darDeBajaProducto(
            $conexion,
            $id,
            filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT) ?: 0,
            null,
            is_string($_POST['motivo'] ?? null) ? $_POST['motivo'] : '',
            (int) $_SESSION['administrador_id']
        );
        $_SESSION['mensajeExito'] = 'Equipo dado de baja. Sus asignaciones se cerraron y su código quedó disponible.';
    } else {
        $entrada = $_POST;
        if ($accion === 'crear') {
            $operacion = $entrada['token_creacion'] ?? null;
            if (!is_string($operacion) || !preg_match('/^[a-f0-9]{64}$/D', $operacion)) {
                throw new DomainException('La solicitud de creación no es válida. Abre un formulario nuevo.');
            }
            // Separa las operaciones de administradores diferentes.
            $entrada['token_creacion'] = hash('sha256', $_SESSION['administrador_id'] . ':' . $operacion);
        }
        guardarProducto($conexion, $entrada, $accion === 'crear' ? null : $id, (int) $_SESSION['administrador_id']);
        $_SESSION['mensajeExito'] = $accion === 'crear' ? 'Producto creado correctamente.' : 'Producto actualizado correctamente.';
    }
} catch (PDOException $error) {
    error_log('Error de base de datos en productos: ' . $error->getMessage());
    $_SESSION['mensajeError'] = mensajeErrorProducto($error);
} catch (DomainException $error) {
    $_SESSION['mensajeError'] = $error->getMessage();
} catch (Throwable $error) {
    error_log('Error inesperado en productos: ' . $error->getMessage());
    $_SESSION['mensajeError'] = 'No fue posible guardar el producto. Intenta nuevamente.';
}
if ($accion === 'dar_baja' && isset($_SESSION['mensajeError']) && $id > 0) {
    $_SESSION['baja_productos'][$id] = [
        'motivo' => is_string($_POST['motivo'] ?? null) ? mb_substr($_POST['motivo'], 0, 500) : '',
    ];
    if (($_POST['modal_baja'] ?? null) === '1') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => true, 'url' => '/sistemaInventario/administracion/bajas?id=' . $id]);
        exit;
    }
    header('Location: /sistemaInventario/administracion/bajas?id=' . $id, true, 303);
    exit;
}
if ($accion === 'dar_baja' && ($_POST['modal_baja'] ?? null) === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (isset($_SESSION['mensajeError'])) {
        http_response_code(422);
        echo json_encode(['error' => true, 'mensaje' => $_SESSION['mensajeError']]);
    } else {
        echo json_encode(['exito' => true, 'url' => urlRetornoFormulario('productos', '/sistemaInventario/administracion/productos')]);
    }
    exit;
}
header('Location: ' . urlRetornoFormulario('productos', '/sistemaInventario/administracion/productos'), true, 303);
exit;
