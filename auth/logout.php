<?php

declare(strict_types=1);

require_once __DIR__ . '/sesion.php';
require_once __DIR__ . '/entrada.php';
iniciarSesionSegura();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

$csrfToken = textoEntrada($_POST, 'csrf_token');

if (
    empty($_SESSION['csrf_logout'])
    || !hash_equals($_SESSION['csrf_logout'], $csrfToken)
) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

destruirSesionActual();
header('Location: /sistemaInventario/');
exit;
