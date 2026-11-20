<?php

declare(strict_types=1);

/** Reglas de datos faltantes; no exige que los catálogos históricos sigan activos. */
function reglasAlertasDashboard(): array
{
    return [
        'codigo' => [
            'titulo' => 'Sin código vigente',
            'descripcion' => 'Productos sin un código de inventario vigente con valor.',
            'condicion' => "NOT EXISTS (SELECT 1 FROM producto_codigos pc
                JOIN codigos_inventario ci ON ci.id_codigo = pc.id_codigo
                WHERE pc.producto_vigente = p.id_producto AND TRIM(COALESCE(ci.codigo, '')) <> '')",
        ],
        'descripcion' => [
            'titulo' => 'Sin descripción',
            'descripcion' => 'Productos cuya descripción está vacía.',
            'condicion' => "TRIM(COALESCE(p.descripcion, '')) = ''",
        ],
        'clasificacion' => [
            'titulo' => 'Sin área o categoría',
            'descripcion' => 'Productos sin una referencia existente de área o categoría.',
            'condicion' => 'NOT EXISTS (SELECT 1 FROM areas a WHERE a.id_area = p.id_area)
                OR NOT EXISTS (SELECT 1 FROM categorias c WHERE c.id_categoria = p.id_categoria)',
        ],
        'atributos' => [
            'titulo' => 'Atributos obligatorios pendientes',
            'descripcion' => 'Productos con al menos un atributo requerido activo sin completar.',
            'condicion' => "EXISTS (
                SELECT 1 FROM categoria_atributos ca
                JOIN atributos a ON a.id_atributo = ca.id_atributo
                WHERE ca.id_categoria = p.id_categoria AND ca.requerido = 1
                  AND ca.estado = 'ACTIVO' AND a.estado = 'ACTIVO'
                  AND NOT EXISTS (
                    SELECT 1 FROM producto_atributo_valores v
                    WHERE v.id_producto = p.id_producto AND v.id_categoria_atributo = ca.id_categoria_atributo
                      AND CASE a.tipo_dato
                        WHEN 'TEXTO' THEN TRIM(COALESCE(v.valor_texto, '')) <> ''
                        WHEN 'NUMERO' THEN v.valor_numero IS NOT NULL
                        WHEN 'FECHA' THEN v.valor_fecha IS NOT NULL
                        WHEN 'BOOLEANO' THEN v.valor_booleano IS NOT NULL
                        WHEN 'SELECCION' THEN EXISTS (SELECT 1 FROM atributo_opciones o
                            WHERE o.id_atributo_opcion = v.id_atributo_opcion AND o.id_atributo = a.id_atributo)
                        ELSE 0 END
                  )
            )",
        ],
    ];
}

function consultarAlertasDashboard(PDO $db, array $entrada): array
{
    $reglas = reglasAlertasDashboard();
    $campos = [];
    foreach ($reglas as $clave => $regla) {
        $campos[] = 'COALESCE(SUM((' . $regla['condicion'] . ')), 0) AS ' . $clave;
    }
    $totales = $db->query('SELECT ' . implode(', ', $campos) . " FROM productos p WHERE p.estado = 'ACTIVO'")->fetch();
    foreach ($reglas as $clave => &$regla) {
        $regla['total'] = (int) $totales[$clave];
    }
    unset($regla);
    $seleccion = is_string($entrada['alerta'] ?? null) && isset($reglas[$entrada['alerta']]) ? $entrada['alerta'] : '';
    $paginaEntrada = filter_var($entrada['pagina_alerta'] ?? null, FILTER_VALIDATE_INT);
    $total = $seleccion !== '' ? $reglas[$seleccion]['total'] : 0;
    $paginas = max(1, (int) ceil($total / 10));
    $pagina = min($paginas, max(1, (int) $paginaEntrada));
    $productos = [];
    if ($seleccion !== '' && $total > 0) {
        $offset = ($pagina - 1) * 10;
        // La condición solo procede de las reglas internas, nunca de la entrada HTTP.
        $productos = $db->query(
            "SELECT p.id_producto, p.descripcion, c.nombre AS categoria, a.nombre AS area
             FROM productos p
             LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
             LEFT JOIN areas a ON a.id_area = p.id_area
             WHERE p.estado = 'ACTIVO' AND (" . $reglas[$seleccion]['condicion'] . ")
             ORDER BY p.id_producto DESC LIMIT 10 OFFSET $offset"
        )->fetchAll();
    }
    return compact('reglas', 'seleccion', 'productos', 'total', 'pagina', 'paginas');
}
