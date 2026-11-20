<?php

declare(strict_types=1);
require_once __DIR__ . '/../../auth/busqueda.php';
require_once __DIR__ . '/../auditoria/servicio.php';

function filtrosInventario(array $entrada): array
{
    $texto = static fn (string $k): string => is_string($entrada[$k] ?? null) ? trim($entrada[$k]) : '';
    
    $categoriasEntrada = $entrada['categoria'] ?? [];

    // Conserva compatibilidad con enlaces que tengan una sola categoría.
    if (!is_array($categoriasEntrada)) {
        $categoriasEntrada = [$categoriasEntrada];
    }

    $categorias = [];

    foreach ($categoriasEntrada as $valor) {
        if (!is_scalar($valor)) {
            continue;
        }

        $id = filter_var(
            $valor,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($id !== false) {
            $categorias[] = $id;
        }
    }

    $categorias = array_values(array_unique($categorias));

    return [
        'codigo' => mb_substr($texto('codigo'), 0, 100),
        'usuario' => mb_substr($texto('usuario'), 0, 100),
        'categoria' => $categorias,
        'area' => max(0, (int) filter_var($entrada['area'] ?? 0, FILTER_VALIDATE_INT)),
        'asignacion' => in_array($texto('asignacion'), ['CON', 'SIN'], true) ? $texto('asignacion') : '',
        'pagina' => max(1, (int) filter_var($entrada['pagina'] ?? 1, FILTER_VALIDATE_INT)),
    ];
}

function consultarInventario(
    PDO $db, 
    array $f,
    bool $exportar = false
): array {
    $categoriasExistentes = array_map(
        'intval',
        $db->query(
            'SELECT id_categoria
            FROM categorias'
        )->fetchAll(PDO::FETCH_COLUMN)
    );

    $f['categoria'] = array_values(
        array_intersect($f['categoria'], $categoriasExistentes)
    );

    foreach (['area' => ['areas', 'id_area']] as $clave => [$tabla,$id]) {
        if ($f[$clave]) {
            $verificar = $db->prepare(
                "SELECT 1 
                 FROM $tabla 
                WHERE $id = ?"
            );

            $verificar->execute([$f[$clave]]);
            if (!$verificar->fetchColumn()) {
                $f[$clave] = 0;
            }
        }
    }
    $desde = ' FROM productos p JOIN categorias c ON c.id_categoria=p.id_categoria
        JOIN areas a ON a.id_area=p.id_area
        LEFT JOIN producto_codigos pc ON pc.producto_vigente=p.id_producto
        LEFT JOIN codigos_inventario ci ON ci.id_codigo=pc.id_codigo';
    $condiciones = ['p.estado = "ACTIVO"'];

    // Ignora enlaces antiguos que incluyan un filtro de estado.
    unset($f['estado']);
    $valores = [];

    if ($f['codigo'] !== '') {
        $condiciones[] = "ci.codigo LIKE ? ESCAPE '!'";
        $valores[] = patronBusqueda($f['codigo']);
    }

    if ($f['usuario'] !== '') {
        $condiciones[] = 'EXISTS (
            SELECT 1 FROM producto_asignaciones pu
            JOIN usuarios u ON u.id_usuario = pu.id_usuario
            WHERE pu.id_producto = p.id_producto AND pu.fecha_devolucion IS NULL
              AND (CONCAT(u.nombre, " ", u.apellido) LIKE ? ESCAPE "!"
                   OR u.cedula LIKE ? ESCAPE "!")
        )';
        $valores[] = patronBusqueda($f['usuario']);
        $valores[] = patronBusqueda($f['usuario']);
    }

    if ($f['categoria']) {
        $marcadores = implode(
            ',',
            array_fill(0, count($f['categoria']), '?')
        );

        $condiciones[] = "p.id_categoria IN ($marcadores)";

        foreach ($f['categoria'] as $categoria) {
            $valores[] = $categoria;
        }
    }

    foreach (['area' => 'p.id_area'] as $clave => $columna) {
        if ($f[$clave]) {
            $condiciones[] = "$columna = ?";
            $valores[] = $f[$clave];
        }
    }
    
    if ($f['asignacion'] !== '') {
        $condiciones[] = ($f['asignacion'] === 'SIN' ? 'NOT ' : '')
            . 'EXISTS (
            SELECT 1 
            FROM producto_asignaciones pa 
            WHERE pa.id_producto=p.id_producto 
                AND pa.fecha_devolucion IS NULL)';
    }
    $donde = $condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '';
    
    $q = $db->prepare(
        'SELECT COUNT(*)'
        . $desde . $donde
    );

    $q->execute($valores);
    $total = (int) $q->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / 15));
    $pagina = min($f['pagina'], $totalPaginas);
    $offset = ($pagina - 1) * 15;
    $limite = $exportar ? '' : " LIMIT 15 OFFSET $offset";
    $q = $db->prepare(
        'SELECT p.id_producto,p.descripcion,p.estado,p.disponible,c.nombre 
            AS categoria,a.nombre 
                AS area,ci.codigo,
        (SELECT GROUP_CONCAT(
            DISTINCT ch.codigo 
            ORDER BY ch.codigo SEPARATOR ", ") 
            
        FROM producto_codigos h 
        JOIN codigos_inventario ch 
            ON ch.id_codigo=h.id_codigo 
        WHERE h.id_producto=p.id_producto 
            AND h.fecha_fin IS NOT NULL) 
        AS codigos_anteriores'
        . $desde . $donde . '  ORDER BY c.nombre DESC, ci.codigo ASC, c.id_categoria DESC, p.id_producto DESC' . $limite
    );
    $q->execute($valores);
    $productos = $q->fetchAll();
    $responsables = [];
    if ($productos) {
        $ids = array_column($productos, 'id_producto');
        $q = $db->prepare('SELECT pa.id_producto_asignacion,pa.id_producto,pa.id_usuario,
            pa.fecha_asignacion,
            CONCAT(u.nombre," ",u.apellido) AS nombre FROM producto_asignaciones pa
            JOIN usuarios u ON u.id_usuario=pa.id_usuario WHERE pa.fecha_devolucion IS NULL
            AND pa.id_producto IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY u.nombre,u.apellido');

        $q->execute($ids);
        foreach ($q->fetchAll() as $fila) {
            $responsables[$fila['id_producto']][] = $fila;
        }
    }
    return compact('productos', 'responsables', 'total', 'pagina', 'totalPaginas') + ['filtros' => $f];
}

