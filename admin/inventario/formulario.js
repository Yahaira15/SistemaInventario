function iniciarFilasInventario() {
'use strict';
document.querySelectorAll('form[data-devolucion]').forEach((formulario) => {
    formulario.addEventListener('submit', (evento) => {
        const mensaje = `¿Registrar la devolución de ${formulario.dataset.responsable} para ${formulario.dataset.equipo}?`;
        if (!window.confirm(mensaje)) evento.preventDefault();
    });
});

document.querySelectorAll('[data-buscador-usuarios]').forEach((formulario) => {
    const input = formulario.querySelector('.buscar-usuario');
    const idUsuario = formulario.querySelector('[name="id_usuario"]');
    const resultados = formulario.querySelector('.resultados-usuarios');
    const estado = formulario.querySelector('.estado-busqueda');
    const boton = formulario.querySelector('button[type="submit"]');
    let temporizador;
    let controlador;
    let version = 0;

    if (idUsuario.value) {
        estado.textContent = 'Selección recuperada. Puedes reintentar o elegir otro responsable.';
    }

    function cerrarResultados() {
        resultados.replaceChildren();
        resultados.hidden = true;
    }

    input.addEventListener('input', () => {
        clearTimeout(temporizador);
        controlador?.abort();
        const versionActual = ++version;
        const texto = input.value.trim();
        idUsuario.value = '';
        boton.disabled = true;
        cerrarResultados();
        if (texto.length < 2) {
            estado.textContent = 'Escribe al menos 2 caracteres.';
            return;
        }
        estado.textContent = 'Buscando…';
        temporizador = setTimeout(async () => {
            controlador = new AbortController();
            const parametros = new URLSearchParams({
                buscar: texto,
                producto: formulario.dataset.producto,
            });
            try {
                const respuesta = await fetch(
                    `/sistemaInventario/admin/inventario/modal/buscar_usuarios.php?${parametros}`,
                    { signal: controlador.signal },
                );
                if (!respuesta.ok) throw new Error('La búsqueda falló.');
                const usuarios = await respuesta.json();
                if (versionActual !== version) return;
                if (!Array.isArray(usuarios)) throw new Error('Respuesta inválida.');
                cerrarResultados();
                usuarios.forEach((usuario) => {
                    const opcion = document.createElement('button');
                    opcion.type = 'button';
                    opcion.className = 'resultado-usuario';
                    opcion.textContent = `${usuario.nombre} (${usuario.cedula})`;
                    opcion.addEventListener('click', () => {
                        input.value = usuario.nombre;
                        idUsuario.value = usuario.id_usuario;
                        boton.disabled = false;
                        estado.textContent = 'Responsable seleccionado.';
                        cerrarResultados();
                        boton.focus();
                    });
                    resultados.appendChild(opcion);
                });
                resultados.hidden = usuarios.length === 0;
                estado.textContent = usuarios.length
                    ? 'Selecciona un resultado.'
                    : 'No se encontraron usuarios disponibles.';
            } catch (error) {
                if (error.name === 'AbortError' || versionActual !== version) return;
                cerrarResultados();
                estado.textContent = 'No fue posible buscar. Inténtalo nuevamente.';
            }
        }, 300);
    });
    formulario.addEventListener('submit', (evento) => {
        if (!idUsuario.value) {
            evento.preventDefault();
            estado.textContent = 'Selecciona un usuario de los resultados.';
            input.focus();
        }
    });
});

document.querySelectorAll('.modal-inventario').forEach((modal) => {
    // También se ejecuta cuando el diálogo se cierra con Escape.
    modal.addEventListener('close', () => {
        const boton = document.querySelector(`[aria-controls="${modal.id}"]`);
        boton?.setAttribute('aria-expanded', 'false');
        boton?.focus();
    });
});

document.querySelectorAll('[data-historial-producto]').forEach((panel) => {
    const filas = panel.querySelector('[data-filas-historial]');
    const estado = panel.querySelector('[data-estado-historial]');
    const etiquetaPagina = panel.querySelector('[data-pagina-historial]');
    const paginacion = panel.querySelector('[data-paginacion-historial]');
    const anterior = panel.querySelector('[data-anterior-historial]');
    const siguiente = panel.querySelector('[data-siguiente-historial]');
    const actualizar = panel.querySelector('[data-actualizar-historial]');
    let pagina = 1;
    let controlador;
    let version = 0;

    // Las fechas del servidor se muestran sin conversiones de zona horaria.
    function fecha(valor) {
        if (!valor) return '—';
        const [dia, hora] = valor.split(' ');
        const [anio, mes, numero] = dia.split('-');
        return `${numero}/${mes}/${anio} ${(hora || '').slice(0, 5)}`;
    }

    async function cargar(destino = pagina) {
        controlador?.abort();
        controlador = new AbortController();
        const versionActual = ++version;
        anterior.disabled = siguiente.disabled = actualizar.disabled = true;
        panel.setAttribute('aria-busy', 'true');
        filas.replaceChildren();
        etiquetaPagina.replaceChildren();
        paginacion.hidden = true;
        estado.textContent = 'Consultando historial…';
        try {
            const parametros = new URLSearchParams({ producto: panel.dataset.historialProducto, pagina: destino });
            const respuesta = await fetch(`/sistemaInventario/admin/inventario/modal/historial.php?${parametros}`, {
                signal: controlador.signal,
                headers: { Accept: 'application/json' },
            });
            if (!respuesta.ok) throw new Error('No fue posible consultar el historial.');
            const datos = await respuesta.json();
            if (versionActual !== version) return;
            if (!Array.isArray(datos.asignaciones)) throw new Error('Respuesta inválida.');
            pagina = datos.pagina;
            datos.asignaciones.forEach((asignacion) => {
                const fila = document.createElement('tr');
                [asignacion.responsable || 'Usuario no disponible', asignacion.cedula || '—',
                    fecha(asignacion.fecha_asignacion), fecha(asignacion.fecha_devolucion),
                    asignacion.fecha_devolucion ? 'Finalizada' : 'Vigente'].forEach((valor) => {
                    const celda = document.createElement('td');
                    celda.textContent = valor;
                    fila.appendChild(celda);
                });
                filas.appendChild(fila);
            });
            estado.textContent = datos.total ? `${datos.total} asignación(es) registrada(s).` : 'Este producto aún no tiene asignaciones registradas.';
            paginacion.hidden = datos.totalPaginas <= 1;
            if (datos.totalPaginas > 1) {
                // Limita los botones visibles para mantener compacto el modal.
                const inicio = Math.max(1, Math.min(pagina - 2, datos.totalPaginas - 4));
                const fin = Math.min(datos.totalPaginas, inicio + 4);
                for (let numero = inicio; numero <= fin; numero++) {
                    const botonPagina = document.createElement('button');
                    botonPagina.type = 'button';
                    botonPagina.className = 'pagina-historial';
                    botonPagina.textContent = numero;
                    botonPagina.setAttribute('aria-label', `Página ${numero}`);
                    if (numero === pagina) {
                        botonPagina.setAttribute('aria-current', 'page');
                    }
                    botonPagina.addEventListener('click', () => cargar(numero));
                    etiquetaPagina.appendChild(botonPagina);
                }
            }
            anterior.disabled = pagina <= 1;
            siguiente.disabled = pagina >= datos.totalPaginas;
        } catch (error) {
            if (error.name === 'AbortError' || versionActual !== version) return;
            estado.textContent = 'No fue posible consultar el historial. Usa Actualizar para reintentar.';
        } finally {
            if (versionActual === version) {
                actualizar.disabled = false;
                panel.removeAttribute('aria-busy');
            }
        }
    }

    panel.addEventListener('consultar-historial', () => cargar());
    panel.closest('details')?.addEventListener('toggle', (evento) => {
        if (evento.target.open) cargar();
    });
    anterior.addEventListener('click', () => cargar(pagina - 1));
    siguiente.addEventListener('click', () => cargar(pagina + 1));
    actualizar.addEventListener('click', () => cargar());
});


}
iniciarFilasInventario();
document.addEventListener('paginacion:actualizada', iniciarFilasInventario);
const formularioFiltros = document.querySelector('#filtros-inventario');

