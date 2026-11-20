(() => {
    const select = document.getElementById('id_atributo');
    if (!select) return;

    const normalizar = (texto) => texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const opciones = Array.from(select.options)
        .filter((opcion) => opcion.value)
        .map((opcion) => ({ valor: opcion.value, texto: opcion.textContent.trim() }));
    const contenedor = document.createElement('div');
    contenedor.className = 'selector-atributos';
    contenedor.innerHTML = `
        <input type="text" id="buscar-atributo" role="combobox" aria-autocomplete="list"
            aria-expanded="false" aria-controls="opciones-atributo" autocomplete="off"
            placeholder="Buscar atributo" required>
        <div class="resultados-atributos" hidden>
            <ul id="opciones-atributo" role="listbox" aria-label="Atributos disponibles"></ul>
            <p role="status" hidden>No hay atributos que coincidan.</p>
        </div>`;
    select.before(contenedor);
    const campo = contenedor.querySelector('input');
    const desplegable = contenedor.querySelector('.resultados-atributos');
    const lista = contenedor.querySelector('ul');
    const vacio = contenedor.querySelector('p');
    let filtrados = [];
    let posicion = -1;
    campo.value = opciones.find((opcion) => opcion.valor === select.value)?.texto ?? '';
    select.hidden = true;
    select.required = false;
    document.querySelector('label[for="id_atributo"]').htmlFor = campo.id;

    function cerrar() {
        desplegable.hidden = true;
        campo.setAttribute('aria-expanded', 'false');
        campo.removeAttribute('aria-activedescendant');
        posicion = -1;
    }

    function seleccionar(opcion) {
        select.value = opcion.valor;
        campo.value = opcion.texto;
        campo.setCustomValidity('');
        select.dispatchEvent(new Event('change', { bubbles: true }));
        cerrar();
        campo.focus();
    }

    function abrir() {
        const busqueda = select.value ? '' : normalizar(campo.value.trim());
        filtrados = opciones.filter((opcion) => normalizar(opcion.texto).includes(busqueda));
        lista.replaceChildren();
        posicion = -1;
        campo.removeAttribute('aria-activedescendant');
        for (const opcion of filtrados) {
            const elemento = document.createElement('li');
            elemento.id = `atributo-opcion-${opcion.valor}`;
            elemento.setAttribute('role', 'option');
            elemento.setAttribute('aria-selected', String(select.value === opcion.valor));
            elemento.textContent = opcion.texto;
            elemento.addEventListener('mousedown', (evento) => evento.preventDefault());
            elemento.addEventListener('click', () => seleccionar(opcion));
            lista.append(elemento);
        }
        vacio.hidden = filtrados.length > 0;
        desplegable.hidden = false;
        campo.setAttribute('aria-expanded', 'true');
    }

    campo.addEventListener('click', abrir);
    campo.addEventListener('input', () => {
        select.value = '';
        campo.setCustomValidity('Selecciona un atributo de la lista.');
        abrir();
    });
    campo.addEventListener('blur', cerrar);
    campo.addEventListener('keydown', (evento) => {
        if (evento.isComposing) return;
        if (evento.key === 'Escape') {
            evento.preventDefault();
            cerrar();
        }
        if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
            evento.preventDefault();
            if (desplegable.hidden) abrir();
            if (!filtrados.length) return;
            const paso = evento.key === 'ArrowDown' ? 1 : -1;
            posicion = posicion === -1
                ? (paso === 1 ? 0 : filtrados.length - 1)
                : (posicion + paso + filtrados.length) % filtrados.length;
            Array.from(lista.children).forEach((elemento, indice) => {
                elemento.classList.toggle('opcion-activa', indice === posicion);
            });
            const activa = lista.children[posicion];
            campo.setAttribute('aria-activedescendant', activa.id);
            activa.scrollIntoView({ block: 'nearest' });
        }
        if (evento.key === 'Enter' && !desplegable.hidden) {
            evento.preventDefault();
            if (posicion >= 0) seleccionar(filtrados[posicion]);
            else if (filtrados.length === 1) seleccionar(filtrados[0]);
        }
    });
    select.form.addEventListener('reset', () => {
        setTimeout(() => {
            campo.value = opciones.find((opcion) => opcion.valor === select.value)?.texto ?? '';
            campo.setCustomValidity('');
            cerrar();
        }, 0);
    });
})();
