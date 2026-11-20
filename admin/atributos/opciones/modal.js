(() => {
    const modal = document.getElementById('modal-opciones');
    if (!modal || typeof modal.showModal !== 'function') return;

    const contenido = document.getElementById('contenido-opciones');
    const titulo = document.getElementById('titulo-opciones');
    const estado = document.getElementById('estado-opciones');
    const cerrar = document.getElementById('cerrar-opciones');
    const ruta = '/sistemaInventario/administracion/atributos/opciones';
    let ocupado = false;
    let origen = null;

    function esOpciones(url) {
        return url.origin === location.origin && url.pathname.replace(/\/$/, '') === ruta;
    }

    document.querySelectorAll('a[href]').forEach((enlace) => {
        if (esOpciones(new URL(enlace.href))) {
            enlace.setAttribute('aria-haspopup', 'dialog');
            enlace.setAttribute('aria-controls', modal.id);
        }
    });

    async function cargar(url, formulario = null) {
        if (ocupado) return;
        ocupado = true;
        cerrar.disabled = true;
        contenido.inert = true;
        contenido.setAttribute('aria-busy', 'true');
        estado.hidden = false;
        estado.textContent = formulario ? 'Guardando cambios…' : 'Cargando opciones…';
        try {
            const respuesta = await fetch(url, formulario
                ? { method: 'POST', body: new FormData(formulario) }
                : { cache: 'no-store' });
            const documento = new DOMParser().parseFromString(await respuesta.text(), 'text/html');
            const principal = documento.querySelector('main.contenido-crud');
            if (!respuesta.ok || !esOpciones(new URL(respuesta.url)) || !principal) {
                throw new Error('No se pudieron cargar las opciones. Comprueba tu sesión y vuelve a abrir la ventana.');
            }
            titulo.textContent = documento.querySelector('h1')?.textContent ?? 'Opciones del atributo';
            // Evita colisiones con los campos del formulario de atributos que sigue abierto detrás.
            principal.querySelectorAll('[id]').forEach((elemento) => {
                elemento.id = `modal-${elemento.id}`;
            });
            principal.querySelectorAll('[for], [aria-controls]').forEach((elemento) => {
                for (const atributo of ['for', 'aria-controls']) {
                    if (elemento.hasAttribute(atributo)) {
                        elemento.setAttribute(atributo, `modal-${elemento.getAttribute(atributo)}`);
                    }
                }
            });
            contenido.replaceChildren(principal);
            estado.hidden = true;
        } catch (error) {
            estado.textContent = formulario
                ? 'No se pudo confirmar el resultado. Cierra y vuelve a abrir las opciones para revisar si se guardó el cambio antes de intentarlo otra vez.'
                : error.message;
        } finally {
            ocupado = false;
            cerrar.disabled = false;
            contenido.inert = false;
            contenido.removeAttribute('aria-busy');
            const campo = contenido.querySelector('#modal-formulario-desplegable:not([hidden]) input:not([type="hidden"])');
            (campo ?? cerrar).focus();
        }
    }

    document.addEventListener('click', (evento) => {
        const enlace = evento.target.closest('a[href]');
        if (!enlace || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey || evento.button !== 0) return;
        const url = new URL(enlace.href);
        if (!esOpciones(url)) return;
        evento.preventDefault();
        if (ocupado) return;
        if (!modal.open) {
            origen = enlace;
            contenido.replaceChildren();
            titulo.textContent = 'Opciones del atributo';
            modal.showModal();
        }
        cargar(url);
    });

    // Se registra después de confirmaciones.js para respetar la cancelación del usuario.
    document.addEventListener('submit', (evento) => {
        const formulario = evento.target;
        if (!contenido.contains(formulario) || evento.defaultPrevented) return;
        evento.preventDefault();
        if (!ocupado) cargar(formulario.action, formulario);
    });

    cerrar.addEventListener('click', () => modal.close());
    modal.addEventListener('cancel', (evento) => {
        if (ocupado) evento.preventDefault();
    });
    modal.addEventListener('close', () => origen?.focus());
})();
