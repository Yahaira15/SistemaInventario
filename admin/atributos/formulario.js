(() => {
    const tipoDato = document.getElementById('tipoDato');
    const listaOpciones = document.getElementById('lista-opciones');
    const agregarOpcion = document.getElementById('agregar-opcion');

    function actualizarCamposPorTipo() {
        const tipo = tipoDato.value;

        document.querySelectorAll('[data-configuracion-tipo]').forEach((grupo) => {
            const visible = grupo.dataset.configuracionTipo === tipo;
            grupo.hidden = !visible;

            grupo.querySelectorAll('input, select, textarea').forEach((campo) => {
                campo.disabled = !visible;
            });
        });
    }

    tipoDato?.addEventListener('change', actualizarCamposPorTipo);

    if (tipoDato) {
        actualizarCamposPorTipo();
    }

    function actualizarOpciones() {
        if (!listaOpciones) {
            return;
        }

        const filas = listaOpciones.querySelectorAll('.fila-opcion');

        filas.forEach((fila, indice) => {
            const entrada = fila.querySelector('input[name="opciones[]"]');
            const quitar = fila.querySelector('.quitar-opcion');
            entrada.required = tipoDato.value === 'SELECCION' && filas.length === 1;
            entrada.setAttribute('aria-label', `Opción ${indice + 1}`);
            quitar.disabled = filas.length === 1;
        });
    }

    agregarOpcion?.addEventListener('click', () => {
        const fila = document.createElement('div');
        fila.className = 'fila-opcion';
        fila.innerHTML = `
        <input type="text" name="opciones[]" maxlength="100" placeholder="Nueva opción">
        <button type="button" class="boton secundario quitar-opcion">Quitar</button>
    `;
        listaOpciones.appendChild(fila);
        actualizarOpciones();
        fila.querySelector('input').focus();
    });

    listaOpciones?.addEventListener('click', (evento) => {
        const boton = evento.target.closest('.quitar-opcion');

        if (boton && listaOpciones.children.length > 1) {
            boton.closest('.fila-opcion').remove();
            actualizarOpciones();
        }
    });

    tipoDato.addEventListener('change', actualizarOpciones);
    actualizarOpciones();
})();

(() => {
    const lista = document.getElementById('lista-unidades');
    document.getElementById('agregar-unidad')?.addEventListener('click', () => {
        if (lista.children.length >= 50) return;
        const fila = document.createElement('div');
        fila.className = 'fila-opcion';
        fila.innerHTML =
            '<input type="text" name="unidades[]" maxlength="50" aria-label="Unidad permitida" placeholder="Ejemplo: TB"><button type="button" class="boton secundario quitar-opcion">Quitar</button>';
        lista.appendChild(fila);
        fila.querySelector('input').focus();
    });
    lista?.addEventListener('click', (evento) => {
        const boton = evento.target.closest('.quitar-opcion');
        if (!boton) return;
        if (lista.children.length === 1) lista.querySelector('input').value = '';
        else boton.closest('.fila-opcion').remove();
    });
})();
