<?php

declare(strict_types=1);

function guardarAtributosProducto(PDO $conexion, int $idProducto, int $idCategoria, array $valores, array $unidades = []): void
{
    $consulta = $conexion->prepare(
        'SELECT ca.id_categoria_atributo, ca.requerido, a.id_atributo, a.tipo_dato, a.nombre, a.decimales,
                a.longitud_maxima, a.valor_minimo, a.valor_maximo
         FROM categoria_atributos ca
         INNER JOIN atributos a ON a.id_atributo = ca.id_atributo
         WHERE ca.id_categoria = :id_categoria
           AND ca.estado = "ACTIVO" AND a.estado = "ACTIVO"'
    );
    $consulta->execute(['id_categoria' => $idCategoria]);
    $definiciones = $consulta->fetchAll();

    // Solo se modifican relaciones activas de esta categoría enviadas por el formulario.
    // Los valores de relaciones inactivas o categorías anteriores se conservan.
    $eliminar = $conexion->prepare(
        'DELETE FROM producto_atributo_valores
         WHERE id_producto = :producto AND id_categoria_atributo = :relacion'
    );
    $existe = $conexion->prepare(
        'SELECT 1 FROM producto_atributo_valores
         WHERE id_producto = :producto AND id_categoria_atributo = :relacion'
    );
    $actualizar = $conexion->prepare(
        'UPDATE producto_atributo_valores
         SET valor_texto = :texto, valor_numero = :numero, valor_fecha = :fecha,
             valor_booleano = :booleano, id_atributo_opcion = :opcion, id_atributo_unidad = :unidad
         WHERE id_producto = :producto AND id_categoria_atributo = :relacion'
    );
    $insertar = $conexion->prepare(
        'INSERT INTO producto_atributo_valores
         (id_producto, id_categoria_atributo, valor_texto, valor_numero, valor_fecha, valor_booleano, id_atributo_opcion, id_atributo_unidad)
         VALUES (:producto, :relacion, :texto, :numero, :fecha, :booleano, :opcion, :unidad)'
    );

    foreach ($definiciones as $definicion) {
        $idRelacion = (int) $definicion['id_categoria_atributo'];
        if (!array_key_exists($idRelacion, $valores)) {
            if ((int) $definicion['requerido'] === 1) {
                throw new DomainException($definicion['nombre'] . ': este atributo es obligatorio.');
            }
            // La ausencia del campo no es una solicitud de borrar su valor.
            continue;
        }
        if (!is_scalar($valores[$idRelacion])) {
            throw new DomainException('Valor no válido para ' . $definicion['nombre'] . '.');
        }
        $valor = trim((string) $valores[$idRelacion]);
        if ($valor === '') {
            if ((int) $definicion['requerido'] === 1) {
                throw new DomainException($definicion['nombre'] . ': este atributo es obligatorio.');
            }
            $eliminar->execute(['producto' => $idProducto, 'relacion' => $idRelacion]);
            continue;
        }
        $datos = ['producto' => $idProducto, 'relacion' => $idRelacion, 'texto' => null, 'numero' => null, 'fecha' => null, 'booleano' => null, 'opcion' => null, 'unidad' => null];
        switch ($definicion['tipo_dato']) {
            case 'TEXTO':
                if (mb_strlen($valor) > min(255, (int) ($definicion['longitud_maxima'] ?: 255))) {
                    throw new DomainException($definicion['nombre'] . ': el texto supera su longitud permitida.');
                }
                $datos['texto'] = $valor;
                break;
            case 'NUMERO':
                $qUnidades = $conexion->prepare('SELECT id_atributo_unidad, estado FROM atributo_unidades WHERE id_atributo = ?');
                $qUnidades->execute([$definicion['id_atributo']]);
                $permitidas = $qUnidades->fetchAll(PDO::FETCH_KEY_PAIR);
                $unidadEntrada = $unidades[$idRelacion] ?? '';
                if (!is_scalar($unidadEntrada)) {
                    throw new DomainException($definicion['nombre'] . ': unidad no válida.');
                }
                if ($permitidas !== []) {
                    $idUnidad = filter_var($unidadEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (!$idUnidad || !isset($permitidas[$idUnidad])) {
                        throw new DomainException($definicion['nombre'] . ': selecciona una unidad permitida.');
                    }
                    if ($permitidas[$idUnidad] !== 'ACTIVO') {
                        $actualUnidad = $conexion->prepare('SELECT id_atributo_unidad FROM producto_atributo_valores WHERE id_producto = ? AND id_categoria_atributo = ?');
                        $actualUnidad->execute([$idProducto, $idRelacion]);
                        if ((int) $actualUnidad->fetchColumn() !== $idUnidad) {
                            throw new DomainException($definicion['nombre'] . ': selecciona una unidad activa.');
                        }
                    }
                    $datos['unidad'] = $idUnidad;
                } elseif ((string) $unidadEntrada !== '') {
                    throw new DomainException($definicion['nombre'] . ': este atributo no utiliza unidad.');
                }
                if (!preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $valor)) {
                    throw new DomainException($definicion['nombre'] . ': ingresa un número decimal válido.');
                }
                $partes = explode('.', ltrim($valor, '+-'));
                $decimales = (int) ($definicion['decimales'] ?? 0);
                if (strlen(rtrim($partes[1] ?? '', '0')) > $decimales) {
                    throw new DomainException($definicion['nombre'] . ': se permiten hasta ' . $decimales . ' decimales.');
                }
                if (strlen(ltrim($partes[0], '0')) > 14) {
                    throw new DomainException($definicion['nombre'] . ': el número supera la capacidad permitida.');
                }
                $numero = (float) $valor;
                if ($definicion['valor_minimo'] !== null && $numero < (float) $definicion['valor_minimo']) {
                    throw new DomainException($definicion['nombre'] . ': el valor es menor al mínimo permitido.');
                }
                if ($definicion['valor_maximo'] !== null && $numero > (float) $definicion['valor_maximo']) {
                    throw new DomainException($definicion['nombre'] . ': el valor supera el máximo permitido.');
                }
                $datos['numero'] = $valor;
                break;
            case 'FECHA':
                $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
                if (!$fecha || $fecha->format('Y-m-d') !== $valor) {
                    throw new DomainException($definicion['nombre'] . ': ingresa una fecha válida.');
                }
                $datos['fecha'] = $valor;
                break;
            case 'BOOLEANO':
                if (!in_array($valor, ['0', '1'], true)) {
                    throw new DomainException($definicion['nombre'] . ': selecciona Sí o No.');
                }
                $datos['booleano'] = (int) $valor;
                break;
            case 'SELECCION':
                $opcion = $conexion->prepare('SELECT ao.id_atributo_opcion FROM atributo_opciones ao INNER JOIN categoria_atributos ca ON ca.id_atributo=ao.id_atributo WHERE ao.id_atributo_opcion=:opcion AND ca.id_categoria_atributo=:relacion AND (ao.estado="ACTIVO" OR EXISTS (SELECT 1 FROM producto_atributo_valores pav WHERE pav.id_producto=:producto AND pav.id_categoria_atributo=ca.id_categoria_atributo AND pav.id_atributo_opcion=ao.id_atributo_opcion))');
                $opcion->execute(['opcion' => $valor, 'relacion' => $idRelacion, 'producto' => $idProducto]);
                if ($opcion->fetchColumn() === false) {
                    throw new DomainException($definicion['nombre'] . ': selecciona una opción activa o conserva la opción actual.');
                }
                $datos['opcion'] = (int) $valor;
                break;
            default:
                throw new DomainException('Tipo de atributo no admitido.');
        }
        $existe->execute(['producto' => $idProducto, 'relacion' => $idRelacion]);
        $yaExiste = $existe->fetchColumn() !== false;
        $existe->closeCursor();
        ($yaExiste ? $actualizar : $insertar)->execute($datos);
    }
}
