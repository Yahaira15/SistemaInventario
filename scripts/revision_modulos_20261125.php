<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Lecturas sobre datos reales; las escrituras de reproducción usan los fixtures temporales.
$real = require __DIR__ . '/../config/conexion.php';
$sql = 'SELECT p.id_producto, a.nombre AS atributo FROM productos p
    JOIN categoria_atributos ca ON ca.id_categoria=p.id_categoria
    JOIN atributos a ON a.id_atributo=ca.id_atributo
    LEFT JOIN producto_atributo_valores v ON v.id_producto=p.id_producto AND v.id_categoria_atributo=ca.id_categoria_atributo
    WHERE p.estado="ACTIVO" AND ca.estado="ACTIVO" AND a.estado="ACTIVO"
    AND ca.requerido=1 AND v.id_producto IS NULL ORDER BY p.id_producto,a.nombre';
echo 'ATRIBUTOS PENDIENTES: ', json_encode($real->query($sql)->fetchAll(), JSON_UNESCAPED_UNICODE), PHP_EOL;

// Comprueba el alcance público sin imprimir cédulas, nombres ni fotos.
foreach (['ADMINISTRADOR', 'INACTIVO'] as $caso) {
    $where = $caso === 'ADMINISTRADOR' ? 'rol="ADMINISTRADOR"' : 'estado="INACTIVO"';
    $cedula = $real->query('SELECT cedula FROM usuarios WHERE ' . $where . ' LIMIT 1')->fetchColumn();
    if ($cedula === false) { echo 'CONSULTA ', $caso, ': sin fixture real', PHP_EOL; continue; }
    $contexto = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['cedula' => $cedula]), 'timeout' => 10,
    ]]);
    $html = file_get_contents('http://localhost/sistemaInventario/consultas', false, $contexto);
    echo 'CONSULTA PUBLICA ', $caso, ': ', str_contains((string)$html, 'Resultado de la consulta') ? 'MUESTRA FICHA' : 'NO MUESTRA FICHA', PHP_EOL;
}

require __DIR__ . '/probar_guardado_codigos.php';
require_once __DIR__ . '/../auth/formularios.php';
$anterior = consultarProducto($c, $corregido);
$edicion = array_replace($base, [
    'generar_codigo' => '0', 'id_codigo' => $anterior['id_codigo'],
    'version' => $anterior['version'], 'atributos' => [1 => '8'],
]);
guardarPrueba($c, array_replace($edicion, ['descripcion' => 'Cambio de A']), $corregido);
$_SESSION = [];
$_POST = array_replace($edicion, ['accion' => 'actualizar', 'id_producto' => $corregido, 'descripcion' => 'Borrador de B']);
try {
    guardarPrueba($c, $_POST, $corregido);
    throw new RuntimeException('No se detectó el conflicto inicial');
} catch (DomainException $error) { $_SESSION['mensajeError'] = $error->getMessage(); }
$url = urlRetornoFormulario('productos', '/sistemaInventario/administracion/productos');
parse_str((string)parse_url($url, PHP_URL_QUERY), $_GET);
$borrador = recuperarFormulario('productos', $corregido);
$actual = consultarProducto($c, $corregido);
// index.php:75 recupera valores del borrador, pero :231 emite la versión actual de BD.
$reenvio = array_replace($actual, $borrador, ['version' => $actual['version']]);
guardarPrueba($c, $reenvio, $corregido);
verificar(consultarProducto($c, $corregido)['descripcion'] === 'Borrador de B',
    'HALLAZGO: reenviar el formulario recuperado sobrescribe el cambio de A');
echo "Revisión finalizada sin modificar registros reales.\n";
