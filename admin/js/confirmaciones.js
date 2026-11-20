// Las vistas declaran el mensaje en data-confirmar; la lógica se comparte aquí.
document.addEventListener('submit', (evento) => {
    const formulario = evento.target;
    const mensaje = formulario.dataset.confirmar;
    if (mensaje && !window.confirm(mensaje)) evento.preventDefault();
});
