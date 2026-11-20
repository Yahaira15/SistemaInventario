document.querySelectorAll('.usuario-encabezado').forEach((desplegable) => {
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
