<?php

declare(strict_types=1);

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

require_once __DIR__ . '/servicio.php';
require_once __DIR__ . '/alertas.php';
$conexion = require __DIR__ . '/../../config/conexion.php';
$resumen = null;
try {
    $resumen = consultarResumenDashboard($conexion);
} catch (PDOException $error) {
    error_log('Error al consultar el dashboard: ' . $error->getMessage());
}

$nombre = (string) ($_SESSION['administrador_nombre'] ?? 'Administrador');
$alertas = null;
try {
    $alertas = consultarAlertasDashboard($conexion, $_GET);
} catch (PDOException $error) {
    error_log('Error al consultar alertas del dashboard: ' . $error->getMessage());
}
$seccionActiva = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel administrativo | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/dashboard/dashboard.css">
</head>
<body>
    <div class="estructura-admin">
        <?php require __DIR__ . '/../menu_lateral.php'; ?>

        <div class="area-principal">
            <section class="encabezado-modulo">
                <h1>Dashboard</h1>
                <?php require __DIR__ . '/../usuario_encabezado.php'; ?>
            </section>
            <main class="contenido-dashboard">
                <?php require __DIR__ . '/panel_alertas.php'; ?>

                <?php if ($resumen === null): ?>
                    <p class="dashboard-aviso" role="alert">No fue posible cargar el resumen. Recarga la página para intentarlo nuevamente.</p>
                <?php else: ?>
                    <div class="dashboard-resumen">
                        <section class="dashboard-panel" aria-labelledby="titulo-categorias">
                            <h2 class="dashboard-titulo" id="titulo-categorias">Productos por categoría</h2>
                            <p>Distribución de los productos activos.</p>
                            <p class="dashboard-nota"><?= number_format($resumen['total_categorias'], 0, ',', '.') ?> productos · Pulsa una categoría para ocultarla o mostrarla.</p>
                            <?php if (!$resumen['categorias']): ?>
                                <p class="dashboard-vacio">No hay productos activos que coincidan con los filtros.</p>
                            <?php else: ?>
                                <?php
                                $segmentos = $resumen['categorias'];
                                $restantes = $resumen['total_categorias'] - array_sum(array_column($segmentos, 'total'));
                                if ($restantes > 0) {
                                    $segmentos[] = ['nombre' => 'Otras', 'total' => $restantes, 'id_categoria' => null];
                                }
                                $colores = ['#e44f5a', '#4385cf', '#26a58b', '#eea338', '#8a69c5', '#dc7c46', '#647b91'];
                                $angulo = -M_PI / 2;
                                ?>
                                <svg class="dashboard-pastel" data-segmentos="<?= htmlspecialchars(json_encode($segmentos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') ?>" data-colores="<?= htmlspecialchars(json_encode($colores), ENT_QUOTES, 'UTF-8') ?>" viewBox="0 0 320 320" role="img" aria-labelledby="pastel-titulo pastel-descripcion">
                                    <title id="pastel-titulo">Productos activos por categoría</title>
                                    <desc id="pastel-descripcion">Cada segmento muestra el porcentaje y debajo la cantidad de productos. Los segmentos pequeños muestran sus datos al pasar el cursor. La leyenda inferior identifica las categorías.</desc>
                                    <?php foreach ($segmentos as $indice => $categoria): ?>
                                        <?php
                                        $porcion = (int) $categoria['total'] / max(1, $resumen['total_categorias']);
                                        $fin = $angulo + $porcion * 2 * M_PI;
                                        $x1 = 160 + 150 * cos($angulo);
                                        $y1 = 160 + 150 * sin($angulo);
                                        $x2 = 160 + 150 * cos($fin);
                                        $y2 = 160 + 150 * sin($fin);
                                        $medio = ($angulo + $fin) / 2;
                                        $etiquetaX = 160 + 94 * cos($medio);
                                        $etiquetaY = 160 + 94 * sin($medio);
                                        ?>
                                        <g class="dashboard-sector">
                                            <title><?= htmlspecialchars($categoria['nombre'], ENT_QUOTES, 'UTF-8') ?>: <?= (int) $categoria['total'] ?> (<?= round($porcion * 100, 1) ?>%)</title>
                                            <?php if ($porcion >= 1): ?>
                                                <circle cx="160" cy="160" r="150" fill="<?= $colores[$indice] ?>" />
                                            <?php else: ?>
                                                <path d="M160 160 L<?= $x1 ?> <?= $y1 ?> A150 150 0 <?= $porcion > .5 ? 1 : 0 ?> 1 <?= $x2 ?> <?= $y2 ?> Z" fill="<?= $colores[$indice] ?>" stroke="#fff" stroke-width="1.5" />
                                            <?php endif; ?>
                                            <?php if ($porcion >= .05): ?>
                                                <text x="<?= $etiquetaX ?>" y="<?= $etiquetaY - 6 ?>" text-anchor="middle" dominant-baseline="middle" class="dashboard-porcentaje">
                                                    <tspan x="<?= $etiquetaX ?>"><?= round($porcion * 100, 1) ?>%</tspan>
                                                    <tspan x="<?= $etiquetaX ?>" dy="16" class="dashboard-cantidad-segmento"><?= number_format((int) $categoria['total'], 0, ',', '.') ?></tspan>
                                                </text>
                                            <?php endif; ?>
                                        </g>
                                        <?php $angulo = $fin; ?>
                                    <?php endforeach; ?>
                                    <circle cx="160" cy="160" r="7" fill="#fff" />
                                </svg>
                                <ul class="dashboard-leyenda-pastel">
                                    <?php foreach ($segmentos as $indice => $categoria): ?>
                                        <li style="--color-categoria: <?= $colores[$indice] ?>">
                                            <button type="button" data-segmento="<?= $indice ?>" aria-pressed="true">
                                                <?= htmlspecialchars($categoria['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>
                        <section class="dashboard-panel" aria-labelledby="titulo-asignacion">
                            <h2 class="dashboard-titulo" id="titulo-asignacion">Asignación del inventario</h2>
                            <p>Distribución de los productos activos.</p>
                            <?php if ($resumen['activos'] === 0): ?>
                                <p class="dashboard-vacio">Aún no hay productos activos para mostrar.</p>
                            <?php else: ?>
                                <div class="dashboard-dona" style="--asignacion: <?= round($resumen['asignados'] * 100 / $resumen['activos'], 4) ?>%" role="img" aria-label="<?= $resumen['porcentaje_asignado'] ?>% de productos con asignación; <?= $resumen['asignados'] ?> asignados y <?= $resumen['sin_asignacion'] ?> sin asignación">
                                    <div class="dashboard-dona-centro" aria-hidden="true">
                                        <strong><?= $resumen['porcentaje_asignado'] ?>%</strong>
                                        <span>con asignación</span>
                                    </div>
                                </div>
                                <ul class="dashboard-leyenda">
                                    <li><a href="/sistemaInventario/administracion/inventario?asignacion=CON"><span class="dashboard-punto asignados" aria-hidden="true"></span>Con asignación <strong><?= number_format($resumen['asignados'], 0, ',', '.') ?></strong></a></li>
                                    <li><a href="/sistemaInventario/administracion/inventario?asignacion=SIN"><span class="dashboard-punto sin-asignacion" aria-hidden="true"></span>Sin asignación <strong><?= number_format($resumen['sin_asignacion'], 0, ',', '.') ?></strong></a></li>
                                </ul>
                            <?php endif; ?>
                            <p class="dashboard-nota">Sin asignación indica que no hay un responsable actual; consulta el inventario para revisar cada producto.</p>
                        </section>
                    </div>

                    <div class="dashboard-resumen">
                        <section class="dashboard-panel" aria-labelledby="titulo-areas">
                            <h2 class="dashboard-titulo" id="titulo-areas">Productos por área</h2>
                            <p>Productos activos en cada área.</p>
                            <?php $maximoArea = max([1, ...array_map(static fn (array $area): int => (int) $area['total'], $resumen['areas'])]); ?>
                            <?php if (!$resumen['areas']): ?>
                                <p class="dashboard-vacio">Aún no hay áreas registradas.</p>
                            <?php else: ?>
                                <ul class="dashboard-areas">
                                    <?php foreach ($resumen['areas'] as $indice => $area): ?>
                                        <li>
                                            <a href="/sistemaInventario/administracion/inventario?area=<?= (int) $area['id_area'] ?>">
                                                <span><?= htmlspecialchars($area['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <strong><?= number_format((int) $area['total'], 0, ',', '.') ?></strong>
                                                <span class="dashboard-area-pista" aria-hidden="true"><span style="width: <?= round((int) $area['total'] * 100 / $maximoArea, 2) ?>%; background: <?= ['#4385cf', '#26a58b', '#8a69c5', '#eea338', '#e44f5a'][$indice % 5] ?>"></span></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>
                        <section class="dashboard-panel" aria-labelledby="titulo-actividad">
                            <h2 class="dashboard-titulo" id="titulo-actividad">Ingresos y bajas por mes · <?= $resumen['anio'] ?></h2>
                            <?php
                            $totalIngresos = array_sum(array_column($resumen['meses'], 'ingresos'));
                            $totalBajas = array_sum(array_column($resumen['meses'], 'bajas'));
                            $maximoActividad = max(1, ...array_map(static fn (array $mes): int => $mes['ingresos'] + $mes['bajas'], $resumen['meses']));
                            ?>
                            <div class="dashboard-actividad-totales"><span><i class="actividad-alta"></i>ingresos <strong><?= $totalIngresos ?></strong></span><span><i class="actividad-baja"></i>Bajas <strong><?= $totalBajas ?></strong></span></div>
                            <div class="dashboard-meses-scroll" tabindex="0" role="region" aria-label="Actividad mensual; desplaza horizontalmente para ver todos los meses">
                                <ol class="dashboard-meses">
                                    <?php foreach ($resumen['meses'] as $mes): ?>
                                        <li>
                                            <span class="dashboard-mes-total" aria-hidden="true"><?= $mes['ingresos'] + $mes['bajas'] ?></span>
                                            <div class="dashboard-mes-pista" role="img" aria-label="<?= $mes['nombre'] ?>: <?= $mes['ingresos'] ?> ingresos y <?= $mes['bajas'] ?> bajas" title="<?= $mes['nombre'] ?>: <?= $mes['ingresos'] ?> ingresos y <?= $mes['bajas'] ?> bajas">
                                                <span class="actividad-baja" style="height: <?= round($mes['bajas'] * 100 / $maximoActividad, 3) ?>%"></span>
                                                <span class="actividad-alta" style="height: <?= round($mes['ingresos'] * 100 / $maximoActividad, 3) ?>%"></span>
                                            </div>
                                            <span class="dashboard-mes-nombre"><?= $mes['nombre'] ?></span>
                                            <span class="dashboard-mes-detalle"><?= $mes['ingresos'] ?> / <?= $mes['bajas'] ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                            <?php if ($totalIngresos + $totalBajas === 0): ?><p class="dashboard-vacio">No hay actividad registrada este año.</p><?php endif; ?>
                            <p class="dashboard-nota">Debajo de cada mes: ingresos / bajas. Los ingresos corresponden a la fecha de creación y las bajas a la fecha efectiva del movimiento.</p>
                        </section>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>
    <script src="/sistemaInventario/admin/dashboard/graficas.js" defer></script>
</body>
</html>
