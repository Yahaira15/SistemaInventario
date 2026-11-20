<?php

declare(strict_types=1);

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/servicio.php';
require_once __DIR__ . '/recuperacion.php';

iniciarSesionSegura();
exigirAdministrador();


// Validación de la solicitud antes de modificar datos.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
$token = $_POST['csrf_token'] ?? null;
if (
    !is_string($token)
    || empty($_SESSION['csrf_inventario'])
    || !hash_equals($_SESSION['csrf_inventario'], $token)
) {
    http_response_code(403);
    exit('Solicitud no válida.');
}
$accion = $_POST['accion'] ?? null;
$producto = (int) filter_var($_POST['id_producto'] ?? null, FILTER_VALIDATE_INT);
$tokenResultado = $_POST['resultado'] ?? null;
if ($accion === 'descartar_recuperacion') {
    descartarResultadoInventario($tokenResultado, $producto);
    header('Location: /sistemaInventario/administracion/inventario?' . http_build_query(filtrosInventario($_POST)), true, 303);
    exit;
}
if (!in_array($accion, ['asignar', 'devolver'], true)) {
    http_response_code(400);
    exit('Acción no válida.');
}
$conexion = require __DIR__ . '/../../config/conexion.php';
$exito = '';
$mensajeError = '';

try {
    $producto = (int) filter_var($_POST['id_producto'] ?? null, FILTER_VALIDATE_INT);
    $campoDestino = $accion === 'asignar' ? 'id_usuario' : 'id_asignacion';
    $destino = (int) filter_var($_POST[$campoDestino] ?? null, FILTER_VALIDATE_INT);
    $idAdministrador = (int) ($_SESSION['administrador_id'] ?? 0);
    
    modificarAsignacionInventario(
        $conexion, 
        $accion, 
        $producto, 
        $destino,
        $idAdministrador
    );
    
    $exito = $accion === 'asignar'
        ? 'Responsable asignado correctamente.'
        : 'Devolución registrada correctamente.';
} catch (DomainException $error) {
    $mensajeError = $error->getMessage();
} catch (Throwable $error) {
    error_log('Error de inventario: ' . $error->getMessage());
    $mensajeError = 'No fue posible guardar la operación. Actualiza el listado e inténtalo de nuevo.';
}

$resultado = ['id_producto' => $producto, 'exito' => $exito];
if ($mensajeError !== '') {
    $resultado['formulario'] = [
        'error' => $mensajeError,
        'id_producto' => $producto,
        'id_usuario' => $accion === 'asignar' ? max(0, $destino) : 0,
        'responsable_texto' => $accion === 'asignar' && is_string($_POST['responsable_texto'] ?? null)
            ? mb_substr($_POST['responsable_texto'], 0, 100)
            : '',
    ];
}

// Regresa al listado conservando los filtros y evitando reenviar el formulario.
descartarResultadoInventario($tokenResultado, $producto);
$tokenRetorno = guardarResultadoInventario($resultado);
$parametros = http_build_query(filtrosInventario($_POST) + ['resultado' => $tokenRetorno]);
header('Location: /sistemaInventario/administracion/inventario?' . $parametros, true, 303);
exit;
