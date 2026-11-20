<?php

declare(strict_types=1);
require_once __DIR__ . '/../auth/entrada.php';

// Variables que controlan el resultado y los mensajes mostrados en la vista.
$resultado = null;
$mensaje = '';
$cedula = '';
$dispositivos = [];

// Solo consulta la base de datos después de recibir una cédula por POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cedula = trim(textoEntrada($_POST, 'cedula'));

    if (!preg_match('/^[0-9]{4,20}$/D', $cedula)) {
        $mensaje = 'Ingresa una cédula válida.';
    } else {
        $conexion = require __DIR__ . '/../config/conexion.php';

        // La consulta pública muestra usuarios con rol FUNCIONARIO.
        $consulta = $conexion->prepare(
            'SELECT u.id_usuario, u.cedula, u.nombre, u.apellido, a.nombre AS area, u.estado
             FROM usuarios AS u
             INNER JOIN areas AS a ON a.id_area = u.id_area
             WHERE u.cedula = :cedula
               AND u.rol = "FUNCIONARIO"
             LIMIT 1'
        );
        $consulta->execute(['cedula' => $cedula]);
        $resultado = $consulta->fetch();

        if (!$resultado) {
            $mensaje = 'No encontramos información disponible para esta cédula.';
        } else {
            $consultaEquipos = $conexion->prepare(
                'SELECT p.descripcion, c.nombre AS categoria, a.nombre AS area,
                        ci.codigo, pa.fecha_asignacion
                 FROM producto_asignaciones pa
                 INNER JOIN productos p ON p.id_producto = pa.id_producto
                 LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
                 LEFT JOIN areas a ON a.id_area = p.id_area
                 LEFT JOIN producto_codigos pc ON pc.producto_vigente = p.id_producto
                 LEFT JOIN codigos_inventario ci ON ci.id_codigo = pc.id_codigo
                 WHERE pa.id_usuario = :usuario AND pa.fecha_devolucion IS NULL
                   AND p.estado = "ACTIVO"
                 ORDER BY c.nombre, p.descripcion, p.id_producto'
            );
            $consultaEquipos->execute(['usuario' => $resultado['id_usuario']]);
            $dispositivos = $consultaEquipos->fetchAll();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consulta por cédula | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/consulta/styles.css">
</head>
<body>
    <main>
        <div class="columna-consulta">
        <div class="contenido-consulta">
        <header class="presentacion-consulta">
            <img src="/sistemaInventario/img/logoADP.png" alt="ADP" class="logo-consulta">
            <span class="etiqueta-consulta">CONSULTA PERSONAL</span>
            <h1>Consulta por cédula</h1>
            <p>Encuentra tu información y los dispositivos que tienes asignados en el sistema de inventario.</p>
        </header>
        <form action="/sistemaInventario/consultas" method="post" class="formulario">
            <!-- La cédula es el único identificador requerido en el módulo público. -->
            <label for="cedula">Número de cédula</label>
            <div class="fila">
                <input
                    type="text"
                    id="cedula"
                    name="cedula"
                    value="<?= htmlspecialchars($cedula, ENT_QUOTES, 'UTF-8') ?>"
                    maxlength="20"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="Ej. 1020304050"
                    aria-describedby="ayuda-cedula"
                    required
                    autofocus
                >
                <button type="submit">Consultar dispositivos <span aria-hidden="true">→</span></button>
            </div>
            <p class="ayuda" id="ayuda-cedula">Escribe tu número de cédula sin puntos ni espacios.</p>
        </form>

        <?php if ($mensaje !== ''): ?>
            <div class="mensaje" role="alert"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($resultado): ?>
            <!-- Este bloque solo se renderiza cuando la consulta encontró información. -->
            <section class="resultado" aria-live="polite">
                <header>
                    <div>
                        <span>Resultado de la consulta</span>
                        <h2><?= htmlspecialchars($resultado['nombre'] . ' ' . $resultado['apellido'], ENT_QUOTES, 'UTF-8') ?></h2>
                    </div>
                    <strong class="estado <?= $resultado['estado'] === 'ACTIVO' ? 'estado-activo' : 'estado-inactivo' ?>"><?= htmlspecialchars($resultado['estado'], ENT_QUOTES, 'UTF-8') ?></strong>
                </header>
                <dl>
                    <div><dt>Cédula</dt><dd><?= htmlspecialchars($resultado['cedula'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div><dt>Área</dt><dd><?= htmlspecialchars($resultado['area'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                </dl>
            </section>
        <?php endif; ?>
        </div>
        </div>
        <section class="panel-dispositivos" aria-labelledby="titulo-dispositivos" aria-live="polite">
            <div class="contenido-dispositivos">
            <header class="encabezado-dispositivos">
                <div><span class="etiqueta-panel">SISTEMA DE INVENTARIO</span><h2 id="titulo-dispositivos">Dispositivos asignados</h2><p>Consulta el detalle de tus equipos con asignación vigente.</p></div>
                <?php if ($resultado): ?><span class="contador-dispositivos"><?= count($dispositivos) ?></span><?php endif; ?>
            </header>
            <?php if (!$resultado): ?>
                <div class="dispositivos-vacio">
                    <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="8" y="10" width="48" height="34" rx="4"/><path d="M8 36h48M25 44v10m14-10v10M20 54h24"/></svg>
                    <h3>Consulta tus dispositivos</h3>
                    <p>Ingresa tu cédula en el formulario para ver los equipos que tienes asignados.</p>
                </div>
            <?php elseif (!$dispositivos): ?>
                <div class="dispositivos-vacio"><h3>Sin dispositivos asignados</h3><p>No tienes equipos con una asignación vigente.</p></div>
            <?php else: ?>
                <ul class="lista-dispositivos">
                    <?php foreach ($dispositivos as $dispositivo): ?>
                        <li class="dispositivo">
                            <span class="categoria-dispositivo"><?= htmlspecialchars($dispositivo['categoria'] ?? 'Sin categoría', ENT_QUOTES, 'UTF-8') ?></span>
                            <h3><?= htmlspecialchars($dispositivo['descripcion'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <dl>
                                <div><dt>Código de inventario</dt><dd><?= htmlspecialchars($dispositivo['codigo'] ?? 'Sin código', ENT_QUOTES, 'UTF-8') ?></dd></div>
                                <div><dt>Área del equipo</dt><dd><?= htmlspecialchars($dispositivo['area'] ?? 'Sin área', ENT_QUOTES, 'UTF-8') ?></dd></div>
                                <div><dt>Asignado desde</dt><dd><?= htmlspecialchars(date('d/m/Y', strtotime($dispositivo['fecha_asignacion'])), ENT_QUOTES, 'UTF-8') ?></dd></div>
                            </dl>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <footer class="pie">ADP · Sistema de Inventario</footer>
            </div>
        </section>
    </main>
</body>
</html>
