const modalAuditoria = document.getElementById("modal-auditoria");
const detalleAuditoria = document.getElementById("detalle-auditoria");
const tituloAuditoria = document.getElementById("titulo-modal-auditoria");

document.addEventListener('click', (evento) => {
    const boton = evento.target.closest('[data-ver-cambios]');
    if (!boton) return;
    (() => {

        const plantilla = boton.parentElement.querySelector(
            ".contenido-cambios"
        );

        tituloAuditoria.textContent = boton.dataset.titulo
            ? `Detalle · ${boton.dataset.titulo}`
            : `Detalle del evento #${boton.dataset.evento}`;
        detalleAuditoria.replaceChildren(
            plantilla.content.cloneNode(true)
        );

        modalAuditoria.showModal();
    })();
});

modalAuditoria.addEventListener("close", () => {
    detalleAuditoria.replaceChildren();
});
