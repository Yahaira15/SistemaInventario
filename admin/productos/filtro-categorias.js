(() => {
    const filtro = document.querySelector('[data-filtro-categorias]');
    if (!filtro) return;
    const formulario = filtro.closest('form');
    const casillas = [...filtro.querySelectorAll('input[type="checkbox"]')];
    const desplegable = filtro.querySelector('details');
    const resumen = desplegable.querySelector('summary');
    const actualizar = () => {
        const cantidad = casillas.filter(casilla => casilla.checked).length;
        resumen.textContent = cantidad ? `${cantidad} seleccionadas` : 'Todas las categorías';
    };
    document.addEventListener('click', evento => {
        if (!filtro.contains(evento.target)) desplegable.open = false;
    });
    filtro.addEventListener('keydown', evento => {
        if (evento.key === 'Escape' && desplegable.open) {
            desplegable.open = false;
            resumen.focus();
        }
    });
    filtro.addEventListener('change', evento => {
        if (!casillas.includes(evento.target)) return;
        actualizar();
        formulario.requestSubmit();
    });
    window.addEventListener('pageshow', actualizar);
    actualizar();
})();