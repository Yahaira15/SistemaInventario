(() => {
    'use strict';
    let controlador;
    let urlActual = location.href;
    const selector = '.paginacion:not([data-paginacion-historial]), .dashboard-alerta-paginacion';

    async function cargar(url, historial = false) {
        controlador?.abort();
        const solicitud = new AbortController();
        controlador = solicitud;
        const principal = document.querySelector('main');
        const x = window.scrollX;
        const y = window.scrollY;
        principal?.setAttribute('aria-busy', 'true');
        try {
            const respuesta = await fetch(url, { signal: solicitud.signal, cache: 'no-store' });
            if (!respuesta.ok || new URL(respuesta.url).pathname !== new URL(url).pathname) {
                throw new Error('Respuesta inesperada');
            }
            const documento = new DOMParser().parseFromString(await respuesta.text(), 'text/html');
            // Se conservan formularios y filtros con sus eventos y valores actuales.
            const actuales = [...document.querySelectorAll('main .tabla-contenedor, #detalle-alertas')];
            const nuevos = [...documento.querySelectorAll('main .tabla-contenedor, #detalle-alertas')];
            if (!actuales.length || actuales.length !== nuevos.length) throw new Error('Listado inesperado');
            if (solicitud.signal.aborted) return;
            actuales.forEach((actual, indice) => {
                // Reemplazar también los controles de la tabla, incluso si desaparecen en la última página.
                const contenedor = actual.closest('.grupo-productos, .tabla-crud');
                const nuevoContenedor = nuevos[indice].closest('.grupo-productos, .tabla-crud');
                if (contenedor && nuevoContenedor) {
                    const nav = contenedor.querySelector(':scope > .paginacion');
                    const nuevoNav = nuevoContenedor.querySelector(':scope > .paginacion');
                    if (nav) nav.remove();
                    if (nuevoNav) contenedor.append(document.importNode(nuevoNav, true));
                }
                const nuevo = document.importNode(nuevos[indice], true);
                nuevo.style.minHeight = `${actual.getBoundingClientRect().height}px`;
                actual.replaceWith(nuevo);
            });
            const extras = document.querySelector('[data-dialogos-paginacion]');
            const nuevosExtras = documento.querySelector('[data-dialogos-paginacion]');
            if (extras && nuevosExtras) extras.replaceChildren(...document.importNode(nuevosExtras, true).childNodes);
            if (!historial) history.pushState(null, '', url);
            urlActual = location.href;
            document.dispatchEvent(new Event('paginacion:actualizada'));
            const activo = document.querySelector(`${selector.split(', ').join(' [aria-current="page"], ')} [aria-current="page"]`);
            activo?.focus({ preventScroll: true });
            window.scrollTo({ left: x, top: y, behavior: 'instant' });
        } catch (error) {
            if (error.name !== 'AbortError') location.assign(url);
        } finally {
            if (controlador === solicitud) principal?.removeAttribute('aria-busy');
        }
    }

    document.addEventListener('click', (evento) => {
        const enlace = evento.target.closest(`${selector.split(', ').join(' a, ')} a`);
        if (!enlace || evento.defaultPrevented || evento.button !== 0 || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey || enlace.target || enlace.hasAttribute('download')) return;
        const url = new URL(enlace.href);
        if (url.origin !== location.origin || url.pathname !== location.pathname) return;
        evento.preventDefault();
        if (enlace.hasAttribute('aria-current')) return;
        cargar(url.href);
    });
    window.addEventListener('popstate', () => {
        if (location.href !== urlActual) cargar(location.href, true);
    });
})();
