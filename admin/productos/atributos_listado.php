<?php

declare(strict_types=1);

/** Consulta en lote los atributos de los productos visibles, conservando valores de definiciones inactivas. */
function consultarAtributosListadoProductos(PDO $db, array $productos): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productos), fn ($id) => $id > 0)));
    if (!$ids) {
        return [];
    }
    $q = $db->prepare(
        'SELECT p.id_producto, a.id_atributo, a.nombre, a.tipo_dato, a.unidad,
            v.valor_texto, v.valor_numero, v.valor_fecha, v.valor_booleano,
            o.valor AS opcion, u.simbolo
         FROM productos p
         JOIN categoria_atributos ca ON ca.id_categoria = p.id_categoria
         JOIN atributos a ON a.id_atributo = ca.id_atributo
         LEFT JOIN producto_atributo_valores v
            ON v.id_producto = p.id_producto AND v.id_categoria_atributo = ca.id_categoria_atributo
         LEFT JOIN atributo_opciones o ON o.id_atributo_opcion = v.id_atributo_opcion
         LEFT JOIN atributo_unidades u ON u.id_atributo_unidad = v.id_atributo_unidad
         WHERE p.id_producto IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
           AND ((ca.estado = "ACTIVO" AND a.estado = "ACTIVO") OR v.id_producto IS NOT NULL)
         ORDER BY p.id_producto, ca.orden_visual, a.nombre, ca.id_categoria_atributo'
    );
    $q->execute($ids);
    $resultado = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $valor = match ($fila['tipo_dato']) {
            'TEXTO' => $fila['valor_texto'],
            'NUMERO' => $fila['valor_numero'],
            'FECHA' => $fila['valor_fecha'],
            'BOOLEANO' => $fila['valor_booleano'] === null ? null : ((int) $fila['valor_booleano'] === 1 ? 'Sí' : 'No'),
            'SELECCION' => $fila['opcion'],
            default => null,
        };
        if ($valor !== null && $valor !== '') {
            if ($fila['tipo_dato'] === 'NUMERO') {
                $valor = str_contains((string) $valor, '.') ? rtrim(rtrim((string) $valor, '0'), '.') : (string) $valor;
                $unidad = $fila['simbolo'] ?? $fila['unidad'];
                if ($unidad !== null && $unidad !== '') {
                    $valor .= ' ' . $unidad;
                }
            } elseif ($fila['tipo_dato'] === 'FECHA') {
                $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $valor);
                $valor = $fecha ? $fecha->format('d/m/Y') : $valor;
            }
        } else {
            $valor = 'Sin valor';
        }
        $resultado[(int) $fila['id_producto']][(int) $fila['id_atributo']] = ['nombre' => $fila['nombre'], 'valor' => (string) $valor];
    }
    return $resultado;
}

/** Obtiene en lote las columnas propias de cada categoría, incluidos valores históricos. */
function consultarColumnasProductosPorCategoria(PDO $db, array $categorias): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $categorias), fn ($id) => $id > 0)));
    if (!$ids) {
        return [];
    }
    $q = $db->prepare(
        'SELECT ca.id_categoria, a.id_atributo, a.nombre, MIN(ca.orden_visual) AS orden
         FROM categoria_atributos ca
         JOIN atributos a ON a.id_atributo = ca.id_atributo
         WHERE ca.id_categoria IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
           AND ((ca.estado = "ACTIVO" AND a.estado = "ACTIVO") OR EXISTS (
               SELECT 1 FROM producto_atributo_valores v
               WHERE v.id_categoria_atributo = ca.id_categoria_atributo
           ))
         GROUP BY ca.id_categoria, a.id_atributo, a.nombre
         ORDER BY ca.id_categoria, orden, a.nombre, a.id_atributo'
    );
    $q->execute($ids);
    $resultado = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $resultado[(int) $fila['id_categoria']][] = $fila;
    }
    return $resultado;
}
