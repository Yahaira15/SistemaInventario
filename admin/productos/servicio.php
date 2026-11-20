<?php

declare(strict_types=1);
require_once __DIR__ . '/../auditoria/cambios.php';
require_once __DIR__ . '/../../auth/busqueda.php';

require_once __DIR__ . '/guardar_atributos.php';
require_once __DIR__ . '/atributos_listado.php';
require_once __DIR__ . '/../codigos/servicio.php';
require_once __DIR__ . '/bajas/baja_servicio.php';

function bloqueoProducto(PDO $conexion): string
{
    return $conexion->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

function consultarProducto(PDO $conexion, int $id, bool $bloquear = false): array|false
{
    $consulta = $conexion->prepare(
        'SELECT * FROM productos 
         WHERE id_producto = :id'
    . ($bloquear ? bloqueoProducto($conexion) : '')
    );
    $consulta->execute(['id' => $id]);
    $producto = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        return false;
    }

    // OBTIENE EL CÓDIGO VIGENTE DESDE SU HISTORIAL
    $consulta = $conexion->prepare(
        'SELECT c.id_codigo, c.codigo
         FROM producto_codigos pc
         INNER JOIN codigos_inventario c
            ON c.id_codigo = pc.id_codigo
         WHERE pc.producto_vigente = :id_producto'
         . ($bloquear ? bloqueoProducto($conexion) : '')
    );

    $consulta->execute(['id_producto' => $id]);
    $codigoActual = $consulta->fetch(PDO::FETCH_ASSOC);

    $producto['id_codigo'] = $codigoActual
        ? (int) $codigoActual['id_codigo']
        : null;

    $producto['codigo'] = $codigoActual
        ? $codigoActual['codigo']
        : '';

    return $producto;
}

