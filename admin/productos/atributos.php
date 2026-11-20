<?php

declare(strict_types=1);

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();
header('Content-Type: application/json; charset=utf-8');

$idCategoria = filter_input(INPUT_GET, 'categoria', FILTER_VALIDATE_INT);
$idProducto = filter_input(INPUT_GET, 'producto', FILTER_VALIDATE_INT) ?: 0;
if (!$idCategoria) {
    http_response_code(400);
    echo json_encode(['error' => 'Categoría no válida.']);
    exit;
}

$conexion = require __DIR__ . '/../../config/conexion.php';
$consulta = $conexion->prepare(
    'SELECT ca.id_categoria_atributo, ca.requerido,
            a.id_atributo, a.nombre, a.tipo_dato,
            a.longitud_maxima, a.valor_minimo, a.valor_maximo, a.decimales,
            pav.valor_texto, pav.valor_numero, pav.valor_fecha,
            pav.valor_booleano, pav.id_atributo_opcion, pav.id_atributo_unidad
     FROM categoria_atributos ca
     INNER JOIN atributos a ON a.id_atributo = ca.id_atributo
     LEFT JOIN producto_atributo_valores pav
       ON pav.id_categoria_atributo = ca.id_categoria_atributo
      AND pav.id_producto = :id_producto
     WHERE ca.id_categoria = :id_categoria
       AND ca.estado = "ACTIVO" AND a.estado = "ACTIVO"
     ORDER BY ca.orden_visual, a.nombre'
);
$consulta->execute(['id_producto' => $idProducto, 'id_categoria' => $idCategoria]);
$atributos = $consulta->fetchAll();

$consultaOpciones = $conexion->prepare(
    'SELECT id_atributo_opcion, valor, estado
     FROM atributo_opciones
     WHERE id_atributo = :id_atributo AND (estado = "ACTIVO" OR id_atributo_opcion = :opcion_actual)
     ORDER BY orden_visual, valor'
);
foreach ($atributos as &$atributo) {
    $atributo['unidades'] = [];
    if ($atributo['tipo_dato'] === 'NUMERO') {
        $qUnidades = $conexion->prepare('SELECT id_atributo_unidad, simbolo, estado FROM atributo_unidades WHERE id_atributo = ? AND (estado = "ACTIVO" OR id_atributo_unidad = ?) ORDER BY simbolo');
        $qUnidades->execute([$atributo['id_atributo'], $atributo['id_atributo_unidad'] ?? 0]);
        $atributo['unidades'] = $qUnidades->fetchAll();
    }
    $atributo['opciones'] = [];
    if ($atributo['tipo_dato'] === 'SELECCION') {
        $consultaOpciones->execute(['id_atributo' => $atributo['id_atributo'], 'opcion_actual' => $atributo['id_atributo_opcion'] ?? 0]);
        $atributo['opciones'] = $consultaOpciones->fetchAll();
    }
}
unset($atributo);

echo json_encode(['atributos' => $atributos], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
