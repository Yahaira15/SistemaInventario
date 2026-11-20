(() => {
    const select = document.getElementById('area');
    if (!select) return;

    const opciones = Array.from(select.options)
        .filter((opcion) => opcion.value && !opcion.disabled)
        .map((opcion) => ({ id: opcion.value, nombre: opcion.textContent.trim() }));
    const contenedor = document.createElement('div');
    contenedor.className = 'selector-area-producto';
    const control = document.createElement('div');
    control.className = 'control-area-producto';
    const campo = document.createElement('input');
    campo.type = 'text';
    campo.id = 'buscar-area-producto';
    campo.placeholder = 'Busca o selecciona un área';
    campo.autocomplete = 'off';
    campo.required = select.required;
    campo.setAttribute('role', 'combobox');
    campo.setAttribute('aria-autocomplete', 'list');
    campo.setAttribute('aria-expanded', 'false');
    campo.setAttribute('aria-controls', 'opciones-area-producto');
    const boton = document.createElement('button');
    boton.type = 'button';
    boton.textContent = '▾';
    boton.setAttribute('aria-label', 'Mostrar áreas');
    boton.setAttribute('aria-controls', 'opciones-area-producto');
    const lista = document.createElement('ul');
    lista.id = 'opciones-area-producto';
    lista.setAttribute('role', 'listbox');
    lista.setAttribute('aria-label', 'Áreas disponibles');
    lista.hidden = true;
    const estado = document.createElement('p');
    estado.className = 'estado-area-producto';
    estado.setAttribute('role', 'status');
    control.append(campo, boton);
    contenedor.append(control, lista, estado);
    select.after(contenedor);
    select.labels[0]?.setAttribute('for', campo.id);
    select.hidden = true;
    select.required = false;

    let filtradas = [];
    let posicion = -1;
    const normalizar = (valor) => valor.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');

    function cerrar() {
        lista.hidden = true;
        campo.setAttribute('aria-expanded', 'false');
        campo.removeAttribute('aria-activedescendant');
        posicion = -1;
    }

    function sincronizar() {
        campo.value = opciones.find((opcion) => opcion.id === select.value)?.nombre ?? '';
        campo.setCustomValidity(select.value ? '' : 'Selecciona un área de la lista.');
    }

    function elegir(opcion) {
        select.value = opcion.id;
        sincronizar();
        cerrar();
        estado.textContent = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        campo.focus();
    }

    function mostrar(todas = false) {
        const termino = normalizar(campo.value.trim());
        filtradas = opciones.filter((opcion) => todas || normalizar(opcion.nombre).includes(termino));
        lista.replaceChildren();
        posicion = -1;
        campo.removeAttribute('aria-activedescendant');
        filtradas.forEach((opcion, indice) => {
            const elemento = document.createElement('li');
            elemento.id = `area-producto-${indice}`;
            elemento.setAttribute('role', 'option');
            elemento.setAttribute('aria-selected', String(opcion.id === select.value));
            elemento.textContent = opcion.nombre;
            elemento.addEventListener('mousedown', (evento) => evento.preventDefault());
            elemento.addEventListener('click', () => elegir(opcion));
            lista.append(elemento);
        });
        lista.hidden = filtradas.length === 0;
        campo.setAttribute('aria-expanded', String(!lista.hidden));
        estado.textContent = filtradas.length ? '' : 'No se encontraron áreas.';
    }

    campo.addEventListener('input', () => {
        select.value = '';
        campo.setCustomValidity('Selecciona un área de la lista.');
        mostrar();
    });
    campo.addEventListener('click', () => mostrar(Boolean(select.value)));
    boton.addEventListener('click', () => {
        campo.focus();
        if (lista.hidden) mostrar(true);
        else cerrar();
    });
    campo.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape' || evento.key === 'Tab') {
            if (evento.key === 'Escape' && !lista.hidden) evento.preventDefault();
            cerrar();
        } else if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
            evento.preventDefault();
            if (lista.hidden) mostrar(Boolean(select.value));
            if (!filtradas.length) return;
            posicion = evento.key === 'ArrowDown'
                ? (posicion + 1) % filtradas.length
                : (posicion <= 0 ? filtradas.length : posicion) - 1;
            Array.from(lista.children).forEach((elemento, indice) => {
                elemento.classList.toggle('activa', indice === posicion);
            });
            const activa = lista.children[posicion];
            campo.setAttribute('aria-activedescendant', activa.id);
            activa.scrollIntoView({ block: 'nearest' });
        } else if (evento.key === 'Enter' && !lista.hidden) {
            evento.preventDefault();
            if (posicion >= 0) elegir(filtradas[posicion]);
        }
    });
    document.addEventListener('click', (evento) => {
        if (!contenedor.contains(evento.target)) cerrar();
    });
    select.addEventListener('change', sincronizar);
    select.form.addEventListener('reset', () => {
        queueMicrotask(() => { sincronizar(); cerrar(); estado.textContent = ''; });
    });
    sincronizar();
})();