function valoresProducto(PDO $conexion, int $id): array
{
    $consulta = $conexion->prepare('SELECT id_categoria_atributo, valor_texto, valor_numero, valor_fecha, valor_booleano, id_atributo_opcion, id_atributo_unidad FROM producto_atributo_valores WHERE id_producto = :id ORDER BY id_categoria_atributo');
    $consulta->execute(['id' => $id]);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

function validarDatosProducto(array $entrada): array
{
    foreach (['id_categoria', 'id_area', 'id_codigo', 'generar_codigo', 'descripcion', 'estado'] as $campo) {
        if (isset($entrada[$campo]) && !is_scalar($entrada[$campo])) {
            throw new DomainException('Formato no válido en ' . $campo . '.');
        }
    }
    $datos = [
        'id_categoria' => filter_var($entrada['id_categoria'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
        'id_area' => filter_var($entrada['id_area'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
        'id_codigo' => null,
        'generar_codigo' => false,
        'descripcion' => trim((string) ($entrada['descripcion'] ?? '')),
        'estado' => (string) ($entrada['estado'] ?? 'ACTIVO'),
    ];
    if (!$datos['id_categoria']) {
        throw new DomainException('Categoría: selecciona una categoría válida.');
    }
    if (!$datos['id_area']) {
        throw new DomainException('Área: selecciona un área válida.');
    }
    // El formulario envía generar_codigo=1 o el ID de un código existente.
    $generar = $entrada['generar_codigo'] ?? '0';
    if (!in_array($generar, ['0', '1', 0, 1], true)) {
        throw new DomainException('Código: la opción de generación no es válida.');
    }
    $datos['generar_codigo'] = (string) $generar === '1';
    $idCodigo = $entrada['id_codigo'] ?? '';
    if ($datos['generar_codigo']) {
        if ($idCodigo !== '') {
            throw new DomainException('Código: elige generar uno nuevo o seleccionar uno existente, no ambos.');
        }
    } else {
        if (!is_string($idCodigo) && !is_int($idCodigo)) {
            throw new DomainException('Código: selecciona un código válido.');
        }
        $idCodigo = filter_var($idCodigo, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($idCodigo === false) {
            throw new DomainException('Código: selecciona uno disponible o elige generar uno nuevo.');
        }
        $datos['id_codigo'] = $idCodigo;
    }
    // Existencia, categoría y disponibilidad se comprobarán al guardar,
    // dentro de la transacción; esta función solo valida la entrada.
    if ($datos['descripcion'] === '' || mb_strlen($datos['descripcion']) > 255) {
        throw new DomainException('Descripción: ingresa entre 1 y 255 caracteres.');
    }
    if (!in_array($datos['estado'], ['ACTIVO', 'INACTIVO'], true)) {
        throw new DomainException('Estado: selecciona un estado válido.');
    }
    return $datos;
}

function guardarProducto(
    PDO $conexion,
    array $entrada,
    ?int $id = null, int $administrador = 0
): int {
    if ($id !== null && $id < 1) {
        throw new DomainException('El producto no es válido.');
    }
    $tokenCreacion = $id === null ? ($entrada['token_creacion'] ?? null) : null;
    if ($id === null && (!is_string($tokenCreacion) || !preg_match('/^[a-f0-9]{64}$/D', $tokenCreacion))) {
        throw new DomainException('La solicitud de creación no es válida. Abre un formulario nuevo.');
    }
    if ($tokenCreacion !== null) {
        $consulta = $conexion->prepare('SELECT id_producto FROM productos WHERE token_creacion = ?');
        $consulta->execute([$tokenCreacion]);
        $existente = $consulta->fetchColumn();
        if ($existente !== false) {
            return (int) $existente;
        }
    }
    if (isset($entrada['estado']) && $entrada['estado'] !== 'ACTIVO') {
        throw new DomainException('Para retirar un equipo utiliza la opción Dar de baja.');
    }
    $entrada['estado'] = 'ACTIVO';
    $datos = validarDatosProducto($entrada);
    $unidades = $entrada['unidades_producto'] ?? [];
    if (!is_array($unidades)) {
        throw new DomainException('Las unidades enviadas no son válidas.');
    }
    $atributos = $entrada['atributos'] ?? [];
    if (!is_array($atributos)) {
        throw new DomainException('Los atributos enviados no son válidos.');
    }
    $conexion->beginTransaction();
    try {
        $anterior = $id === null ? null : consultarProducto($conexion, $id, true);
        $antesAuditoria = consultarRegistroAuditoria($conexion, 'productos', $id);
        if ($id !== null && !$anterior) {
            throw new DomainException('El producto que intentas editar no existe.');
        }
        if ($anterior && $anterior['estado'] !== 'ACTIVO') {
            throw new DomainException('Un equipo dado de baja no se puede editar ni recibir un código nuevo.');
        }
        if ($anterior && (string) ($entrada['version'] ?? '') !== (string) $anterior['version']) {
            throw new DomainException('Otro administrador modificó este producto. Conservamos tus datos; revisa la información antes de volver a guardar.');
        }
        if ($anterior && (int) $anterior['id_categoria'] !== $datos['id_categoria']) {
            throw new DomainException('No se puede cambiar la categoría de un equipo registrado.');
        }
        foreach (['categorias' => 'id_categoria', 'areas' => 'id_area'] as $tabla => $campo) {
            $consulta = $conexion->prepare("SELECT estado FROM $tabla WHERE $campo = :id" . bloqueoProducto($conexion));
            $consulta->execute(['id' => $datos[$campo]]);
            $estado = $consulta->fetchColumn();
            if ($estado === false || ($estado !== 'ACTIVO' && (!$anterior || (int) $anterior[$campo] !== $datos[$campo]))) {
                throw new DomainException($campo === 'id_area' ? 'Área: selecciona un área activa.' : 'Categoría: selecciona una categoría activa.');
            }
        }
        if ($datos['generar_codigo']) {
            $codigo = generarCodigoInventario($conexion, $datos['id_categoria']);
        } else {
            $consulta = $conexion->prepare('SELECT id_codigo, id_categoria FROM codigos_inventario WHERE id_codigo = ? FOR UPDATE');
            $consulta->execute([$datos['id_codigo']]);
            $codigo = $consulta->fetch(PDO::FETCH_ASSOC);
            if (!$codigo || (int) $codigo['id_categoria'] !== $datos['id_categoria']) {
                throw new DomainException('Código: selecciona uno de la categoría del producto.');
            }
            $consulta = $conexion->prepare('SELECT id_producto FROM producto_codigos WHERE codigo_vigente = ? FOR UPDATE');
            $consulta->execute([$datos['id_codigo']]);
            $ocupante = $consulta->fetchColumn();
            if ($ocupante !== false && (int) $ocupante !== $id) {
                throw new DomainException('El código ya está asignado a otro equipo. Selecciona otro o genera uno nuevo.');
            }
        }
        $campos = array_intersect_key($datos, array_flip(['id_categoria', 'descripcion', 'id_area', 'estado']));
        if ($id === null) {
            $consulta = $conexion->prepare('INSERT INTO productos (id_categoria, descripcion, id_area, estado, token_creacion) VALUES (:id_categoria, :descripcion, :id_area, :estado, :token_creacion)');
            $consulta->execute($campos + ['token_creacion' => $tokenCreacion]);
            $id = (int) $conexion->lastInsertId();
        } else {
            $consulta = $conexion->prepare('UPDATE productos SET id_categoria=:id_categoria, descripcion=:descripcion, id_area=:id_area, estado=:estado, version=version+1, fecha_actualizacion=CURRENT_TIMESTAMP WHERE id_producto=:id');
            $consulta->execute($campos + ['id' => $id]);
        }
        if ((int) ($anterior['id_codigo'] ?? 0) !== (int) $codigo['id_codigo']) {
            $consulta = $conexion->prepare('UPDATE producto_codigos SET fecha_fin = CURRENT_TIMESTAMP WHERE producto_vigente = ?');
            $consulta->execute([$id]);
            $consulta = $conexion->prepare('INSERT INTO producto_codigos (id_producto, id_codigo, id_categoria) VALUES (?, ?, ?)');
            $consulta->execute([$id, $codigo['id_codigo'], $datos['id_categoria']]);
        }
        guardarAtributosProducto($conexion, $id, $datos['id_categoria'], $atributos, $unidades);
        registrarCambioAuditoria($conexion, $administrador, 'productos', $id, $antesAuditoria);
        $conexion->commit();
        return $id;
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        // Otra solicitud pudo confirmar mientras esta esperaba un bloqueo.
        // Tras revertir (incluido el consecutivo), devuelve el resultado original.
        if ($tokenCreacion !== null) {
            $consulta = $conexion->prepare('SELECT id_producto FROM productos WHERE token_creacion = ?');
            $consulta->execute([$tokenCreacion]);
            $existente = $consulta->fetchColumn();
            if ($existente !== false) {
                return (int) $existente;
            }
        }
        throw $error;
    }
}


function mensajeErrorProducto(PDOException $error): string
{
    $codigo = (int) ($error->errorInfo[1] ?? 0);
    if ($codigo === 1062) {
        return 'El código ya no está disponible. Recarga las opciones e intenta nuevamente.';
    }
    if (in_array($codigo, [1205, 1213], true)) {
        return 'Otro administrador está actualizando estos datos. Intenta guardar nuevamente.';
    }
    if (in_array($codigo, [1451, 1452], true)) {
        return 'No fue posible guardar: una categoría, área u opción relacionada ya no está disponible.';
    }
    return 'No fue posible guardar el producto. Intenta nuevamente.';
}

function consultarListadoProductos(
    PDO $conexion, 
    string $buscar, 
    ?int $categoria, 
    string $estado, 
    int $pagina, 
    int $porPagina = 10,
    ?int $totalCalculado = null
): array {
    [$desde, $parametros] = prepararConsultaProductos($buscar, $categoria, $estado);
    if ($totalCalculado === null) {
        $consulta = $conexion->prepare('SELECT COUNT(*)' . $desde);
        $consulta->execute($parametros);
        $total = (int) $consulta->fetchColumn();
    } else {
        $total = $totalCalculado;
    }
    $porPagina = max(1, min(100, $porPagina));
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = max(1, min($pagina, $totalPaginas));
    $consulta = $conexion->prepare('SELECT p.*, COALESCE(ci.codigo, "") AS codigo, ci.id_codigo, a.nombre AS area, c.nombre AS categoria,
        (SELECT ch.codigo FROM producto_codigos h JOIN codigos_inventario ch ON ch.id_codigo=h.id_codigo WHERE h.id_producto=p.id_producto AND h.fecha_fin IS NOT NULL ORDER BY h.fecha_fin DESC, h.id_producto_codigo DESC LIMIT 1) AS codigo_anterior'
        . $desde . ' ORDER BY ci.codigo ASC, p.id_producto DESC LIMIT :lim OFFSET :off');
    foreach ($parametros as $campo => $valor) {
        $consulta->bindValue(':' . $campo, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $consulta->bindValue(':lim', $porPagina, PDO::PARAM_INT);
    $consulta->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
    $consulta->execute();
    return ['productos' => $consulta->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'totalPaginas' => $totalPaginas, 'pagina' => $pagina];
    
}

function prepararConsultaProductos(
    string $buscar,
    ?int $categoria,
    string $estado
): array {
    $condiciones = [];
    $parametros = [];
    if ($buscar !== '') {
        $condiciones[] = '(
            EXISTS (
                SELECT 1 
                FROM producto_codigos h 
                JOIN codigos_inventario ch ON ch.id_codigo=h.id_codigo 
                WHERE h.id_producto=p.id_producto AND ch.codigo LIKE :codigo ESCAPE "!"
            ) 
             OR p.descripcion LIKE :descripcion ESCAPE "!" 
             OR a.nombre LIKE :area ESCAPE "!" 
             OR c.nombre LIKE :categoria_nombre ESCAPE "!"
        )';
        foreach (['codigo', 'descripcion', 'area', 'categoria_nombre'] as $campo) {
            $parametros[$campo] = patronBusqueda($buscar);
        }
    }

    if ($categoria) {
        $condiciones[] = 'p.id_categoria=:categoria'; $parametros['categoria'] = $categoria;
    }

    if ($estado !== '') {
        $condiciones[] = 'p.estado=:estado'; $parametros['estado'] = $estado;
    }

   $desde = ' 
        FROM productos p
        JOIN categorias c ON c.id_categoria = p.id_categoria
        JOIN areas a ON a.id_area = p.id_area
        LEFT JOIN producto_codigos pc ON pc.producto_vigente = p.id_producto
        LEFT JOIN codigos_inventario ci ON ci.id_codigo = pc.id_codigo
    ';

    if ($condiciones) {
        $desde .= ' WHERE ' . implode(' AND ', $condiciones);
    }

    return [$desde, $parametros];
}

function consultarTotalesProductosPorCategoria(
    PDO $conexion,
    string $buscar,
    ?int $categoria,
    string $estado
): array {
    [$desde, $parametros] = prepararConsultaProductos(
        $buscar,
        $categoria,
        $estado
    );

    $consulta = $conexion->prepare(
        'SELECT p.id_categoria, COUNT(*) AS total'
        . $desde
        . ' GROUP BY p.id_categoria '
    );

    $consulta->execute($parametros);

    $totales = [];

    foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $totales[(int) $fila['id_categoria']] = (int) $fila['total'];
    }
    return $totales;
}