/** Consulta el equipo del borrador sin filtros ni paginación. */
function consultarEquipoRecuperacionInventario(PDO $db, int $id): ?array
{
    $q = $db->prepare('SELECT p.id_producto, p.descripcion, p.estado, ci.codigo
        FROM productos p
        LEFT JOIN producto_codigos pc ON pc.producto_vigente = p.id_producto
        LEFT JOIN codigos_inventario ci ON ci.id_codigo = pc.id_codigo
        WHERE p.id_producto = ?');
    $q->execute([$id]);
    $producto = $q->fetch();
    if (!$producto) {
        return null;
    }
    $q = $db->prepare('SELECT pa.id_producto_asignacion, pa.id_usuario,
        CONCAT(u.nombre, " ", u.apellido) AS nombre
        FROM producto_asignaciones pa
        JOIN usuarios u ON u.id_usuario = pa.id_usuario
        WHERE pa.id_producto = ? AND pa.fecha_devolucion IS NULL
        ORDER BY u.nombre, u.apellido, pa.id_producto_asignacion');
    $q->execute([$id]);
    return ['producto' => $producto, 'responsables' => $q->fetchAll()];
}

/** Incluye asignaciones vigentes y finalizadas, también de productos dados de baja. */
function consultarHistorialProducto(PDO $db, int $producto, int $pagina = 1): ?array
{
    $q = $db->prepare('SELECT 1 FROM productos WHERE id_producto = ?');
    $q->execute([$producto]);
    if (!$q->fetchColumn()) {
        return null;
    }

    $q = $db->prepare('SELECT COUNT(*) FROM producto_asignaciones WHERE id_producto = ?');
    $q->execute([$producto]);
    $total = (int) $q->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / 10));
    $pagina = min(max(1, $pagina), $totalPaginas);
    $offset = ($pagina - 1) * 10;
    $q = $db->prepare(
        'SELECT pa.id_producto_asignacion, pa.fecha_asignacion, pa.fecha_devolucion,
            CONCAT(u.nombre, " ", u.apellido) AS responsable, u.cedula
         FROM producto_asignaciones pa
         LEFT JOIN usuarios u ON u.id_usuario = pa.id_usuario
         WHERE pa.id_producto = ?
         ORDER BY pa.fecha_asignacion DESC, pa.id_producto_asignacion DESC
         LIMIT 10 OFFSET ' . $offset
    );
    $q->execute([$producto]);
    $asignaciones = $q->fetchAll(PDO::FETCH_ASSOC);
    return compact('asignaciones', 'total', 'pagina', 'totalPaginas');
}