if (formularioFiltros) {
    const desplegable = formularioFiltros.querySelector('.desplegable-categorias');

    const casillas = formularioFiltros.querySelectorAll(
        'input[type="checkbox"][name="categoria[]"]',
    );

    const claveEstado = 'inventario-categorias';
    let temporizadorCategorias;
    let envioAutomatico = false;

    function borrarEstado() {
        try {
            sessionStorage.removeItem(claveEstado);
        } catch {
            // El filtro funciona aunque el navegador bloquee el almacenamiento.
        }
    }

    function guardarEstado(casilla) {
        try {
            sessionStorage.setItem(
                claveEstado,
                JSON.stringify({
                    categoria: casilla.value,
                    desplazamiento: desplegable.querySelector('.opciones-categorias').scrollTop,
                    fecha: Date.now(),
                }),
            );
        } catch {
            // El almacenamiento solo conserva la apertura y el foco.
        }
    }

    if (desplegable) {
        let guardado = null;

        try {
            guardado = JSON.parse(sessionStorage.getItem(claveEstado));
        } catch {
            guardado = null;
        }

        borrarEstado();

        if (
            guardado &&
            typeof guardado.fecha === 'number' &&
            Date.now() - guardado.fecha >= 0 &&
            Date.now() - guardado.fecha < 30000
        ) {
            desplegable.open = true;

            const casillaAnterior = Array.from(casillas).find(
                (casilla) => casilla.value === guardado.categoria,
            );

            casillaAnterior?.focus({ preventScroll: true });

            const opciones = desplegable.querySelector('.opciones-categorias');

            opciones.scrollTop = Number(guardado.desplazamiento) || 0;
        }

        casillas.forEach((casilla) => {
            casilla.addEventListener('change', () => {
                clearTimeout(temporizadorCategorias);

                const seleccionadas = Array.from(casillas).filter(
                    (opcion) => opcion.checked,
                ).length;

                desplegable.querySelector('summary').textContent = seleccionadas
                    ? `${seleccionadas} seleccionada(s)`
                    : 'Todas las categorías';

                temporizadorCategorias = setTimeout(() => {
                    if (desplegable.open) {
                        guardarEstado(casilla);
                    } else {
                        borrarEstado();
                    }

                    envioAutomatico = true;
                    formularioFiltros.requestSubmit();
                    envioAutomatico = false;
                }, 300);
            });
        });

        desplegable.addEventListener('keydown', (evento) => {
            if (evento.key === 'Escape') {
                evento.preventDefault();
                desplegable.open = false;
                desplegable.querySelector('summary').focus();
            }
        });

        document.addEventListener('click', (evento) => {
            if (!desplegable.contains(evento.target)) {
                desplegable.open = false;
            }
        });

        formularioFiltros.addEventListener('submit', () => {
            clearTimeout(temporizadorCategorias);

            if (!envioAutomatico) {
                borrarEstado();
            }
        });
    }
}

// FUNCIONES QUE ADMINISTRAN LA VENTANA DE

function abrirModal(idModal) {
    const modal = document.getElementById(idModal);
    if (!modal || modal.open) return;
    modal.showModal();
    modal.querySelectorAll('[data-historial-producto]').forEach((panel) => {
        if (!panel.closest('details') || panel.closest('details').open) {
            panel.dispatchEvent(new Event('consultar-historial'));
        }
    });
    document.querySelector(`[aria-controls="${idModal}"]`)?.setAttribute('aria-expanded', 'true');
}

function cerrarModal(idModal) {
    const modal = document.getElementById(idModal);
    if (modal?.open) modal.close();
}

const modalRecuperado = document.querySelector('[data-recuperar-formulario]');
if (modalRecuperado) {
    abrirModal(modalRecuperado.id);
    const buscador = modalRecuperado.querySelector('.buscar-usuario');
    const seleccionado = modalRecuperado.querySelector('[name="id_usuario"]');
    if (buscador?.value && !seleccionado?.value) {
        buscador.dispatchEvent(new Event('input'));
    }
}
