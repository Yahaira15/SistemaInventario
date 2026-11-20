(() => {
    const categoria = document.getElementById('categoria');
    const contenedor = document.getElementById('campos-atributos');
    const configuracion = JSON.parse(document.getElementById('config-productos').textContent);
    const producto = configuracion.producto;
    const categoriaRecuperada = configuracion.categoria;
    const atributosRecuperados = configuracion.atributos;
    const unidadesRecuperadas = configuracion.unidades ?? {};
    if (!categoria || !contenedor) return;
    const formulario = categoria.form;
    const guardar = document.getElementById('guardar-producto');
    const reintentar = document.getElementById('reintentar-atributos');
    let categoriaCargada = null;
    let solicitudActual = 0;
    let controlador = null;
    let enviando = false;
    const textoGuardar = guardar.textContent;

    window.addEventListener('pageshow', () => {
        enviando = false;
        guardar.textContent = textoGuardar;
        guardar.disabled = !categoriaCargada || categoriaCargada !== categoria.value;
    });

    formulario.addEventListener('submit', (evento) => {
        if (enviando) {
            evento.preventDefault();
            return;
        }
        if (!categoriaCargada || categoriaCargada !== categoria.value) {
            evento.preventDefault();
        }
        // Espera a que el selector de códigos también valide el envío.
        queueMicrotask(() => {
            if (evento.defaultPrevented) return;
            enviando = true;
            guardar.disabled = true;
            guardar.textContent = 'Guardando…';
        });
    });

    const escapar = (valor) =>
        String(valor ?? '').replace(
            /[&<>"']/g,
            (caracter) =>
                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[
                    caracter
                ],
        );
    const valorActual = (a) =>
        a.tipo_dato === 'TEXTO'
            ? a.valor_texto
            : a.tipo_dato === 'NUMERO'
              ? a.valor_numero
              : a.tipo_dato === 'FECHA'
                ? a.valor_fecha
                : a.tipo_dato === 'BOOLEANO'
                  ? a.valor_booleano
                  : a.id_atributo_opcion;
    const campo = (a) => {
        const nombre = `atributos[${a.id_categoria_atributo}]`;
        const id = `atributo-${a.id_categoria_atributo}`;
        const requerido = Number(a.requerido) === 1 ? ' required' : '';
        const valor =
            Number(categoria.value) === categoriaRecuperada &&
            Object.prototype.hasOwnProperty.call(atributosRecuperados, a.id_categoria_atributo)
                ? atributosRecuperados[a.id_categoria_atributo]
                : (valorActual(a) ?? '');
        let control;

        if (a.tipo_dato === 'SELECCION') {
            control = `<select id="${id}" name="${nombre}"${requerido}><option value="">Selecciona una opción</option>${a.opciones.map((o) => `<option value="${o.id_atributo_opcion}" ${String(o.id_atributo_opcion) === String(valor) ? 'selected' : ''}>${escapar(o.valor)}${o.estado === 'INACTIVO' ? ' (Inactiva; valor conservado)' : ''}</option>`).join('')}</select>`;
        } else if (a.tipo_dato === 'BOOLEANO') {
            control = `<select id="${id}" name="${nombre}"${requerido}><option value="">Selecciona</option><option value="1" ${String(valor) === '1' ? 'selected' : ''}>Sí</option><option value="0" ${String(valor) === '0' ? 'selected' : ''}>No</option></select>`;
        } else {
            const tipo =
                a.tipo_dato === 'NUMERO' ? 'number' : a.tipo_dato === 'FECHA' ? 'date' : 'text';
            let extras = '';

            if (tipo === 'number') {
                const decimales = Number(a.decimales) || 0;
                const paso = decimales > 0 ? '0.' + '0'.repeat(decimales - 1) + '1' : '1';

                extras = ` step="${paso}"`;

                if (a.valor_minimo !== null) {
                    extras += ` min="${escapar(a.valor_minimo)}"`;
                }

                if (a.valor_maximo !== null) {
                    extras += ` max="${escapar(a.valor_maximo)}"`;
                }
            } else if (tipo === 'text') {
                const longitud = Math.min(255, Math.max(1, Number(a.longitud_maxima) || 255));
                extras = ` maxlength="${longitud}"`;
            }
            control = `<input type="${tipo}" id="${id}" name="${nombre}" value="${escapar(valor)}"${extras}${requerido}>`;
        }

        if (a.tipo_dato === 'NUMERO' && a.unidades.length) {
            const recuperada =
                Number(categoria.value) === categoriaRecuperada &&
                Object.prototype.hasOwnProperty.call(unidadesRecuperadas, a.id_categoria_atributo);
            let unidad = recuperada
                ? unidadesRecuperadas[a.id_categoria_atributo]
                : a.id_atributo_unidad;
            if (!recuperada && !unidad)
                unidad = a.unidades.find((u) => u.estado === 'ACTIVO')?.id_atributo_unidad ?? '';
            const elegida = a.unidades.find((u) => String(u.id_atributo_unidad) === String(unidad));
            const textoUnidad = elegida
                ? `${elegida.simbolo}${elegida.estado === 'INACTIVO' ? ' · Inactiva' : ''}`
                : 'Unidad';
            return `<div class="campo-formulario"><div class="encabezado-atributo"><label for="${id}">${escapar(a.nombre)}${Number(a.requerido) === 1 ? ' *' : ''}</label><span class="unidad-interactiva"><span class="unidad-texto" aria-hidden="true">(${escapar(textoUnidad)})</span><select class="selector-unidad-texto" id="${id}-unidad" name="unidades_producto[${a.id_categoria_atributo}]" aria-label="Unidad de ${escapar(a.nombre)}" data-unidad-de="${id}" ${String(valor) !== '' || Number(a.requerido) === 1 ? 'required' : ''}><option value="">Elegir unidad</option>${a.unidades.map((u) => `<option value="${u.id_atributo_unidad}" ${String(u.id_atributo_unidad) === String(unidad) ? 'selected' : ''}>${escapar(u.simbolo)}${u.estado === 'INACTIVO' ? ' · Inactiva' : ''}</option>`).join('')}</select></span></div>${control}</div>`;
        }
        return `<div class="campo-formulario"><label for="${id}">${escapar(a.nombre)}${Number(a.requerido) === 1 ? ' *' : ''}</label>${control}</div>`;
    };
    const cargar = async () => {
        const solicitud = ++solicitudActual;
        const seleccion = categoria.value;
        if (controlador) controlador.abort();
        controlador = new AbortController();
        categoriaCargada = null;
        guardar.disabled = true;
        reintentar.hidden = true;
        contenedor.setAttribute('aria-busy', 'false');
        if (!seleccion) {
            contenedor.innerHTML =
                '<p class="ayuda-atributos">Selecciona una categoría para cargar sus atributos.</p>';
            return;
        }
        contenedor.setAttribute('aria-busy', 'true');
        contenedor.innerHTML = '<p class="ayuda-atributos">Cargando atributos…</p>';
        try {
            const respuesta = await fetch(
                `/sistemaInventario/administracion/productos/atributos?categoria=${encodeURIComponent(seleccion)}&producto=${producto}`,
                { signal: controlador.signal },
            );
            if (!respuesta.ok) throw new Error();
            const datos = await respuesta.json();
            if (solicitud !== solicitudActual || seleccion !== categoria.value) return;
            if (!Array.isArray(datos.atributos)) throw new Error();
            contenedor.innerHTML = datos.atributos.length
                ? datos.atributos.map(campo).join('')
                : '<p class="ayuda-atributos">Esta categoría no tiene atributos activos.</p>';
            categoriaCargada = seleccion;
            guardar.disabled = false;
        } catch {
            if (solicitud !== solicitudActual || seleccion !== categoria.value) return;
            contenedor.innerHTML =
                '<p class="ayuda-atributos error-atributos">No fue posible cargar los atributos. Reintenta la carga antes de guardar.</p>';
            reintentar.hidden = false;
        } finally {
            if (solicitud === solicitudActual) contenedor.setAttribute('aria-busy', 'false');
        }
    };
    contenedor.addEventListener('input', (evento) => {
        const selector = contenedor.querySelector(`[data-unidad-de="${evento.target.id}"]`);
        if (selector) selector.required = evento.target.required || evento.target.value !== '';
    });
    contenedor.addEventListener('change', (evento) => {
        const selector = evento.target;
        if (!selector.matches('.selector-unidad-texto')) return;
        const texto = selector.value ? selector.selectedOptions[0].textContent : 'Unidad';
        selector.parentElement.querySelector('.unidad-texto').textContent = `(${texto})`;
    });
    categoria.addEventListener('change', cargar);
    reintentar.addEventListener('click', cargar);
    if (categoria.value) cargar();
})();