function buscarResponsablesInventario(PDO $db, string $buscar, int $producto): array
{
    $buscar = trim($buscar);
    if ($producto < 1 || mb_strlen($buscar) < 2 || mb_strlen($buscar) > 100) {
        return [];
    }
    $q = $db->prepare(
        "SELECT u.id_usuario, CONCAT(u.nombre, ' ', u.apellido) AS nombre, u.cedula
         FROM usuarios u
         WHERE u.estado = 'ACTIVO'
           AND (CONCAT(u.nombre, ' ', u.apellido) LIKE ? ESCAPE '!' OR u.cedula LIKE ? ESCAPE '!')
           AND EXISTS (SELECT 1 FROM productos p WHERE p.id_producto = ? AND p.estado = 'ACTIVO')
           AND NOT EXISTS (SELECT 1 FROM producto_asignaciones pa
               WHERE pa.id_producto = ? AND pa.id_usuario = u.id_usuario AND pa.fecha_devolucion IS NULL)
         ORDER BY u.nombre, u.apellido, u.id_usuario LIMIT 10"
    );
    $patron = patronBusqueda($buscar);
    $q->execute([$patron, $patron, $producto, $producto]);
    return $q->fetchAll();
}

function modificarAsignacionInventario(
    PDO $db, 
    string $accion, 
    int $producto, 
    int $destino,
    int $idAdministrador
): void {
    if (!in_array($accion, ['asignar', 'devolver'], true) || $producto < 1 || $destino < 1) {
        throw new DomainException('Selecciona un equipo y un responsable válidos.');
    }

    if ($idAdministrador < 1) {
        throw new DomainException(
            'No fue posibe identificar al administrador.'
        );
    }

    $datosAnteriores = null;
    $idAsignacion = 0;

    $db->beginTransaction();
    try {
        // Usa el mismo bloqueo de equipo que el servicio de bajas.
        $q = $db->prepare(
            'SELECT estado 
             FROM productos 
             WHERE id_producto=? 
             FOR UPDATE'
        );

        $q->execute([$producto]);

        if ($q->fetchColumn() !== 'ACTIVO') {
            throw new DomainException('El equipo no existe o está dado de baja.');
        }
        
        if ($accion === 'asignar') {
            // Comparte bloqueos con la desactivación de áreas y categorías.
            $q = $db->prepare(
                'SELECT a.estado 
                AS area_estado, c.estado 
                AS categoria_estado
                FROM productos p 
                JOIN areas a 
                    ON a.id_area = p.id_area
                JOIN categorias c 
                    ON c.id_categoria = p.id_categoria
                WHERE p.id_producto = ? 
                FOR UPDATE');
            $q->execute([$producto]);
            $catalogos = $q->fetch();
            if (!$catalogos || $catalogos['area_estado'] !== 'ACTIVO' || $catalogos['categoria_estado'] !== 'ACTIVO') {
                throw new DomainException('No puedes asignar un equipo cuya área o categoría esté inactiva.');
            }
            $q = $db->prepare(
                'SELECT estado 
                 FROM usuarios 
                 WHERE id_usuario=? 
                 FOR UPDATE'
            );

            $q->execute([$destino]);
            
            if ($q->fetchColumn() !== 'ACTIVO') {
                throw new DomainException('Selecciona un usuario activo.');
            }
            
            $q = $db->prepare(
                'SELECT id_producto_asignacion  
                 FROM producto_asignaciones 
                 WHERE id_producto=? 
                    AND id_usuario=? 
                    AND fecha_devolucion 
                IS NULL FOR UPDATE'
            );

            $q->execute([$producto, $destino]);

            if ($q->fetchColumn()) {
                throw new DomainException('El usuario ya es responsable de este equipo.');
            }
            
            $q = $db->prepare(
                'INSERT INTO producto_asignaciones 
                    (id_producto,id_usuario) 
                VALUES (?,?)'
            );

            $q->execute([$producto, $destino]);

             $idAsignacion = (int) $db->lastInsertId();
        } else {
            
            $idAsignacion = $destino;

            $datosAnteriores = consultarDatosAsignacion(
                $db,
                $idAsignacion,
                $producto
            );

            if ($datosAnteriores['fecha_devolucion'] !== null) {
                throw new DomainException(
                    'Esta asignación ya tiene na devolución registrada.'
                );
            }
             
            $q = $db->prepare(
                'UPDATE producto_asignaciones SET fecha_devolucion = CURRENT_TIMESTAMP
                 WHERE id_producto_asignacion = ? AND id_producto = ? AND fecha_devolucion IS NULL'
            );
            $q->execute([$destino, $producto]);
            if ($q->rowCount() !== 1) {
                throw new DomainException('La asignación ya fue devuelta o no pertenece a este equipo.');
            }
        }
        // Actualiza la disponibilidad después de asignar o devolver, en la misma transacción.
        $q = $db->prepare(
            'UPDATE productos p
                 SET disponible = CASE 
                    WHEN p.estado = "ACTIVO"
                        AND NOT EXISTS (
                            SELECT 1
                            FROM producto_asignaciones pa
                            WHERE pa.id_producto = p.id_producto
                                AND pa.fecha_devolucion IS NULL 
                        )
                    THEN 1
                    ELSE 0
                END
                WHERE p.id_producto = ?'
        );

        $q->execute([$producto]);
        
        $datosNuevos = consultarDatosAsignacion(
            $db,
            $idAsignacion,
            $producto
        );
        
        registrarEventoAuditoria(
            $db,
            $idAdministrador,
            'INVENTARIO',
            $accion ==='asignar' ? 'ASIGNACION' : 'DEVOLUCION',
            'producto_asignaciones',
            $idAsignacion,
            $datosAnteriores,
            $datosNuevos
        );

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}


function consultarDatosAsignacion(
    PDO $conexion,
    int $idAsignacion,
    int $idProducto
): array {
    $consulta = $conexion->prepare(
        'SELECT
            pa.id_producto_asignacion,
            pa.id_producto,
            pa.id_usuario,
            pa.fecha_asignacion,
            pa.fecha_devolucion,
            p.descripcion AS equipo,
            ci.codigo,
            p.id_area,
            a.nombre AS area,
            CONCAT(u.nombre, " ", u.apellido) AS responsable
         FROM producto_asignaciones AS pa
         INNER JOIN productos AS p
            ON p.id_producto = pa.id_producto
         INNER JOIN usuarios AS u
            ON u.id_usuario = pa.id_usuario
         INNER JOIN areas AS a
            ON a.id_area = p.id_area
         LEFT JOIN producto_codigos pc ON pc.producto_vigente = p.id_producto
         LEFT JOIN codigos_inventario ci ON ci.id_codigo = pc.id_codigo
         WHERE pa.id_producto_asignacion = ?
           AND pa.id_producto = ?
         FOR UPDATE'
    );

    $consulta->execute([$idAsignacion, $idProducto]);
    $asignacion = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$asignacion) {
        throw new DomainException(
            'La asignación no existe o no pertenece a este equipo.'
        );
    }

    return $asignacion;
}

