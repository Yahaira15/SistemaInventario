<?php

declare(strict_types=1);

/** Impide retirar un catálogo mientras sus productos tengan responsables. */
function exigirCatalogoSinAsignaciones(PDO $db, string $tipo, int $id): void
{
    $catalogos = [
        'area' => ['areas', 'id_area', 'esta área'],
        'categoria' => ['categorias', 'id_categoria', 'esta categoría'],
    ];

    if (!isset($catalogos[$tipo]) || !$db->inTransaction()) {
        throw new LogicException('La validación requiere un catálogo válido y una transacción.');
    }
    [$tabla, $columna, $etiqueta] = $catalogos[$tipo];
    $consultaCatalogo = $db->prepare(
        "SELECT $columna FROM $tabla WHERE $columna = ? FOR UPDATE"
    );
    $consultaCatalogo->execute([$id]);

    if ($consultaCatalogo->fetchColumn() === false) {
        throw new DomainException('El registro no existe.');
    }

    $consultaProductos = $db->prepare(
        "SELECT id_producto
         FROM productos
         WHERE $columna = ?
         ORDER BY id_producto
         FOR UPDATE"
    );
    $consultaProductos->execute([$id]);
    $productos = $consultaProductos->fetchAll(PDO::FETCH_COLUMN);

    $pendientes = $db->prepare(
        'SELECT id_producto_asignacion
         FROM producto_asignaciones
         WHERE id_producto = ? AND fecha_devolucion IS NULL
         FOR UPDATE'
    );

    // Se cuentan productos, aunque un producto tenga varios responsables.
    $total = 0;
    foreach ($productos as $producto) {
        $pendientes->execute([$producto]);
        if ($pendientes->fetchAll(PDO::FETCH_COLUMN)) {
            $total++;
        }
    }

    if ($total > 0) {
        throw new DomainException(
            'No puedes desactivar ' . $etiqueta . ' porque tiene ' . $total
            . ($total === 1
                ? ' producto con asignaciones pendientes.'
                : ' productos con asignaciones pendientes.')
            . ' Primero debes desasignar los productos desde Inventario, registrando todas las devoluciones.'
        );
    }
}
