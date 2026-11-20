document.addEventListener('DOMContentLoaded', function () {
    const rol = document.getElementById('rol');
    const campoContrasena = document.getElementById('campo-contrasena');
    const contrasena = document.getElementById('contrasena');
    const configuracion = JSON.parse(document.getElementById('config-usuarios').textContent);
    const estaEditando = configuracion.estaEditando;
    const rolOriginal = configuracion.rolOriginal;

    function actualizarCampoContrasena() {
        const esAdministrador = rol.value === 'ADMINISTRADOR';

        campoContrasena.hidden = !esAdministrador;
        contrasena.disabled = !esAdministrador;

        const necesitaNuevaContrasena =
            esAdministrador && (!estaEditando || rolOriginal !== 'ADMINISTRADOR');

        contrasena.required = necesitaNuevaContrasena;

        if (!esAdministrador) {
            contrasena.value = '';
        }
    }

    rol.addEventListener('change', actualizarCampoContrasena);
    actualizarCampoContrasena();

    const imagen = document.getElementById('imagen');
    const vista = document.getElementById('vista-imagen-usuario');
    const sinImagen = document.getElementById('sin-imagen-usuario');
    const estadoImagen = document.getElementById('estado-imagen');
    const cancelarImagen = document.getElementById('cancelar-imagen');
    const original = vista.getAttribute('src');
    const mensajeInicial = estadoImagen.textContent;
    let urlVista = null;

    function restaurarVista() {
        if (urlVista) URL.revokeObjectURL(urlVista);
        urlVista = null;
        if (original) vista.src = original;
        else vista.removeAttribute('src');
        vista.hidden = !original;
        sinImagen.hidden = Boolean(original);
    }

    function errorImagen(mensaje) {
        restaurarVista();
        imagen.setCustomValidity(mensaje);
        estadoImagen.textContent = mensaje;
        estadoImagen.classList.add('imagen-error');
    }

    imagen.addEventListener('change', function () {
        const archivo = imagen.files[0];
        imagen.setCustomValidity('');
        estadoImagen.classList.remove('imagen-error');
        cancelarImagen.hidden = !archivo;
        if (!archivo) {
            restaurarVista();
            estadoImagen.textContent = mensajeInicial;
            return;
        }
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(archivo.type)) {
            errorImagen('Selecciona una imagen JPG, PNG o WebP válida.');
            return;
        }
        if (archivo.size > 2 * 1024 * 1024) {
            errorImagen('La imagen debe tener un tamaño máximo de 2 MB.');
            return;
        }
        const url = URL.createObjectURL(archivo);
        const prueba = new Image();
        prueba.onload = function () {
            if (imagen.files[0] !== archivo) { URL.revokeObjectURL(url); return; }
            if (prueba.naturalWidth > 4096 || prueba.naturalHeight > 4096) {
                URL.revokeObjectURL(url);
                errorImagen('La imagen no puede superar los 4096 píxeles de ancho o alto.');
                return;
            }
            restaurarVista();
            urlVista = url;
            vista.src = url;
            vista.hidden = false;
            sinImagen.hidden = true;
            estadoImagen.textContent = 'Imagen seleccionada. Se guardará al guardar el usuario.';
        };
        prueba.onerror = function () {
            URL.revokeObjectURL(url);
            if (imagen.files[0] === archivo) errorImagen('No se pudo leer la imagen. Selecciona otra.');
        };
        prueba.src = url;
    });
    cancelarImagen.addEventListener('click', function () {
        imagen.value = '';
        imagen.dispatchEvent(new Event('change'));
    });
});
