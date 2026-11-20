<?php

declare(strict_types = 1);

require_once __DIR__ . '/../../../auth/busqueda.php';

function filtrosBajas(array $entrada): array {
    $buscar = is_string($entrada['buscar'] ?? null)
    ? trim($entrada['buscar'])
    : '';

    $entero = static function (string $campo) use ($entrada): int {
        $valor = filter_var(
            $entrada[$campo] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        
        return $valor === false || $valor === null ? 0 : $valor;
    };

    $fechaFiltro = static function (string $campo) use ($entrada): string {
        $valor = $entrada[$campo] ?? '';
        if (!is_string($valor)) {
            throw new DomainException('Indica fechas válidas para el filtro de bajas.');
        }
        $valor = trim($valor);
        if ($valor === '') {
            return '';
        }
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if (!$fecha || $fecha->format('Y-m-d') !== $valor || $valor < '1000-01-01' || $valor > '9999-12-31') {
            throw new DomainException('Indica fechas válidas para el filtro de bajas.');
        }
        return $valor;
    };
    $desde = $fechaFiltro('desde');
    $hasta = $fechaFiltro('hasta');
    if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
        throw new DomainException('La fecha Desde no puede ser posterior a la fecha Hasta.');
    }

    return [
        'buscar' => mb_substr($buscar, 0, 100),
        'categoria' => $entero('categoria'),
        'area' =>  $entero('area'),
        'pagina' => max(1, $entero('pagina')),
        'desde' => $desde,
        'hasta' => $hasta,
    ];
}

function consultarListadoBajas (PDO $conexion, array $filtros, bool $exportar = false): array {
    // Obtiene  un solo movimiento de bajay un solo código por producto
    $desde = '
        FROM productos p
        JOIN categorias c ON c.id_categoria = p.id_categoria
        JOIN areas a ON a.id_area = p.id_area  
        LEFT JOIN inventario_movimientos m
            ON m.id_movimiento = (
                SELECT MAX(m2.id_movimiento)
                FROM inventario_movimientos m2
                WHERE m2.id_producto = p.id_producto
                  AND m2.tipo_movimiento = "BAJA"
            )
        LEFT JOIN usuarios u 
            ON u.id_usuario = m.id_usuario_registro
        LEFT JOIN producto_codigos pc
            ON pc.id_producto_codigo = (
            SELECT pc2.id_producto_codigo
            FROM producto_codigos pc2
            WHERE pc2.id_producto = p.id_producto
            ORDER BY pc2.fecha_inicio DESC,
                     pc2.id_producto_codigo DESC
            LIMIT 1
            )    
        LEFT JOIN codigos_inventario ci
            ON ci.id_codigo = pc.id_codigo
    ';

    $condiciones = ['p.estado = "INACTIVO"'];
    $valores = [];

    if ($filtros['buscar'] !== '')  {
        $condiciones[] = '(
            p.descripcion LIKE ? ESCAPE "!"
            OR ci.codigo LIKE ? ESCAPE "!"
        )';

        $patron = patronBusqueda($filtros['buscar']);
        $valores[] = $patron;
        $valores[] = $patron;
    } 

    if ($filtros['categoria'] > 0) {
        $condiciones[] = 'p.id_categoria = ?';
        $valores[] = $filtros['categoria']; 
    }

    if ($filtros['area'] > 0) {
        $condiciones[] = 'p.id_area = ?';
        $valores[] = $filtros['area'];
    }

    if (($filtros['desde'] ?? '') !== '') {
        $condiciones[] = 'm.fecha_movimiento >= ?';
        $valores[] = $filtros['desde'] . ' 00:00:00';
    }
    if (($filtros['hasta'] ?? '') !== '') {
        $condiciones[] = 'm.fecha_movimiento <= ?';
        $valores[] = $filtros['hasta'] . ' 23:59:59';
    }

    $donde = ' WHERE ' . implode(' AND ', $condiciones);

    $consulta = $conexion->prepare(
        'SELECT COUNT(*)' . $desde . $donde
    );
    $consulta->execute($valores);

    $total = (int) $consulta->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / 15));
    $pagina = min($filtros['pagina'], $totalPaginas);
    $offset = ($pagina -1) * 15;
    $limite = $exportar ? '' : " LIMIT 15 OFFSET $offset";

    $consulta = $conexion->prepare(
        'SELECT 
            p.id_producto,
            p.descripcion,
            c.nombre AS categoria,
            a.nombre AS area,
            ci.codigo,
            m.id_movimiento,
            m.fecha_movimiento,
            m.motivo,
            CONCAT(u.nombre, " ", u.apellido) AS administrador,
            (SELECT CONCAT(ultimo_usuario.nombre, " ", ultimo_usuario.apellido)
             FROM producto_asignaciones ultima_asignacion
             LEFT JOIN usuarios ultimo_usuario
                 ON ultimo_usuario.id_usuario = ultima_asignacion.id_usuario
             WHERE ultima_asignacion.id_producto = p.id_producto
             ORDER BY ultima_asignacion.fecha_asignacion DESC,
                      ultima_asignacion.id_producto_asignacion DESC
             LIMIT 1) AS ultimo_responsable'
        . $desde
        . $donde
        . ' ORDER BY m.fecha_movimiento DESC, p.id_producto DESC'
        . $limite
    );

    $consulta->execute($valores);
    $productos = $consulta->fetchAll(PDO::FETCH_ASSOC);

    return compact(
        'productos',
        'total',
        'pagina',
        'totalPaginas'
    );
}




?>
