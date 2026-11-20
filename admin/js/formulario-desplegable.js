(() => {
    const panel = document.getElementById('formulario-desplegable');
    const boton = document.getElementById('abrir-desplegable');
    const cancelar = document.getElementById('cancelar-desplegable');
    if (!panel || !boton) return;

    const cambiarVisibilidad = (visible) => {
        panel.hidden = !visible;
        boton.setAttribute('aria-expanded', String(visible));
        const icono = document.createElement('span');
        icono.setAttribute('aria-hidden', 'true');
        icono.textContent = visible ? '×' : '+';
        boton.replaceChildren(
            icono,
            document.createTextNode(' ' + (visible ? 'Cerrar' : boton.dataset.etiqueta)),
        );
        const separador = boton.dataset.listado.includes('?') ? '&' : '?';
        boton.href = boton.dataset.listado + (visible ? '' : separador + 'crear=1');
        if (visible) {
            panel
                .querySelector(
                    'input:not([type="hidden"]):not(:disabled), select:not(:disabled), a[href]',
                )
                ?.focus();
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            boton.focus();
        }
    };

    boton.addEventListener('click', (evento) => {
        if (boton.dataset.editando === 'true') return;
        evento.preventDefault();
        cambiarVisibilidad(panel.hidden);
    });
    cancelar?.addEventListener('click', (evento) => {
        if (boton.dataset.editando === 'true') return;
        evento.preventDefault();
        cambiarVisibilidad(false);
    });
})();
