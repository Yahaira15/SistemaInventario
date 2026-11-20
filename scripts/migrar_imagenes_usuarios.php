<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$sql = file_get_contents(__DIR__ . '/usuarios_imagenes.sql');
if (!in_array('--aplicar', $argv, true)) {
    echo $sql, "\nUsa --aplicar para crear la tabla de imágenes. No modifica los usuarios existentes.\n";
    exit;
}
$db = require __DIR__ . '/../config/conexion.php';
$db->exec($sql);
echo "Tabla de imágenes de usuarios preparada.\n";
