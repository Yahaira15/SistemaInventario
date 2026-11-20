<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/sesion.php';
require_once __DIR__ . '/../../../auth/proteger.php';
require_once __DIR__ . '/../servicio.php';

iniciarSesionSegura();
exigirAdministrador();
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$producto = filter_var($_GET['producto'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($producto === false || $producto === null || $pagina === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Selecciona un producto y una página válidos.']);
    exit;
}

try {
    $db = require __DIR__ . '/../../../config/conexion.php';
    $historial = consultarHistorialProducto($db, (int) $producto, (int) $pagina);
    if ($historial === null) {
        http_response_code(404);
        echo json_encode(['error' => 'El producto no existe.']);
        exit;
    }
    echo json_encode($historial, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Error al consultar historial: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No fue posible consultar el historial.']);
}
