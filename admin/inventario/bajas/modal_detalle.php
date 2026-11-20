<?php
// Plantilla de consulta; $producto e $id proceden del listado protegido de bajas.
?>
<dialog class="modal modal-inventario modal-detalle-baja" id="detalle-baja-<?= $id ?>"
    aria-labelledby="titulo-detalle-baja-<?= $id ?>">
    <div class="modal-contenido">
        <header class="encabezado-modal-inventario">
            <h2 id="titulo-detalle-baja-<?= $id ?>">Detalle de la baja</h2>
            <button type="button" class="cerrar" aria-label="Cerrar detalle de baja"
                onclick="cerrarModal('detalle-baja-<?= $id ?>')">×</button>
        </header>
        <div class="equipo-modal">
            <span class="codigo-modal"><?= escaparListadoBajas($producto['codigo'] ?? 'Sin código registrado') ?></span>
            <p><?= escaparListadoBajas($producto['descripcion']) ?></p>
        </div>
        <div class="contenido-detalle-baja">
            <p class="estado-detalle-baja"><span aria-hidden="true"></span>Equipo retirado del inventario</p>
            <?php if ($producto['id_movimiento'] === null): ?>
                <p class="alerta error" role="status">Registro anterior sin movimiento de baja. Su fecha y motivo requieren revisión.</p>
            <?php endif; ?>
            <dl class="ficha-detalle-baja">
                <div><dt>Categoría</dt><dd><?= escaparListadoBajas($producto['categoria']) ?></dd></div>
                <div><dt>Área</dt><dd><?= escaparListadoBajas($producto['area']) ?></dd></div>
                <div><dt>Fecha de baja</dt><dd><?= escaparListadoBajas(
                    $producto['fecha_movimiento'] !== null
                        ? (new DateTimeImmutable($producto['fecha_movimiento']))->format('d/m/Y H:i')
                        : 'Sin registro de baja'
                ) ?></dd></div>
                <div><dt>Registrada por</dt><dd><?= escaparListadoBajas($producto['administrador'] ?? 'Sin registro') ?></dd></div>
                <div class="detalle-baja-completo"><dt>Último usuario asignado</dt><dd><?= escaparListadoBajas($producto['ultimo_responsable'] ?? 'Sin asignaciones registradas') ?></dd></div>
                <div class="detalle-baja-completo recuadro-motivo-baja"><dt>Motivo de la baja</dt><dd class="motivo-detalle-baja"><?= escaparListadoBajas($producto['motivo'] ?? 'Sin registro') ?></dd></div>
            </dl>
            <p class="nota-detalle-baja">El historial del equipo se conserva. Este equipo no admite nuevas asignaciones.</p>
        </div>
    </div>
</dialog>