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
$buscar = is_string($_GET['buscar'] ?? null) ? trim($_GET['buscar']) : '';
$producto = filter_var($_GET['producto'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$producto || mb_strlen($buscar) < 2 || mb_strlen($buscar) > 100) {
    echo json_encode([]);
    exit;
}
try {
    $conexion = require __DIR__ . '/../../../config/conexion.php';
    echo json_encode(buscarResponsablesInventario($conexion, $buscar, $producto), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Error al buscar responsables: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No fue posible buscar usuarios.']);
}
