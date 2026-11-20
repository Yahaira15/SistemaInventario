<section class="historial-inventario" data-historial-producto="<?= (int) $id ?>">

    <h3>Historial de asignaciones</h3>
    <p class="nota-historial">
        Las asignaciones finalizadas incluyen devoluciones y cierres por baja. 
    </p>
    
    <p data-estado-historial role="status">
        Abre el historial para consultar los registros.
    </p>
    <div class="tabla-contenedor">
        <table>
            <thead>
                <tr>
                    <th>Responsable</th>
                    <th>Cédula</th>
                    <th>Asignación</th>
                    <th>Finalización</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody data-filas-historial></tbody>
        </table>
    </div>
    <div class="acciones-historial">
        <nav class="paginacion" data-paginacion-historial aria-label="Páginas del historial" hidden>
            <button 
                type="button" 
                class="pagina-historial" 
                data-anterior-historial 
                aria-label="Página anterior" 
                disabled
            >‹</button>

            <span class="numeros-historial" data-pagina-historial></span>

            <button 
                type="button" 
                class="pagina-historial" 
                data-siguiente-historial 
                aria-label="Página siguiente" 
                disabled
            >›</button>
        </nav>

        <button 
            type="button" 
            class="boton secundario" 
            data-actualizar-historial
        >Actualizar</button>
    </div>
</section>
