(() => {
    const modal = document.getElementById('modal-baja');
    if (!modal || typeof modal.showModal !== 'function') return;
    const contenido = document.getElementById('contenido-modal-baja');
    const estado = document.getElementById('estado-modal-baja');
    const cerrar = document.getElementById('cerrar-modal-baja');
    let ocupado = false;
    let origen;
    const rutaBajas = '/sistemaInventario/administracion/bajas';
    const rutaProductos = '/sistemaInventario/administracion/productos';

    function comprobarRuta(valor, ruta) {
        const url = new URL(valor, location.origin);
        if (url.origin !== location.origin || url.pathname.replace(/\/$/, '') !== ruta) {
            throw new Error('Comprueba tu sesión y vuelve a abrir la ventana.');
        }
        return url;
    }
    async function mostrarFormulario(url) {
        const respuesta = await fetch(comprobarRuta(url, rutaBajas), { cache: 'no-store' });
        comprobarRuta(respuesta.url, rutaBajas);
        if (!respuesta.ok) throw new Error('No fue posible cargar los datos del equipo.');
        const documento = new DOMParser().parseFromString(await respuesta.text(), 'text/html');
        const principal = documento.querySelector('main.contenido-crud');
        if (!principal) throw new Error('No fue posible cargar el formulario.');
        contenido.replaceChildren(principal);
        estado.hidden = true;
    }
    async function ejecutar(url, formulario = null) {
        if (ocupado) return;
        ocupado = true;
        cerrar.disabled = true;
        contenido.inert = true;
        contenido.setAttribute('aria-busy', 'true');
        estado.hidden = false;
        estado.textContent = formulario ? 'Registrando baja…' : 'Cargando equipo…';
        try {
            if (formulario) {
                const datos = new FormData(formulario);
                datos.set('modal_baja', '1');
                datos.set('volver', location.search.slice(1));
                const respuesta = await fetch(formulario.action, {
                    method: 'POST', body: datos, headers: { Accept: 'application/json' },
                });
                if (!(respuesta.headers.get('Content-Type') || '').includes('application/json')) {
                    throw new Error('No se pudo confirmar la baja. Comprueba tu sesión.');
                }
                const resultado = await respuesta.json();
                if (respuesta.ok && resultado.exito === true) {
                    location.assign(comprobarRuta(resultado.url, rutaProductos).href);
                    return;
                }
                if (resultado.error && resultado.url) await mostrarFormulario(resultado.url);
                else throw new Error(resultado.mensaje || 'No fue posible registrar la baja.');
            } else await mostrarFormulario(url);
        } catch (error) {
            estado.hidden = false;
            estado.textContent = formulario
                ? `${error.message} Revisa el estado del equipo antes de volver a confirmar.`
                : error.message;
        } finally {
            ocupado = false;
            cerrar.disabled = false;
            contenido.inert = false;
            contenido.removeAttribute('aria-busy');
            (contenido.querySelector('[role="alert"]') || contenido.querySelector('input:not([type="hidden"])') || cerrar).focus();
            const alerta = contenido.querySelector('[role="alert"]');
            if (alerta) { alerta.tabIndex = -1; alerta.focus(); }
        }
    }
    document.querySelectorAll('[data-modal-baja]').forEach((enlace) => {
        enlace.setAttribute('aria-haspopup', 'dialog');
        enlace.setAttribute('aria-controls', modal.id);
        enlace.addEventListener('click', (evento) => {
            if (evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey || evento.button !== 0) return;
            evento.preventDefault();
            if (ocupado) return;
            origen = enlace;
            contenido.replaceChildren();
            document.getElementById('titulo-modal-baja').textContent = enlace.textContent.trim() === 'Ver baja' ? 'Detalle de la baja' : 'Dar de baja equipo';
            modal.showModal();
            ejecutar(enlace.href);
        });
    });
    contenido.addEventListener('click', (evento) => {
        if (evento.target.closest('[data-cerrar-baja]')) { evento.preventDefault(); if (!ocupado) modal.close(); }
    });
    // El listener de confirmaciones.js se ejecuta primero en document.
    document.addEventListener('submit', (evento) => {
        if (!contenido.contains(evento.target) || evento.defaultPrevented) return;
        evento.preventDefault();
        ejecutar(null, evento.target);
    });
    cerrar.addEventListener('click', () => modal.close());
    modal.addEventListener('cancel', (evento) => { if (ocupado) evento.preventDefault(); });
    modal.addEventListener('close', () => origen?.focus());
})();
