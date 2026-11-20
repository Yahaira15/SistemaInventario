(() => {
    'use strict';
    const svg = document.querySelector('.dashboard-pastel[data-segmentos]');
    if (!svg) return;
    const datos = JSON.parse(svg.dataset.segmentos);
    const colores = JSON.parse(svg.dataset.colores);
    const ocultos = new Set();
    const panel = svg.closest('section');
    const estado = document.createElement('p');
    estado.className = 'dashboard-nota';
    estado.setAttribute('role', 'status');
    panel.append(estado);
    const crear = (tipo, atributos, texto) => {
        const nodo = document.createElementNS('http://www.w3.org/2000/svg', tipo);
        Object.entries(atributos).forEach(([clave, valor]) => nodo.setAttribute(clave, valor));
        if (texto !== undefined) nodo.textContent = texto;
        return nodo;
    };
    function dibujar() {
        svg.querySelectorAll('.dashboard-sector, circle').forEach(nodo => nodo.remove());
        const total = datos.reduce((suma, dato, indice) => suma + (ocultos.has(indice) ? 0 : Number(dato.total)), 0);
        let angulo = -Math.PI / 2;
        datos.forEach((dato, indice) => {
            if (ocultos.has(indice) || !total) return;
            const cantidad = Number(dato.total);
            const porcion = cantidad / total;
            const fin = angulo + porcion * Math.PI * 2;
            const grupo = crear('g', {class: 'dashboard-sector'});
            grupo.append(crear('title', {}, `${dato.nombre}: ${cantidad} (${(porcion * 100).toFixed(1)}%)`));
            if (porcion === 1) {
                grupo.append(crear('circle', {cx: 160, cy: 160, r: 150, fill: colores[indice]}));
            } else {
                const inicioX = 160 + 150 * Math.cos(angulo);
                const inicioY = 160 + 150 * Math.sin(angulo);
                const finX = 160 + 150 * Math.cos(fin);
                const finY = 160 + 150 * Math.sin(fin);
                grupo.append(crear('path', {d: `M160 160 L${inicioX} ${inicioY} A150 150 0 ${porcion > .5 ? 1 : 0} 1 ${finX} ${finY} Z`, fill: colores[indice], stroke: '#fff', 'stroke-width': 1.5}));
            }
            if (porcion >= .05) {
                const medio = (angulo + fin) / 2;
                const x = 160 + 94 * Math.cos(medio);
                const y = 160 + 94 * Math.sin(medio) - 6;
                const texto = crear('text', {x, y, 'text-anchor': 'middle', 'dominant-baseline': 'middle', class: 'dashboard-porcentaje'});
                texto.append(crear('tspan', {x}, `${Number((porcion * 100).toFixed(1))}%`));
                texto.append(crear('tspan', {x, dy: 16, class: 'dashboard-cantidad-segmento'}, cantidad.toLocaleString('es-CO')));
                grupo.append(texto);
            }
            svg.append(grupo);
            angulo = fin;
        });
        if (total) svg.append(crear('circle', {cx: 160, cy: 160, r: 7, fill: '#fff'}));
        else svg.append(crear('circle', {cx: 160, cy: 160, r: 150, fill: '#f1f2f8'}));
        estado.textContent = total ? `${total.toLocaleString('es-CO')} productos visibles` : 'Todas las categorías están ocultas. Pulsa un nombre para mostrarlo.';
    }
    panel.querySelectorAll('[data-segmento]').forEach(boton => {
        boton.addEventListener('click', () => {
            const indice = Number(boton.dataset.segmento);
            if (ocultos.has(indice)) ocultos.delete(indice);
            else ocultos.add(indice);
            boton.setAttribute('aria-pressed', String(!ocultos.has(indice)));
            dibujar();
        });
    });
})();
