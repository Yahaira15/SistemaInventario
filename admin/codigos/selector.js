(() => {
    const selector = document.getElementById('selector-codigos');
    const categoria = document.getElementById('categoria');

    if (!selector || !categoria) return;

    const campo = document.getElementById('buscar-codigo');
    const identificador = document.getElementById('id-codigo');
    const abrir = document.getElementById('abrir-codigos');
    const desplegable = document.getElementById('desplegable-codigos');
    const lista = document.getElementById('opciones-codigo');
    const vacio = document.getElementById('sin-codigos');
    const estado = document.getElementById('estado-codigo');
    const generar = document.getElementById('generar-codigo');
    let primeraCarga = true;

    function aplicarGeneracion() {
        cerrar();
        identificador.value = '';
        campo.readOnly = generar.checked;
        campo.disabled = false;
        abrir.disabled = generar.checked;
        campo.value = generar.checked ? categoria.selectedOptions[0].dataset.codigoEstimado : '';
        campo.setCustomValidity(generar.checked ? '' : 'Selecciona un código de la lista.');
        mostrarMensaje(
            generar.checked
                ? 'Código estimado; se confirma al guardar.'
                : 'Selecciona un código disponible.',
        );
    }

    const configuracion = JSON.parse(document.getElementById('config-productos').textContent);

    let codigos = [];
    let filtrados = [];
    let posicion = -1;
    let solicitud = 0;
    let controlador = null;

    function mostrarMensaje(texto, error = false) {
        estado.textContent = texto;
        estado.classList.toggle('es-error', error);
    }

    function cerrar() {
        desplegable.hidden = true;
        campo.setAttribute('aria-expanded', 'false');
        campo.removeAttribute('aria-activedescendant');
        posicion = -1;
    }

    function seleccionar(codigo) {
        identificador.value = String(codigo.id_codigo);
        campo.value = codigo.codigo;
        campo.setCustomValidity('');
        cerrar();
        campo.focus();
    }

    function dibujar() {
        const busqueda = campo.value.trim().toUpperCase();

        filtrados = codigos.filter((codigo) => codigo.codigo.toUpperCase().includes(busqueda));

        lista.replaceChildren();
        posicion = -1;
        campo.removeAttribute('aria-activedescendant');

        for (const codigo of filtrados) {
            const opcion = document.createElement('li');

            opcion.id = `opcion-codigo-${codigo.id_codigo}`;
            opcion.setAttribute('role', 'option');
            opcion.setAttribute(
                'aria-selected',
                String(identificador.value === String(codigo.id_codigo)),
            );
            opcion.textContent = codigo.codigo;

            opcion.addEventListener('click', () => seleccionar(codigo));

            lista.append(opcion);
        }
        vacio.hidden = filtrados.length > 0;
    }

    function desplegar() {
        if (campo.disabled || campo.readOnly) return;

        dibujar();
        desplegable.hidden = false;
        campo.setAttribute('aria-expanded', 'true');
    }

    async function cargar() {
        const turno = ++solicitud;
        const categoriaSolicitada = categoria.value;

        controlador?.abort();
        controlador = new AbortController();

        cerrar();
        codigos = [];
        lista.replaceChildren();
        identificador.value = '';
        campo.value = '';
        campo.readOnly = false;
        generar.checked = false;
        generar.disabled = true;
        campo.disabled = true;
        abrir.disabled = true;
        campo.setCustomValidity('Selecciona un código de la lista.');

        if (!categoriaSolicitada) {
            campo.placeholder = 'Selecciona una categoría';
            mostrarMensaje('Selecciona primero una categoría para consultar sus códigos.');
            return;
        }

        campo.placeholder = 'Cargando códigos…';
        mostrarMensaje('Cargando códigos…');

        const parametros = new URLSearchParams({
            categoria: categoriaSolicitada,
        });

        // AL CREAR SE OMITE ESTE PARAMETRO: NO ENVIAMOS PRODUCTOS=0
        if (Number(configuracion.producto) > 0) {
            parametros.set('producto', String(configuracion.producto));
        }

        try {
            const respuesta = await fetch(
                `/sistemaInventario/admin/codigos/disponibles.php?${parametros}`,
                { signal: controlador.signal },
            );

            if (!respuesta.ok) {
                throw new Error('No fue posible consultar los códigos.');
            }

            const datos = await respuesta.json();

            if (turno !== solicitud || categoriaSolicitada !== categoria.value) return;

            if (!Array.isArray(datos.codigos)) {
                throw new Error('La respuesta no es válida.');
            }

            codigos = datos.codigos;
            generar.disabled = categoria.selectedOptions[0]?.dataset.generable !== '1';
            campo.disabled = false;
            abrir.disabled = false;
            campo.placeholder = 'Buscar un código';

            mostrarMensaje(
                codigos.length
                    ? 'Selecciona un código disponible'
                    : 'No hay códigos disponibles; pueden estar asignados.',
            );
            if (primeraCarga) {
                const actual = codigos.find(
                    (c) => Number(c.id_codigo) === Number(configuracion.id_codigo),
                );
                if (configuracion.generar_codigo && !generar.disabled) {
                    generar.checked = true;
                    aplicarGeneracion();
                } else if (actual) {
                    identificador.value = String(actual.id_codigo);
                    campo.value = actual.codigo;
                    campo.setCustomValidity('');
                }
                primeraCarga = false;
            }
        } catch (error) {
            if (turno !== solicitud || error.name === 'AbortError') return;

            campo.placeholder = 'No se pudieron cargar los códigos';
            mostrarMensaje('Error de carga. Pulsa la flecha para reintentar.', true);

            abrir.disabled = false;
        }
    }

    categoria.addEventListener('change', cargar);
    generar.addEventListener('change', aplicarGeneracion);

    campo.addEventListener('input', () => {
        identificador.value = '';
        campo.setCustomValidity('Selecciona un código de la lista.');
        desplegar();
    });

    abrir.addEventListener('click', () => {
        if (campo.disabled) {
            cargar();
            return;
        }

        if (desplegable.hidden) {
            campo.focus();
            desplegar();
        } else {
            cerrar();
        }
    });

    campo.addEventListener('click', desplegar);

    campo.addEventListener('keydown', (evento) => {
        if (campo.readOnly) return;
        if (evento.key === 'Escape') {
            cerrar();
            return;
        }

        if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
            evento.preventDefault();

            if (desplegable.hidden) desplegar();
            if (!filtrados.length) return;

            const paso = evento.key === 'ArrowDown' ? 1 : -1;

            posicion =
                posicion === -1
                    ? paso === 1
                        ? 0
                        : filtrados.length - 1
                    : (posicion + paso + filtrados.length) % filtrados.length;

            Array.from(lista.children).forEach((opcion, indice) => {
                opcion.classList.toggle('opcion-activa', indice === posicion);
            });

            const activa = lista.children[posicion];

            campo.setAttribute('aria-activedescendant', activa.id);
            activa.scrollIntoView({ block: 'nearest' });
        }

        if (evento.key === 'Enter' && !desplegable.hidden) {
            evento.preventDefault();

            if (posicion >= 0) seleccionar(filtrados[posicion]);
        }
    });

    document.addEventListener('click', (evento) => {
        if (!selector.contains(evento.target)) cerrar();
    });

    categoria.form.addEventListener('submit', (evento) => {
        if (!(generar.checked && !generar.disabled) && (campo.disabled || !identificador.value)) {
            evento.preventDefault();
            mostrarMensaje('Selecciona un código antes de guardar.', true);
        }
    });

    cargar();
})();
