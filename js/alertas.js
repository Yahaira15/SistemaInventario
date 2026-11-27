(() => {
    if (window.alertasTemporizadasIniciadas) return;
    window.alertasTemporizadasIniciadas = true;
    const programadas = new WeakSet();
    const programarAlertas = () => {
        document.querySelectorAll('.alerta, .alert, .mensaje').forEach((alerta) => {
            // Los avisos de una ventana cerrada comienzan a contar al mostrarse.
            if (programadas.has(alerta) || alerta.getClientRects().length === 0) return;
            programadas.add(alerta);
            window.setTimeout(() => alerta.remove(), 15000);
        });
    };
    programarAlertas();
    new MutationObserver(programarAlertas).observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['hidden', 'open', 'class', 'style'],
    });
})();
