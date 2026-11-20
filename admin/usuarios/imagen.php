<?php
declare(strict_types=1);
require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
iniciarSesionSegura();
exigirAdministrador();
session_write_close();
$id = filter_input(INPUT_GET, 'usuario', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit; }
$db = require __DIR__ . '/../../config/conexion.php';
$q = $db->prepare('SELECT mime, contenido FROM usuario_imagenes WHERE id_usuario = ?');
$q->execute([$id]);
$imagen = $q->fetch();
if (!$imagen || !in_array($imagen['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404); exit;
}
header('Content-Type: ' . $imagen['mime']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Content-Length: ' . strlen($imagen['contenido']));
echo $imagen['contenido'];
