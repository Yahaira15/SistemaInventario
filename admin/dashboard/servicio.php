<?php

declare(strict_types=1);

function consultarResumenDashboard(PDO $db, array $entrada = []): array
{
    // EXISTS cuenta cada producto una sola vez, aunque tenga varios responsables.
    $resumen = $db->query(
        "SELECT COUNT(*) AS activos,
                COALESCE(SUM(EXISTS (
                    SELECT 1 FROM producto_asignaciones pa
                    WHERE pa.id_producto = p.id_producto
                      AND pa.fecha_devolucion IS NULL
                )), 0) AS asignados
         FROM productos p WHERE p.estado = 'ACTIVO'"
    )->fetch();

    $activos = (int) $resumen['activos'];
    $asignados = (int) $resumen['asignados'];

    $areas = $db->query(
        "SELECT a.id_area, a.nombre, COUNT(p.id_producto) AS total
         FROM areas a LEFT JOIN productos p
           ON p.id_area = a.id_area AND p.estado = 'ACTIVO'
         GROUP BY a.id_area, a.nombre
         ORDER BY total DESC, a.nombre ASC"
    )->fetchAll();

    $idArea = filter_var($entrada['grafica_area'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $idArea = $idArea !== false && in_array($idArea, array_map('intval', array_column($areas, 'id_area')), true) ? $idArea : 0;
    $asignacion = is_string($entrada['grafica_asignacion'] ?? null) && in_array($entrada['grafica_asignacion'], ['CON', 'SIN'], true) ? $entrada['grafica_asignacion'] : '';
    $condiciones = ["p.estado = 'ACTIVO'"];
    $parametros = [];
    if ($idArea > 0) {
        $condiciones[] = 'p.id_area = ?';
        $parametros[] = $idArea;
    }
    if ($asignacion !== '') {
        $condiciones[] = ($asignacion === 'SIN' ? 'NOT ' : '') . 'EXISTS (
            SELECT 1 FROM producto_asignaciones pa
            WHERE pa.id_producto = p.id_producto AND pa.fecha_devolucion IS NULL)';
    }
    $consulta = $db->prepare(
        'SELECT c.id_categoria, c.nombre, COUNT(*) AS total FROM productos p
         INNER JOIN categorias c ON c.id_categoria = p.id_categoria
         WHERE ' . implode(' AND ', $condiciones) . '
         GROUP BY c.id_categoria, c.nombre ORDER BY total DESC, c.nombre ASC, c.id_categoria ASC'
    );
    $consulta->execute($parametros);
    $categoriasFiltradas = $consulta->fetchAll();
    $totalCategorias = (int) array_sum(array_column($categoriasFiltradas, 'total'));
    $categorias = array_slice($categoriasFiltradas, 0, 6);

    $anio = (int) (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y');
    $meses = [];
    foreach (['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'] as $indice => $nombre) {
        $meses[$indice + 1] = ['nombre' => $nombre, 'ingresos' => 0, 'bajas' => 0];
    }
    // Las ingresos son registros de productos, incluso si posteriormente se dieron de baja.
    $consultas = [
        'ingresos' => 'SELECT MONTH(fecha_creacion) AS mes, COUNT(*) AS total FROM productos
                    WHERE fecha_creacion >= ? AND fecha_creacion < ? GROUP BY MONTH(fecha_creacion)',
        'bajas' => "SELECT MONTH(fecha_movimiento) AS mes, COUNT(*) AS total FROM inventario_movimientos
                    WHERE tipo_movimiento = 'BAJA' AND fecha_movimiento >= ? AND fecha_movimiento < ?
                    GROUP BY MONTH(fecha_movimiento)",
    ];
    foreach ($consultas as $tipo => $sql) {
        $consulta = $db->prepare($sql);
        $consulta->execute([$anio . '-01-01', ($anio + 1) . '-01-01']);
        foreach ($consulta->fetchAll() as $fila) {
            $meses[(int) $fila['mes']][$tipo] = (int) $fila['total'];
        }
    }
 
    return [
        'activos' => $activos,
        'asignados' => $asignados,
        'sin_asignacion' => $activos - $asignados,
        'porcentaje_asignado' => $activos > 0 ? (int) round($asignados * 100 / $activos) : 0,
        'categorias' => $categorias,
        'total_categorias' => $totalCategorias,
        'filtros_categorias' => ['area' => $idArea, 'asignacion' => $asignacion],
        'areas' => $areas,
        'anio' => $anio,
        'meses' => $meses,
    ];
}
