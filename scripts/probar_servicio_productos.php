<?php
// El servicio usa bloqueos y códigos vigentes de MariaDB; SQLite ya no representa este flujo.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/probar_productos_mysql.php';
require_once __DIR__ . '/../auth/formularios.php';
$datos['version'] = 2;
$datos['atributos'][1] = '5';
$datos['id_codigo'] = consultarProducto($c, $id)['id_codigo'];
$datos['generar_codigo'] = '0';
foreach (['1e2', '999999999999999', '5.1'] as $numero) {
    try {
        guardarMysqlPrueba($c, array_replace($datos, ['atributos' => [1 => $numero, 2 => '1']]), $id);
        throw new RuntimeException('Se aceptó un número inválido');
    } catch (DomainException $error) {
        verificarMysql((int) consultarProducto($c, $id)['version'] === 2, 'Número inválido no modifica producto: ' . $numero);
    }
}
$copia = $datos;
$copia['token_creacion'] = bin2hex(random_bytes(32));
try {
    guardarMysqlPrueba($c, $copia);
    throw new RuntimeException('Se reutilizó un código ocupado');
} catch (DomainException $error) {
    verificarMysql((int) $c->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 1, 'Código ocupado no deja productos parciales');
}
$_SESSION = ['mensajeError' => 'Error'];
$_POST = $datos + ['accion' => 'actualizar', 'id_producto' => $id];
$url = urlRetornoFormulario('productos', '/productos');
parse_str(parse_url($url, PHP_URL_QUERY), $_GET);
$recuperado = recuperarFormulario('productos', $id);
verificarMysql($recuperado['atributos'] === $datos['atributos'] && $recuperado['descripcion'] === $datos['descripcion'], 'Recupera formulario completo tras un error');
$error = new PDOException('Duplicate entry');
$error->errorInfo = ['23000', 1062];
verificarMysql(str_contains(mensajeErrorProducto($error), 'código'), 'Distingue código ocupado');
$error->errorInfo = ['23000', 1452];
verificarMysql(str_contains(mensajeErrorProducto($error), 'relacionada'), 'Distingue una referencia inexistente');