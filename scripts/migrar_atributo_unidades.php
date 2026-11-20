<?php

declare(strict_types=1);

// Migración para MariaDB 10.4. Solo CLI; sin argumentos muestra la vista previa.
// Uso: C:\xampp\php\php.exe scripts/migrar_atributo_unidades.php --aplicar
// Conserva atributos.unidad para compatibilidad con los formularios actuales.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$c = require __DIR__ . '/../config/conexion.php';
$aplicar = in_array('--aplicar', $argv, true);

function respaldoTabla(PDO $c, string $tabla): array
{
    return [
        'ddl' => $c->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_NUM)[1],
        'filas' => $c->query("SELECT * FROM `$tabla` ORDER BY 1")->fetchAll(),
    ];
}

function agregarReferencia(PDO $c, string $tabla, string $columna, string $destino, string $nombre): void
{
    $q = $c->prepare('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        AND REFERENCED_TABLE_NAME IS NOT NULL');
    $q->execute([$tabla, $columna]);
    $referencias = $q->fetchAll(PDO::FETCH_COLUMN);
    if ($referencias === []) {
        $c->exec("ALTER TABLE `$tabla` ADD CONSTRAINT `$nombre` FOREIGN KEY (`$columna`)
            REFERENCES `$destino` (`$columna`) ON UPDATE CASCADE ON DELETE RESTRICT");
    } elseif ($referencias !== [$destino]) {
        throw new RuntimeException('Referencia inesperada en ' . $tabla . '.' . $columna);
    }
}

try {
    $fuentes = $c->query("SELECT id_atributo, unidad, estado FROM atributos
        WHERE unidad IS NOT NULL AND TRIM(unidad) <> '' ORDER BY id_atributo")->fetchAll();
    echo 'Unidades de origen: ', count($fuentes), PHP_EOL;
    foreach ($fuentes as $fuente) {
        echo 'Atributo ', $fuente['id_atributo'], ': ', $fuente['unidad'], PHP_EOL;
    }
    if (!$aplicar) {
        echo 'Vista previa. Ejecuta con --aplicar para migrar.', PHP_EOL;
        exit;
    }

    // El respaldo queda fuera de la carpeta pública de XAMPP.
    $respaldo = [];
    foreach (['atributos', 'categoria_atributos', 'producto_atributo_valores'] as $tabla) {
        $respaldo[$tabla] = respaldoTabla($c, $tabla);
    }
    $existe = $c->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atributo_unidades'")->fetchColumn();
    if ((int) $existe > 0) {
        $respaldo['atributo_unidades'] = respaldoTabla($c, 'atributo_unidades');
    }
    $ruta = tempnam(sys_get_temp_dir(), 'inventario_unidades_');
    if ($ruta === false || file_put_contents($ruta, json_encode($respaldo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('No se pudo guardar el respaldo; no se aplicaron cambios.');
    }
    echo 'Respaldo de estructura y filas: ', $ruta, PHP_EOL;

    // El DDL de MariaDB confirma automáticamente: no forma parte del rollback de datos.
    // simbolo conserva el nombre de columna que ya existe en esta instalación.
    $c->exec("CREATE TABLE IF NOT EXISTS atributo_unidades (
        id_atributo_unidad INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_atributo INT UNSIGNED NOT NULL,
        simbolo VARCHAR(50) NOT NULL,
        estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
        fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_actualizacion DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_atributo_unidad (id_atributo, simbolo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci");
    $c->exec("ALTER TABLE atributo_unidades
        MODIFY id_atributo INT UNSIGNED NOT NULL,
        MODIFY estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
        MODIFY fecha_actualizacion DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
    $indice = $c->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'atributo_unidades' AND INDEX_NAME = 'uq_atributo_unidad'")->fetchColumn();
    if ($indice === 'simbolo') {
        $c->exec('ALTER TABLE atributo_unidades DROP INDEX uq_atributo_unidad,
            ADD UNIQUE KEY uq_atributo_unidad (id_atributo, simbolo)');
    } elseif ($indice === null || $indice === false) {
        $c->exec('ALTER TABLE atributo_unidades ADD UNIQUE KEY uq_atributo_unidad (id_atributo, simbolo)');
    } elseif ($indice !== 'id_atributo,simbolo') {
        throw new RuntimeException('Índice de unidades inesperado. Revisar antes de continuar.');
    }
    $c->exec('ALTER TABLE producto_atributo_valores ADD COLUMN IF NOT EXISTS id_atributo_unidad INT NULL');
    agregarReferencia($c, 'atributo_unidades', 'id_atributo', 'atributos', 'fk_unidad_atributo');
    agregarReferencia($c, 'producto_atributo_valores', 'id_atributo_unidad', 'atributo_unidades', 'fk_producto_valor_unidad');

    $c->beginTransaction();
    // Bloquea las fuentes y los valores durante la copia y la comprobación.
    $c->query('SELECT id_atributo FROM atributos ORDER BY id_atributo FOR UPDATE')->fetchAll();
    $c->query('SELECT id_categoria_atributo FROM categoria_atributos ORDER BY id_categoria_atributo FOR UPDATE')->fetchAll();
    $antes = $c->query('SELECT * FROM producto_atributo_valores ORDER BY id_producto_atributo_valor FOR UPDATE')->fetchAll();
    $insertadas = $c->exec("INSERT INTO atributo_unidades (id_atributo, simbolo, estado)
        SELECT a.id_atributo, a.unidad, a.estado FROM atributos a
        WHERE a.unidad IS NOT NULL AND TRIM(a.unidad) <> ''
          AND NOT EXISTS (SELECT 1 FROM atributo_unidades u
              WHERE u.id_atributo = a.id_atributo AND u.simbolo = a.unidad)");
    $vinculadas = $c->exec("UPDATE producto_atributo_valores pav
        INNER JOIN categoria_atributos ca ON ca.id_categoria_atributo = pav.id_categoria_atributo
        INNER JOIN atributos a ON a.id_atributo = ca.id_atributo
        INNER JOIN atributo_unidades u ON u.id_atributo = a.id_atributo AND u.simbolo = a.unidad
        SET pav.id_atributo_unidad = u.id_atributo_unidad,
            pav.fecha_actualizacion = pav.fecha_actualizacion
        WHERE pav.valor_numero IS NOT NULL AND pav.id_atributo_unidad IS NULL");
    $despues = $c->query('SELECT * FROM producto_atributo_valores ORDER BY id_producto_atributo_valor')->fetchAll();
    if (count($antes) !== count($despues)) {
        throw new RuntimeException('Cambió la cantidad de registros.');
    }
    foreach ($antes as $i => $fila) {
        $actual = $despues[$i];
        if ($fila['id_atributo_unidad'] !== null && $fila['id_atributo_unidad'] !== $actual['id_atributo_unidad']) {
            throw new RuntimeException('Se modificó una unidad previamente vinculada.');
        }
        unset($fila['id_atributo_unidad'], $actual['id_atributo_unidad']);
        if ($fila !== $actual) {
            throw new RuntimeException('Se modificó un dato original; se revierte la copia.');
        }
    }
    $pendientes = (int) $c->query("SELECT COUNT(*) FROM producto_atributo_valores pav
        JOIN categoria_atributos ca ON ca.id_categoria_atributo = pav.id_categoria_atributo
        JOIN atributos a ON a.id_atributo = ca.id_atributo
        WHERE pav.valor_numero IS NOT NULL AND a.unidad IS NOT NULL
          AND TRIM(a.unidad) <> '' AND pav.id_atributo_unidad IS NULL")->fetchColumn();
    $invalidas = (int) $c->query("SELECT COUNT(*) FROM producto_atributo_valores pav
        JOIN categoria_atributos ca ON ca.id_categoria_atributo = pav.id_categoria_atributo
        JOIN atributo_unidades u ON u.id_atributo_unidad = pav.id_atributo_unidad
        WHERE u.id_atributo <> ca.id_atributo OR pav.valor_numero IS NULL")->fetchColumn();
    if ($pendientes > 0 || $invalidas > 0) {
        throw new RuntimeException('Existen vínculos pendientes o unidades de otro atributo.');
    }
    $c->commit();
    echo "Unidades creadas: $insertadas. Valores vinculados: $vinculadas.", PHP_EOL;
    echo 'Verificados ', count($antes), ' registros: cantidades, otros valores y fechas intactos.', PHP_EOL;
} catch (Throwable $e) {
    if ($c->inTransaction()) {
        $c->rollBack();
    }
    fwrite(STDERR, 'Migración interrumpida: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
