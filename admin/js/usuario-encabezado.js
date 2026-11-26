document.querySelectorAll('.usuario-encabezado').forEach((desplegable) => {
    const imagen = desplegable.querySelector('.usuario-avatar-imagen');
    if (imagen) {
        imagen.addEventListener('error', () => { imagen.hidden = true; });
        if (imagen.complete && imagen.naturalWidth === 0) imagen.hidden = true;
    }
    document.addEventListener('click', (evento) => {
        if (!desplegable.contains(evento.target)) desplegable.open = false;
    });

    document.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape' && desplegable.open) {
            desplegable.open = false;
            desplegable.querySelector('summary').focus();
        }
    });
});
