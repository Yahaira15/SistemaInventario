<?php
$consultaGrafica = http_build_query([
    'grafica_area' => $resumen['filtros_categorias']['area'] ?? 0,
    'grafica_asignacion' => $resumen['filtros_categorias']['asignacion'] ?? '',
]);
$prefijoAlertas = '?' . htmlspecialchars($consultaGrafica, ENT_QUOTES, 'UTF-8') . '&amp;';
?>
<section class="dashboard-panel dashboard-alertas" aria-labelledby="titulo-alertas">
    <h2 class="dashboard-titulo" id="titulo-alertas">Alertas de registros</h2>
    <?php if ($alertas === null): ?>
        <p role="alert">No fue posible consultar las alertas. Recarga la página para intentarlo nuevamente.</p>
    <?php else: ?>
        <div class="dashboard-alertas-grid">
            <?php foreach ($alertas['reglas'] as $clave => $regla): ?>
                <a class="dashboard-alerta-tarjeta <?= $regla['total'] === 0 ? 'sin-pendientes' : '' ?> <?= $alertas['seleccion'] === $clave ? 'seleccionada' : '' ?>" href="<?= $prefijoAlertas ?>alerta=<?= $clave ?>#detalle-alertas" title="<?= htmlspecialchars($regla['descripcion'], ENT_QUOTES, 'UTF-8') ?>">
                    <span class="dashboard-alerta-estado"><?= $regla['total'] > 0 ? 'Por revisar' : 'Sin pendientes' ?></span>
                    <strong class="dashboard-alerta-cifra"><?= number_format($regla['total'], 0, ',', '.') ?></strong>
                    <h3><?= htmlspecialchars($regla['titulo'], ENT_QUOTES, 'UTF-8') ?></h3>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($alertas['seleccion'] !== ''): ?>
            <div id="detalle-alertas" class="dashboard-alerta-detalle">
                <h3><?= htmlspecialchars($alertas['reglas'][$alertas['seleccion']]['titulo'], ENT_QUOTES, 'UTF-8') ?> · <?= $alertas['total'] ?> productos</h3>
                <p><?= htmlspecialchars($alertas['reglas'][$alertas['seleccion']]['descripcion'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!$alertas['productos']): ?>
                    <p>No hay productos con esta novedad.</p>
                <?php else: ?>
                    <ul class="dashboard-alerta-lista">
                        <?php foreach ($alertas['productos'] as $producto): ?>
                            <li>
                                <div><strong>#<?= (int) $producto['id_producto'] ?> · <?= htmlspecialchars(trim((string) $producto['descripcion']) ?: 'Sin descripción', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <p><?= htmlspecialchars($producto['categoria'] ?? 'Sin categoría', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($producto['area'] ?? 'Sin área', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <a href="/sistemaInventario/administracion/productos?editar=<?= (int) $producto['id_producto'] ?>">Revisar producto #<?= (int) $producto['id_producto'] ?> &rarr;</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <nav class="dashboard-alerta-paginacion" aria-label="Páginas de productos con alertas">
                        <?php if ($alertas['pagina'] > 1): ?><a href="<?= $prefijoAlertas ?>alerta=<?= $alertas['seleccion'] ?>&amp;pagina_alerta=<?= $alertas['pagina'] - 1 ?>#detalle-alertas">Anterior</a><?php endif; ?>
                        <span>Página <?= $alertas['pagina'] ?> de <?= $alertas['paginas'] ?></span>
                        <?php if ($alertas['pagina'] < $alertas['paginas']): ?><a href="<?= $prefijoAlertas ?>alerta=<?= $alertas['seleccion'] ?>&amp;pagina_alerta=<?= $alertas['pagina'] + 1 ?>#detalle-alertas">Siguiente</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
