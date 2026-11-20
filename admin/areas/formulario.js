(() => {
    const formulario = document.getElementById('formulario-area');
    const botonCrear = document.getElementById('boton-crear-area');
    const botonCancelar = document.getElementById('boton-cancelar-area');
    const campoNombre = document.getElementById('nombre-area');
    const configuracion = JSON.parse(document.getElementById('config-areas').textContent);
    const estaEditando = configuracion.estaEditando;

    const mostrarFormulario = () => {
        formulario.hidden = false;
        botonCrear.setAttribute('aria-expanded', 'true');
        botonCrear.innerHTML = '<span aria-hidden="true">×</span> Cerrar';
        campoNombre.focus();
        formulario.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    const ocultarFormulario = () => {
        formulario.hidden = true;
        botonCrear.setAttribute('aria-expanded', 'false');
        botonCrear.innerHTML = '<span aria-hidden="true">+</span> Crear';
        botonCrear.focus();
    };

    botonCrear.addEventListener('click', (evento) => {
        evento.preventDefault();

        if (estaEditando) {
            window.location.href = '/sistemaInventario/administracion/areas';
            return;
        }

        formulario.hidden ? mostrarFormulario() : ocultarFormulario();
    });
    if (botonCancelar) {
        botonCancelar.addEventListener('click', (evento) => {
            evento.preventDefault();
            ocultarFormulario();
        });
    }
})();
