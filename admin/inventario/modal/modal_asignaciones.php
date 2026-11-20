<dialog class="modal modal-inventario" id="modal-<?= $id ?>" aria-labelledby="titulo-modal-<?= $id ?>" <?= $recuperar ? 'data-recuperar-formulario' : '' ?>>
    <div class="modal-contenido">
        <header class="encabezado-modal-inventario">
            <div>
                <h2 id="titulo-modal-<?= $id ?>">Administrar asignaciones</h2>
            </div>
            <button 
                type="button"
                class="cerrar" 
                onclick="cerrarModal('modal-<?= $id ?>')"
                aria-label="Cerrar ventana"
            >×</button>
        </header>

        <div class="equipo-modal">
            <span class="codigo-modal"><?= escaparInventario($producto['codigo'] ?? 'Sin código vigente') ?></span>
            <p><?= escaparInventario($producto['descripcion']) ?></p>
        </div>
            
        <div class="formulario-modal">
            <?php if ($recuperar): ?>
                <div class="alerta error" role="alert"><?= escaparInventario($error) ?></div>
            <?php endif; ?>
            <form
                id="asignacion-<?= $id ?>"
                class="asignar-inventario"
                method="post"
                action="/sistemaInventario/administracion/inventario/procesar"
                data-buscador-usuarios
                data-producto="<?= $id ?>"
            >
                <?php camposInventario($filtros, $id, $recuperar ? ($tokenResultado ?? null) : null); ?>

                <input type="hidden" name="accion" value="asignar">
                <input type="hidden" name="id_usuario" value="<?= $usuarioRecuperado > 0 ? (int) $usuarioRecuperado : '' ?>">

                <label for="usuario-<?= $id ?>">Asignar responsable</label>

                <div class="buscador-usuarios">
                    <input
                        type="search"
                        id="usuario-<?= $id ?>"
                        name="responsable_texto"
                        value="<?= escaparInventario($textoRecuperado) ?>"
                        class="buscar-usuario"
                        placeholder="Nombre, apellido o cédula"
                        autocomplete="off"
                        maxlength="100"
                        aria-describedby="ayuda-usuario-<?= $id ?>"
                        required
                        autofocus
                    >
                    <div class="resultados-usuarios" hidden></div>
                </div>

                <small id="ayuda-usuario-<?= $id ?>" class="estado-busqueda" role="status">
                    Escribe al menos 2 caracteres y selecciona un resultado.
                </small>
                <button 
                    class="boton asignacion" 
                    type="submit"
                    <?= $usuarioRecuperado > 0 ? '' : 'disabled' ?>
                >Asignar</button>
            </form>
        </div>

        <section class="responsables-modal" aria-labelledby="responsables-titulo-<?= $id ?>">
            <div class="titulo-responsables-modal">
                <h3 id="responsables-titulo-<?= $id ?>">Responsables actuales</h3>
            </div>
                <?php if (!$responsables): ?>
                    <p class="vacio-modal">Aún no hay responsables asignados.</p>
                <?php else: ?>
                    <?php foreach ($responsables as $responsable): ?>
                    <form method="post" action="/sistemaInventario/administracion/inventario/procesar" data-devolucion
                        data-responsable="<?= escaparInventario($responsable['nombre']) ?>"
                        data-equipo="<?= escaparInventario($producto['codigo'] ?? $producto['descripcion']) ?>">
                        <?php camposInventario($filtros, $id, $recuperar ? ($tokenResultado ?? null) : null); ?>
                        <input type="hidden" name="accion" value="devolver">
                        <input type="hidden" name="id_asignacion" value="<?= (int) $responsable['id_producto_asignacion'] ?>">
                        
                        <div class="responsable-modal">
                            <span class="usuario-reponsable"><?= escaparInventario($responsable['nombre']) ?></span>
                        </div>
                        <button 
                            type="submit"
                            class="boton-tabla" 
                            aria-label="<?= escaparInventario(
                                'Registrar devolución de ' . $responsable['nombre']
                            ) ?>"
                        >Devolver</button>
                    </form>
                    <?php endforeach; ?>
                <?php endif; ?>
        </section>
        <details class="desplegable-historial">
            <summary>Ver historial</summary>
            <?php require __DIR__ . '/panel_historial.php'; ?>
        </details>
    </div>
</dialog>
