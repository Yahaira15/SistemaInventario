<?php

declare(strict_types=1);

$modulosAuditoria = [
    'INVENTARIO' => 'Inventario',
    'PRODUCTOS' => 'Productos',
    'USUARIOS' => 'Usuarios',
    'AREAS' => 'Áreas', 
    'CATEGORIAS' => 'Categorías',
    'ATRIBUTOS' => 'Atributos',
];

$accionesAuditoria = [
    'CREACION' => 'Creación',
    'EDICION' => 'Edición',
    'DESACTIVACION' => 'Desactivación',
    'REACTIVACION' => 'Reactivación',
    'ASIGNACION' => 'Asignación',
    'DEVOLUCION' => 'Devolución'
];

$filtros = [];

foreach ([
    'desde',
    'hasta',
    'modulo',
    'accion',
    'administrador',
    'codigo'
] as $campo) {
    $valor = $_GET[$campo] ?? '';
    $filtros[$campo] = is_string($valor) ? trim($valor) : '';
}

$erroresFiltros = [];

foreach (['desde', 'hasta'] as $campo) {
    if ($filtros[$campo] === '') {
        continue;
    }

    if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $filtros[$campo]) || $filtros[$campo] > '9999-12-30') {
        $erroresFiltros[] = 'Introduce fechas válidas.';
        $filtros[$campo] = '';
        continue;
    }

    $fecha = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $filtros[$campo]
    );

    if (!$fecha || $fecha->format('Y-m-d') !== $filtros[$campo]) {
        $erroresFiltros[] = 'Introduce fechas válidas.';
        $filtros[$campo] = '';
    }
}

if (
    $filtros['desde'] !== ''
    && $filtros['hasta'] !== ''
    && $filtros['desde'] > $filtros['hasta']
) {
    $erroresFiltros[] = 'La fecha inicial no puede superar la final.';
}

foreach ([
    'modulo' => $modulosAuditoria,
    'accion' => $accionesAuditoria
] as $campo => $opciones) {
    if (
        $filtros[$campo] !== ''
        && !isset($opciones[$filtros[$campo]])
    ) {
        $erroresFiltros[] = 'El módulo o la acción no son válidos.';
        $filtros[$campo] = '';
    }
}

$condiciones = [];
$parametros = [];

// Trata %, _ y ! como caracteres literales en las búsquedas.
$buscarTexto = static function (string $texto): string {
    return '%' . strtr($texto, [
        '!' => '!!',
        '%' => '!%',
        '_' => '!_',
    ]) . '%';
};

if ($filtros['desde'] !== '') {
    $condiciones[] = 'ae.fecha >= :desde';
    $parametros['desde'] = $filtros['desde'] . ' 00:00:00';
}

if ($filtros['hasta'] !== '') {
    // Incluye el día completo sin aplicar funciones sobre ae.fecha.
    $condiciones[] = 'ae.fecha < :hasta';
    $parametros['hasta'] = (new DateTimeImmutable($filtros['hasta']))
        ->modify('+1 day')
        ->format('Y-m-d 00:00:00');
}

foreach (['modulo', 'accion'] as $campo) {
    if ($filtros[$campo] !== '') {
        $condiciones[] = "ae.$campo = :$campo";
        $parametros[$campo] = $filtros[$campo];
    }
}

if ($filtros['administrador'] !== '') {
    $condiciones[] = "ae.administrador_nombre LIKE :administrador ESCAPE '!'";
    $parametros['administrador'] = $buscarTexto($filtros['administrador']);
}

if ($filtros['codigo'] !== '') {
    $condiciones[] = <<<'SQL'
        (
            ae.entidad IN ('productos', 'producto_asignaciones')
            AND (
                JSON_UNQUOTE(
                    JSON_EXTRACT(ae.datos_nuevos, '$.codigo')
                ) LIKE :codigo_nuevo ESCAPE '!'

                OR JSON_UNQUOTE(
                    JSON_EXTRACT(ae.datos_anteriores, '$.codigo')
                ) LIKE :codigo_anterior ESCAPE '!'

                OR (
                    JSON_EXTRACT(ae.datos_nuevos, '$.codigo') IS NULL 
                    AND JSON_EXTRACT(ae.datos_anteriores, '$.codigo') IS NULL
                    AND ci.codigo LIKE :codigo_actual ESCAPE '!'
                )
            )  
        )
        SQL;
    $codigoBuscado = $buscarTexto($filtros['codigo']);

    $parametros['codigo_nuevo'] = $codigoBuscado;
    $parametros['codigo_anterior'] = $codigoBuscado;
    $parametros['codigo_actual'] = $codigoBuscado;
}

if ($erroresFiltros) {
    // No muestra resultados engañosos si el filtro es inválido.
    $condiciones[] = '1 = 0';
}

$desdeSql = <<<'SQL'
    FROM auditoria_eventos ae 
    LEFT JOIN producto_asignaciones pa
        ON ae.entidad = 'producto_asignaciones'
        AND pa.id_producto_asignacion = ae.id_registro
    LEFT JOIN producto_codigos pc 
        ON pc.producto_vigente  = CASE 
            WHEN ae.entidad = 'productos' THEN ae.id_registro
            ELSE pa.id_producto
        END
    LEFT JOIN codigos_inventario ci 
        ON ci.id_codigo = pc.id_codigo
    SQL;

$dondeSql = $condiciones
    ? ' WHERE ' . implode(' AND ', $condiciones)
    : '';

// El exportador reutiliza exactamente las condiciones, sin paginación.
if (($exportarAuditoria ?? false) === true) {
    return;
}
$contar = $conexion->prepare(
    'SELECT COUNT(*) ' . $desdeSql . $dondeSql
);
$contar->execute($parametros);

$total = (int) $contar->fetchColumn();
$porPagina = 25;
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$pagina = filter_var(
    $_GET['pagina'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$pagina = min($pagina === false ? 1 : $pagina, $totalPaginas);
$desplazamiento = ($pagina - 1) * $porPagina;

$consulta = $conexion->prepare(
    'SELECT ae.*, ci.codigo AS codigo_actual '
    . $desdeSql
    . $dondeSql
    . ' ORDER BY ae.id_evento DESC
        LIMIT :limite OFFSET :desplazamiento'
);



foreach ($parametros as $nombre =>$valor) {
    $consulta->bindValue(':' . $nombre, $valor, PDO::PARAM_STR);
}
  
$consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$consulta->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
$consulta->execute();

$eventos = $consulta->fetchAll(PDO::FETCH_ASSOC);

function urlPaginaAuditoria(int $pagina, array $filtros): string {
    $parametros = array_filter(
        $filtros,
        static fn (string $valor) : bool => $valor !== ''
    );

    $parametros['pagina'] = $pagina;

    return '?' . http_build_query($parametros);
}
