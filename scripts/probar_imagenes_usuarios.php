<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../admin/usuarios/imagenes.php';
require_once __DIR__ . '/soporte_auditoria_pruebas.php';
require_once __DIR__ . '/../admin/auditoria/cambios.php';

function comprobarImagen(bool $ok, string $mensaje): void {
    if (!$ok) throw new RuntimeException($mensaje);
    echo "OK: $mensaje\n";
}
function rechazarImagen(callable $accion, string $mensaje): void {
    try { $accion(); } catch (DomainException $error) { comprobarImagen(true, $mensaje); return; }
    throw new RuntimeException($mensaje);
}
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6XkAAAAASUVORK5CYII=', true);
$imagen = validarImagenUsuario($png);
comprobarImagen($imagen['mime'] === 'image/png' && $imagen['contenido'] === $png, 'Detecta el formato por el contenido real');
comprobarImagen(leerImagenUsuario(null) === null && leerImagenUsuario(['error' => UPLOAD_ERR_NO_FILE]) === null, 'Imagen opcional sin selección');
rechazarImagen(fn () => validarImagenUsuario('<?php echo "invalido";'), 'Rechaza archivos que no son imágenes');
rechazarImagen(fn () => validarImagenUsuario('<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'Rechaza SVG');
rechazarImagen(fn () => validarImagenUsuario(str_repeat('x', MAX_IMAGEN_USUARIO + 1)), 'Rechaza más de 2 MB');
$grande = substr_replace($png, pack('N', 5000), 16, 4);
rechazarImagen(fn () => validarImagenUsuario($grande), 'Rechaza dimensiones superiores a 4096 píxeles');
foreach ([['error' => UPLOAD_ERR_PARTIAL], ['error' => UPLOAD_ERR_INI_SIZE], ['error' => ['0']], ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__]] as $archivo) {
    rechazarImagen(fn () => leerImagenUsuario($archivo), 'Rechaza errores de carga y rutas locales no subidas por HTTP');
}
$db = require __DIR__ . '/../config/conexion.php';
prepararAuditoriaPruebas($db);
$ddl = $db->query('SHOW CREATE TABLE usuario_imagenes')->fetch(PDO::FETCH_NUM)[1];
$ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
$ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
$ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
$db->exec($ddl);
try {
    guardarImagenUsuario($db, 1, $imagen);
    throw new RuntimeException('Se permitió guardar fuera de una transacción');
} catch (LogicException $error) {
    comprobarImagen(true, 'Exige la transacción del usuario');
}
$db->beginTransaction();
guardarImagenUsuario($db, 1, $imagen);
$db->commit();
comprobarImagen(tieneImagenUsuario($db, 1), 'Guarda la imagen del usuario');
comprobarImagen($db->query('SELECT contenido FROM usuario_imagenes WHERE id_usuario=1')->fetchColumn() === $png, 'Conserva los bytes de la imagen');
$db->beginTransaction();
guardarImagenUsuario($db, 1, $imagen);
$db->commit();
comprobarImagen((int) $db->query('SELECT COUNT(*) FROM usuario_imagenes')->fetchColumn() === 1, 'Reemplazar no duplica la imagen');
$anterior = $db->query('SELECT nombre FROM usuarios WHERE id_usuario=1')->fetchColumn();
$db->beginTransaction();
$antesAuditoria = consultarRegistroAuditoria($db, 'usuarios', 1);
$db->exec('UPDATE usuarios SET nombre="Cambio temporal" WHERE id_usuario=1');
guardarImagenUsuario($db, 1, validarImagenUsuario($png . 'otra imagen'));
registrarCambioAuditoria($db, 1, 'usuarios', 1, $antesAuditoria, ['imagen_actualizada' => 'Sí']);
$db->rollBack();
comprobarImagen($db->query('SELECT contenido FROM usuario_imagenes WHERE id_usuario=1')->fetchColumn() === $png
    && $db->query('SELECT nombre FROM usuarios WHERE id_usuario=1')->fetchColumn() === $anterior
    && (int) $db->query('SELECT COUNT(*) FROM auditoria_eventos')->fetchColumn() === 0, 'Rollback revierte imagen, usuario y auditoría juntos');
$db->beginTransaction();
guardarImagenUsuario($db, 1, $imagen);
registrarCambioAuditoria($db, 1, 'usuarios', 1, $antesAuditoria, ['imagen_actualizada' => 'Sí']);
$db->commit();
$evento = $db->query('SELECT datos_nuevos FROM auditoria_eventos LIMIT 1')->fetchColumn();
comprobarImagen(json_decode($evento, true)['imagen_actualizada'] === 'Sí' && !str_contains($evento, $png), 'Audita la imagen sin incluir sus bytes');
echo "Imágenes verificadas en tablas temporales, sin modificar usuarios reales.\n";
