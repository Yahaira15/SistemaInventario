<?php

declare(strict_types=1);

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/servicio.php';

iniciarSesionSegura();
exigirAdministrador();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// La consulta de códigos solo admite solicitudes GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');

    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$opciones = ['options' => ['min_range' => 1]];

$idCategoria = filter_input(
    INPUT_GET,
    'categoria',
    FILTER_VALIDATE_INT,
    $opciones
);

// El producto es opcional: se envía unicamente al editar
$idProducto = null;

if (isset($_GET['producto'])) {
    $idProducto = filter_input(
        INPUT_GET,
        'producto',
        FILTER_VALIDATE_INT,
        $opciones
    );
}

if (!$idCategoria || $idProducto === false) {
    http_response_code(400);

    echo json_encode([
        'error' => 'La categoría o el producto no son válidos.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conexion = require __DIR__ . '/../../config/conexion.php';

try {
    $codigos = consultarCodigosDisponibles(
        $conexion,
        $idCategoria,
        $idProducto
    );

    echo json_encode(
        ['codigos' => $codigos],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
} catch (Throwable $error) {
    error_log('Error al consultar códigos: ' . $error->getMessage());

    http_response_code(500);

    echo json_encode([
        'error' => 'No fue posible cargar los códigos.',
    ], JSON_UNESCAPED_UNICODE);
}

?>