<?php

declare(strict_types=1);

/**
 * Devuelve los códigos disponibles de una categoría.
 * Al editar, incluye también el código vigente del producto.
 */
function consultarCodigosDisponibles(
    PDO $conexion,
    int $idCategoria,
    ?int $idProducto = null
): array {
    if ($idCategoria < 1) {
        throw new DomainException('El producto no es válido.');
    }

    $consulta = $conexion->prepare(
        'SELECT 
            c.id_codigo, 
            c.codigo,
            c.id_categoria
         FROM codigos_inventario c
         LEFT JOIN producto_codigos pc
             ON pc.codigo_vigente = c.id_codigo
         WHERE c.id_categoria = :id_categoria
            AND (
                pc.id_producto_codigo IS NULL
                OR pc.id_producto = :id_producto
            )
         ORDER BY c.codigo'
    );

    $consulta->execute([
        'id_categoria' => $idCategoria,
        'id_producto' => $idProducto
    ]);

    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Genera un código nuevo usando el contador de la categoría.
 * Requiere una transacción iniciada por el guardado del producto.
 */
function generarCodigoInventario(
    PDO $conexion,
    int $idCategoria
): array {
    if ($idCategoria < 1) {
        throw new DomainException('Selcciona una categoría valida.');
    }

    if (!$conexion->inTransaction()) {
        throw new LogicException(
            'La generación del código requiere una transacción activa.'
        );
    }

    // Bloquea el contador para evitar que dos solicitudes usen
    // el mismo consecutivo simultáneamente.

    $consulta = $conexion->prepare(
        'SELECT prefijo_codigo, ultimo_consecutivo, estado
         FROM categorias
         WHERE id_categoria = :id_categoria
         FOR UPDATE'
    );

    $consulta->execute(['id_categoria' => $idCategoria]);
    $categoria = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$categoria || $categoria['estado'] !== 'ACTIVO') {
        throw new DomainException(
            'Selecciona una categoría existente y activa'
        );
    }

    $prefijo = mb_strtoupper(
        trim((string) $categoria['prefijo_codigo']),
        'UTF-8'
    );

    if ($prefijo === '') {
        throw new DomainException('Esta categoría todavía no tiene un prefijo configurado.');
    }

    $ultimo = (int) $categoria['ultimo_consecutivo'];

    if ($ultimo >= 4294967295) {
        throw new DomainException('Se alcanzó el límite de códigos por categoría.');
    }

    $siguiente = $ultimo + 1;

    // Usa al menos tres dígitos: 004, 025, 100, 1000...
    $codigo = $prefijo . str_pad(
        (string) $siguiente,
        3,
        '0',
        STR_PAD_LEFT
    );

    $consulta = $conexion->prepare(
        'INSERT INTO codigos_inventario (codigo, id_categoria)
         VALUES (:codigo, :id_categoria)'
    );

    try {
        $consulta->execute([
            'codigo' => $codigo,
            'id_categoria' => $idCategoria,
        ]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException(
                'El código generado ya existe. Revisa el prefijo '
                . 'y el consecutivo de la categoría.',
                0,
                $error
            );
        }

        throw $error;
    }

    $idCodigo = (int) $conexion->lastInsertId();

    $consulta = $conexion->prepare(
        'UPDATE categorias
         SET ultimo_consecutivo = :consecutivo
         WHERE id_categoria = :id_categoria'
    );

    $consulta->execute([
        'consecutivo' => $siguiente,
        'id_categoria' => $idCategoria,
    ]);

    return [
        'id_codigo' => $idCodigo,
        'codigo' => $codigo,
        'id_categoria' => $idCategoria,
    ];
}
