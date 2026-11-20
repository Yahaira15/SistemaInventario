<dialog class="modal modal-inventario modal-atributos-inventario" id="atributos-<?= $id ?>"
    aria-labelledby="titulo-atributos-<?= $id ?>">
    <div class="modal-contenido">
        <header class="encabezado-modal-inventario">
            <h2 id="titulo-atributos-<?= $id ?>">Atributos del equipo</h2>
            <button type="button" class="cerrar" aria-label="Cerrar ventana"
                onclick="cerrarModal('atributos-<?= $id ?>')">×</button>
        </header>
        <div class="equipo-modal">
            <span class="codigo-modal"><?= escaparInventario($producto['codigo'] ?? 'Sin código vigente') ?></span>
            <p><?= escaparInventario($producto['descripcion']) ?></p>
        </div>
        <div class="contenido-atributos-inventario">
            <p class="categoria-atributos-inventario"><?= escaparInventario($producto['categoria']) ?></p>
            <?php if ($atributosInventario[$id] ?? []): ?>
                <dl class="ficha-atributos-inventario">
                    <?php foreach ($atributosInventario[$id] as $atributo): ?>
                        <div class="dato-atributo-inventario">
                            <dt><?= escaparInventario($atributo['nombre']) ?></dt>
                            <dd<?= $atributo['valor'] === 'Sin valor' ? ' class="atributo-sin-valor"' : '' ?>><?= escaparInventario($atributo['valor']) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php else: ?>
                <p class="vacio-modal">Este equipo no tiene atributos para mostrar.</p>
            <?php endif; ?>
        </div>
    </div>
</dialog>
