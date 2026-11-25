(() => {
    const vista = document.getElementById('vista-foto-usuario');
    if (!vista) return;
    const imagen = vista.querySelector('img');
    const mensaje = vista.querySelector('.usuario-foto-mensaje');
    let activa = null;
    let sobre = null;

    const cerrar = () => {
        activa = null;
        vista.hidden = true;
    };

    const mostrar = (celda) => {
        if (activa === celda) return;
        activa = celda;
        const nombre = celda.dataset.nombreUsuario;
        vista.querySelector('strong').textContent = nombre;
        imagen.hidden = true;
        imagen.alt = `Foto de ${nombre}`;
        mensaje.hidden = false;
        mensaje.textContent = 'Cargando foto…';
        imagen.onload = () => {
            imagen.hidden = false;
            mensaje.hidden = true;
        };
        imagen.onerror = () => {
            imagen.hidden = true;
            mensaje.hidden = false;
            mensaje.textContent = 'Foto no disponible';
        };
        imagen.src = `/sistemaInventario/admin/usuarios/imagen.php?usuario=${encodeURIComponent(celda.dataset.fotoUsuario)}`;
        vista.hidden = false;
        const caja = celda.getBoundingClientRect();
        const ancho = vista.offsetWidth;
        const alto = vista.offsetHeight;
        const izquierda = Math.max(8, Math.min(caja.left, window.innerWidth - ancho - 8));
        const arriba = caja.top >= alto + 8 ? caja.top - alto - 8 : caja.bottom + 8;
        vista.style.left = `${izquierda}px`;
        vista.style.top = `${Math.max(8, Math.min(arriba, window.innerHeight - alto - 8))}px`;
    };

    function iniciarFotos() {
    cerrar();
    document.querySelectorAll('[data-foto-usuario]').forEach((celda) => {
        if (celda.dataset.fotoIniciada) return;
        celda.dataset.fotoIniciada = '1';
        celda.addEventListener('pointerenter', () => {
            sobre = celda;
            mostrar(celda);
        });
        celda.addEventListener('pointerleave', () => {
            sobre = null;
            if (document.activeElement !== celda) cerrar();
        });
        celda.addEventListener('focus', () => mostrar(celda));
        celda.addEventListener('blur', () => {
            if (sobre !== celda) cerrar();
        });
    });
    }
    iniciarFotos();
    document.addEventListener('paginacion:actualizada', iniciarFotos);
    document.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape') cerrar();
    });
    document.addEventListener('scroll', cerrar, true);
    window.addEventListener('resize', cerrar);
})();
