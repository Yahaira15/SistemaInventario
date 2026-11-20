<?php

declare(strict_types=1);
require_once __DIR__ . '/servicio.php';

/** Solo tablas y campos explícitamente autorizados; nunca credenciales ni tokens. */
function consultarRegistroAuditoria(PDO $db, string $entidad, ?int $id): ?array
{
    $definiciones = [
        'areas' => ['id_area', 'nombre, estado'],
        'categorias' => ['id_categoria', 'nombre, descripcion, prefijo_codigo, estado'],
        'usuarios' => ['id_usuario', 'cedula, nombre, apellido, correo, id_area, rol, estado'],
        'atributos' => ['id_atributo', 'nombre, descripcion, tipo_dato, unidad, longitud_maxima, valor_minimo, valor_maximo, decimales, estado'],
        'atributo_opciones' => ['id_atributo_opcion', 'id_atributo, valor, orden_visual, estado'],
        'categoria_atributos' => ['id_categoria_atributo', 'id_categoria, id_atributo, requerido, orden_visual, estado'],
        'productos' => ['id_producto', 'descripcion, id_categoria, id_area, estado, disponible'],
    ];
    if (!isset($definiciones[$entidad]) || !$db->inTransaction()) {
        throw new LogicException('Consulta de auditoría no válida o sin transacción.');
    }
    if (!$id) {
        return null;
    }
    [$clave, $campos] = $definiciones[$entidad];
    $q = $db->prepare("SELECT $campos FROM $entidad WHERE $clave = ? FOR UPDATE");
    $q->execute([$id]);
    $datos = $q->fetch(PDO::FETCH_ASSOC);
    if (!$datos) {
        throw new DomainException('El registro que intentas modificar no existe.');
    }
    foreach (['id_area' => ['areas', 'area'], 'id_categoria' => ['categorias', 'categoria'], 'id_atributo' => ['atributos', 'atributo']] as $campo => [$tabla, $nombre]) {
        if (array_key_exists($campo, $datos)) {
            $q = $db->prepare("SELECT nombre FROM $tabla WHERE $campo = ?");
            $q->execute([$datos[$campo]]);
            $datos[$nombre] = $q->fetchColumn() ?: null;
        }
    }
    if ($entidad === 'productos') {
        $datos['equipo'] = $datos['descripcion'];
        unset($datos['descripcion']);
        $q = $db->prepare('SELECT c.codigo FROM producto_codigos pc INNER JOIN codigos_inventario c ON c.id_codigo = pc.id_codigo WHERE pc.producto_vigente = ?');
        $q->execute([$id]);
        $datos['codigo'] = $q->fetchColumn() ?: null;
        $q = $db->prepare('SELECT ca.id_categoria_atributo, a.nombre, v.valor_texto, v.valor_numero, v.valor_fecha, v.valor_booleano, o.valor AS opcion, u.simbolo AS unidad FROM producto_atributo_valores v INNER JOIN categoria_atributos ca ON ca.id_categoria_atributo = v.id_categoria_atributo INNER JOIN atributos a ON a.id_atributo = ca.id_atributo LEFT JOIN atributo_opciones o ON o.id_atributo_opcion = v.id_atributo_opcion LEFT JOIN atributo_unidades u ON u.id_atributo_unidad = v.id_atributo_unidad WHERE v.id_producto = ? ORDER BY ca.id_categoria_atributo');
        $q->execute([$id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $valor) {
            $contenido = $valor['opcion'] ?? $valor['valor_texto'] ?? $valor['valor_numero'] ?? $valor['valor_fecha'] ?? ($valor['valor_booleano'] === null ? null : ((int) $valor['valor_booleano'] === 1 ? 'Sí' : 'No'));
            $datos['Atributo: ' . $valor['nombre'] . ' (#' . $valor['id_categoria_atributo'] . ')'] = $contenido === null ? null : $contenido . ($valor['unidad'] ? ' ' . $valor['unidad'] : '');
        }
    }
    if ($entidad === 'atributos') {
        foreach (['atributo_unidades' => ['id_atributo_unidad', 'simbolo'], 'atributo_opciones' => ['id_atributo_opcion', 'valor']] as $tabla => [$claveHija, $valor]) {
            $q = $db->prepare("SELECT $claveHija, $valor, estado FROM $tabla WHERE id_atributo = ? ORDER BY $claveHija");
            $q->execute([$id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $datos[($tabla === 'atributo_unidades' ? 'Unidad' : 'Opción') . ' #' . $fila[$claveHija]] = $fila[$valor] . ' (' . $fila['estado'] . ')';
            }
        }
    }
    return $datos;
}

function registrarCambioAuditoria(PDO $db, int $administrador, string $entidad, int $id, ?array $antes, array $extra = []): void
{
    $despues = consultarRegistroAuditoria($db, $entidad, $id);
    if ($antes === $despues && !$extra) {
        return;
    }
    $accion = $antes === null
        ? ($entidad === 'categoria_atributos' ? 'ASIGNACION' : 'CREACION')
        : 'EDICION';
    if ($antes !== null && ($antes['estado'] ?? null) !== ($despues['estado'] ?? null)) {
        $accion = ($despues['estado'] ?? null) === 'INACTIVO' ? 'DESACTIVACION' : 'REACTIVACION';
    }
    $modulo = match ($entidad) {
        'atributo_opciones' => 'ATRIBUTOS',
        'categoria_atributos' => 'CATEGORIAS',
        default => strtoupper($entidad),
    };
    registrarEventoAuditoria($db, $administrador, $modulo, $accion, $entidad, $id, $antes, array_merge($despues, $extra));
}
