# Revisión de bajas — 9 de octubre de 2026

Se revisaron las rutas, permisos de administrador, CSRF, formulario y modal, procesamiento, servicio transaccional, efectos sobre inventario, historial, listado, filtros, paginación y exportación XLSX.

## Correcciones

- El servicio solo comprobaba las fechas de asignaciones y códigos vigentes. Ahora rechaza bajas anteriores al inicio o finalización de cualquier registro del historial, y anteriores a movimientos de entrada. Evita equipos con devoluciones o códigos finalizados después de su baja.
- El detalle conserva el movimiento aun cuando no se encuentre el usuario que lo registró, mediante LEFT JOIN y un texto de respaldo.
- Se eliminó un atributo HTML duplicado, se corrigieron textos del listado y se limitó el estilo del encabezado Excel a sus siete columnas.
- Se actualizó la prueba de búsquedas: las bajas pertenecen a su listado propio y el inventario activo admite múltiples categorías.

## Datos existentes: consulta de solo lectura

Hay dos equipos inactivos. Ninguno tiene responsables vigentes, código vigente, disponibilidad incorrecta, baja duplicada o ausencia del movimiento de baja. Tampoco hay equipos activos con una baja registrada.

El hallazgo cronológico del equipo **#11** quedó resuelto tras la corrección realizada por el usuario en la base de datos. En la nueva consulta del 9 de octubre de 2026, `auditar_bajas.php` devuelve **0 bajas anteriores al historial** y **0 casos en las demás comprobaciones de inconsistencias**. La verificación fue de solo lectura y no modificó datos reales.

## Verificación

Pasaron las pruebas de bajas, cierre de múltiples responsables, liberación y reutilización de códigos, control de versión, rechazo de duplicados y rollback ante un fallo al actualizar el estado. También pasaron las pruebas nuevas de cronología histórica, filtros combinados y malformados, búsqueda literal, paginación, concordancia con exportación y generación/lectura de XLSX con textos que comienzan por `=`.

Pasaron las pruebas de respuesta del modal (éxito, error 422, conservación del formulario y retorno interno con filtros), búsquedas e historial de inventario. Los archivos PHP modificados pasaron la comprobación de sintaxis.

Comandos principales:

```powershell
& C:/xampp_nuevo/php/php.exe -d extension=zip scripts/probar_flujo_bajas.php
& C:/xampp_nuevo/php/php.exe scripts/probar_busquedas.php
& C:/xampp_nuevo/php/php.exe scripts/probar_respuesta_modal_baja.php
& C:/xampp_nuevo/php/php.exe scripts/probar_historial_inventario.php
& C:/xampp_nuevo/php/php.exe scripts/auditar_bajas.php
```

Las pruebas de escritura usan tablas temporales privadas. La extensión ZIP se habilitó solo para el proceso de prueba porque PHP CLI no la carga por defecto; no se cambió la configuración de XAMPP. La revisión del JavaScript y de las vistas fue estática: no se realizó una sesión visual en navegador ni una baja sobre equipos reales.
