<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../admin/productos/guardar_atributos.php';

// Pruebas aisladas: no usan la conexión ni los datos de la aplicación.
$conexion = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$conexion->exec('CREATE TABLE atributos (
    id_atributo INTEGER PRIMARY KEY, tipo_dato TEXT, longitud_maxima INTEGER,
    valor_minimo REAL, valor_maximo REAL, estado TEXT
);
CREATE TABLE categoria_atributos (
    id_categoria_atributo INTEGER PRIMARY KEY, id_categoria INTEGER,
    id_atributo INTEGER, requerido INTEGER, estado TEXT
);
CREATE TABLE producto_atributo_valores (
    id INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER,
    id_categoria_atributo INTEGER, valor_texto TEXT, valor_numero REAL,
    valor_fecha TEXT, valor_booleano INTEGER, id_atributo_opcion INTEGER,
    UNIQUE (id_producto, id_categoria_atributo)
);
INSERT INTO atributos VALUES
    (1, "TEXTO", 50, NULL, NULL, "ACTIVO"),
    (2, "TEXTO", 50, NULL, NULL, "INACTIVO");
INSERT INTO categoria_atributos VALUES
    (10, 1, 1, 0, "ACTIVO"),
    (11, 1, 2, 0, "ACTIVO"),
    (12, 1, 1, 0, "INACTIVO"),
    (20, 2, 1, 0, "ACTIVO");
INSERT INTO producto_atributo_valores (id_producto, id_categoria_atributo, valor_texto)
VALUES (1, 10, "original"), (1, 11, "atributo inactivo"),
       (1, 12, "relación inactiva"), (2, 10, "otro producto");');
$conexion->exec('ALTER TABLE atributos ADD COLUMN nombre TEXT DEFAULT "Atributo"; ALTER TABLE atributos ADD COLUMN decimales INTEGER DEFAULT 0');

function comprobar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
    echo 'OK: ', $mensaje, PHP_EOL;
}

function filas(PDO $conexion): array
{
    return $conexion->query('SELECT * FROM producto_atributo_valores ORDER BY id')->fetchAll();
}

$conexion->exec('ALTER TABLE producto_atributo_valores ADD COLUMN id_atributo_unidad INTEGER; CREATE TABLE atributo_unidades (id_atributo_unidad INTEGER PRIMARY KEY, id_atributo INTEGER, simbolo TEXT, estado TEXT, UNIQUE(id_atributo,simbolo));');
$iniciales = filas($conexion);
guardarAtributosProducto($conexion, 1, 1, [10 => 'editado', 11 => 'alterado', 12 => 'alterado']);
$esperadas = $iniciales;
$esperadas[0]['valor_texto'] = 'editado';
comprobar(filas($conexion) === $esperadas, 'Actualiza el activo conservando su ID, los inactivos y otros productos.');

guardarAtributosProducto($conexion, 1, 2, [20 => 'nueva categoría']);
comprobar(array_slice(filas($conexion), 0, 4) === $esperadas, 'Cambiar de categoría conserva los valores anteriores.');
comprobar(count(filas($conexion)) === 5, 'Inserta el atributo de la nueva categoría.');

$antes = filas($conexion);
guardarAtributosProducto($conexion, 1, 1, []);
comprobar(filas($conexion) === $antes, 'Omitir un opcional conserva su valor.');
guardarAtributosProducto($conexion, 1, 1, [10 => '']);
comprobar(count(filas($conexion)) === 4 && filas($conexion) === array_slice($antes, 1), 'Vaciar un opcional elimina únicamente ese valor.');

guardarAtributosProducto($conexion, 1, 1, [10 => 'restaurado']);
comprobar(count(filas($conexion)) === 5, 'Permite volver a registrar el valor eliminado.');
$conexion->exec('UPDATE categoria_atributos SET estado = "ACTIVO" WHERE id_categoria_atributo = 12');
$antes = filas($conexion);
guardarAtributosProducto($conexion, 1, 1, [10 => 'restaurado', 12 => 'relación inactiva']);
comprobar(filas($conexion) === $antes, 'Reactivar una relación permite reutilizar el valor conservado sin duplicarlo.');

$conexion->exec('INSERT INTO categoria_atributos VALUES (13, 1, 1, 1, "ACTIVO")');
foreach ([[], [13 => '']] as $entrada) {
    $antes = filas($conexion);
    $conexion->beginTransaction();
    $rechazado = false;
    try {
        guardarAtributosProducto($conexion, 1, 1, [10 => 'cambio provisional'] + $entrada);
        $conexion->commit();
    } catch (DomainException $error) {
        $conexion->rollBack();
        $rechazado = true;
    }
    comprobar($rechazado && filas($conexion) === $antes, 'Un obligatorio ausente o vacío rechaza el guardado y permite revertir todos los cambios.');
}

echo 'Pruebas completadas.', PHP_EOL;
