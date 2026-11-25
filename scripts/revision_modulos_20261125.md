# Revisión de módulos — 25 de noviembre de 2026

## Resultado y alcance

Se ejecutaron las 25 suites `scripts/probar_*.php`: 23 aprobaron con la configuración predeterminada de PHP CLI. Las dos restantes (`probar_exportacion_auditoria.php` y `probar_flujo_bajas.php`) aprobaron al ejecutarlas con `-d extension=zip`. Por tanto, las 25 aprobaron con ese requisito satisfecho; esto no descarta las fallas adicionales descritas abajo.

Se verificó la sintaxis de todos los PHP propios, 18 renderizados administrativos (nueve vistas con filtros normales y malformados), entradas públicas HTTP, protección de cuatro rutas administrativas y auditorías de integridad. Las vistas incluyen dashboard, áreas, atributos, categorías, usuarios, productos, inventario, bajas y auditoría. Las suites cubren asignación, devolución, códigos, atributos, unidades, imágenes, bajas, auditoría, recuperación de formularios, restablecimiento y límites de intentos.

No se cambió la lógica del aplicativo ni se modificaron registros reales. Las reproducciones de escritura usan tablas temporales; la prueba de límites revierte su transacción. No se enviaron correos ni se cambiaron contraseñas reales. No se probaron manualmente las interacciones JavaScript en un navegador ni la entrega SMTP; no se declara que esos flujos estén completamente validados.

## Hallazgos

### 1. Alta — Productos: pérdida de cambios al reenviar un borrador después de un conflicto

**Estado: reproducido con tablas temporales.**

En `admin/productos/index.php:75` se recuperan los valores anteriores del formulario. Sin embargo, el campo oculto `version` en la línea 231 usa la versión actual de la base de datos. Se pierde así la protección del borrador frente a una edición concurrente.

Pasos:

1. Dos administradores abren el mismo producto con la misma versión.
2. A guarda una descripción nueva.
3. B intenta guardar su formulario antiguo: el servicio rechaza correctamente la versión anterior.
4. La aplicación recupera el borrador de B y emite la versión actual del producto.
5. B vuelve a guardar: se acepta y sobrescribe el cambio de A con los valores anteriores.

**Mejora:** conservar la versión del borrador y ofrecer una comparación o recarga explícita antes de permitir reemplazar los cambios. Añadir cobertura del flujo de recuperación después del conflicto; probar únicamente el rechazo inicial no detecta esta falla.

Reproducción: `C:\xampp_nuevo\php\php.exe scripts\revision_modulos_20261125.php`.

### 2. Alta — Configuración: secreto SMTP guardado en texto plano

**Estado: confirmado por inspección de `config/correo.php`.**

El archivo contiene el usuario y la contraseña de aplicación del correo. No se reproducen sus valores en este informe. `.gitignore` excluye el archivo y la petición HTTP devuelve 403; no se afirma que esté publicado o versionado. Aun así, una copia del directorio o respaldo puede incluir el secreto.

**Mejora:** retirar el secreto del código, cargarlo desde la configuración del entorno y rotar la contraseña que está almacenada actualmente.

### 3. Alta — Base de datos: la aplicación opera con privilegios globales

**Estado: confirmado mediante la configuración y `SHOW GRANTS`.**

`config/conexion.php` utiliza `root` sin contraseña y la conexión dispone de `ALL PRIVILEGES ON *.*`. Un fallo que comprometa esa conexión tendría alcance sobre otras bases de datos del servidor.

**Mejora:** crear un usuario dedicado, con contraseña y permisos limitados a las operaciones que necesita esta base. Reservar permisos de administración y migración para una identidad separada.

### 4. Media — Consulta pública: muestra administradores y usuarios inactivos

**Estado: reproducido por HTTP sin sesión, sin guardar datos personales en el informe.**

En `consulta/index.php` el comentario indica que la consulta pública muestra funcionarios, pero la consulta SQL filtra solo por cédula. Se comprobó que una cédula de administrador y una de usuario inactivo producen una ficha pública. El código también incorpora foto cuando existe. No hay un límite de consultas en este controlador.

**Mejora:** definir el alcance público esperado y aplicar los filtros de rol y estado que correspondan; limitar consultas repetidas y revisar qué campos y fotografías deben ser públicos. La exposición de fichas se confirmó; no se realizó una prueba masiva de enumeración.

### 5. Media — Datos incompletos: cinco atributos obligatorios pendientes en tres productos

**Estado: confirmado con consultas de solo lectura sobre la base actual.**

| Producto | Atributos pendientes |
| --- | --- |
| 3 | DISCO DURO |
| 9 | DISCO DURO, PROCESADOR, SERVICE TAG |
| 20 | DISCO DURO |

**Impacto:** el inventario conserva equipos activos con información requerida incompleta. El dashboard dispone de una alerta para estos casos; no se atribuye este resultado a un fallo demostrado del alta actual.

**Mejora:** completar los datos a partir de las especificaciones reales y revisar cómo se completan los equipos existentes cuando se agrega un atributo obligatorio. No inventar valores para eliminar las alertas.

### 6. Baja — Pruebas de Excel: configuración predeterminada incompleta

**Estado: reproducido.**

PHP CLI no carga ZIP. `probar_exportacion_auditoria.php` falla al leer el XLSX con `Class "ZipArchive" not found`; `probar_flujo_bajas.php` se detiene al verificar ese requisito. Ambas aprobaron con:

```powershell
& C:\xampp_nuevo\php\php.exe -d extension=zip scripts\probar_exportacion_auditoria.php
& C:\xampp_nuevo\php\php.exe -d extension=zip scripts\probar_flujo_bajas.php
```

**Mejora:** documentar los requisitos y agregar un ejecutor común que detecte extensiones faltantes. Esto demuestra una limitación de la ejecución de las pruebas y lectura de XLSX, no una falla confirmada de descarga de Excel en el servidor. No se modificó `php.ini`.

### 7. Baja — Herramienta de auditoría antigua no ejecuta su escenario

**Estado: reproducido en `scripts/auditar_flujo_productos.php`.**

La herramienta invoca `guardarProducto` sin identificador del administrador; el valor predeterminado 0 es rechazado por la auditoría. Termina con `El administrador no está habilitado para registrar la operación` antes de completar su análisis. Además, contiene una expectativa antigua de aceptar una baja anterior a una devolución; las pruebas actuales de bajas verifican correctamente su rechazo.

**Mejora:** actualizar la herramienta para usar un administrador temporal y las reglas actuales, o retirarla del procedimiento de validación. El fallo corresponde a la herramienta, no demuestra una falla del servicio de bajas.

## Comprobaciones sin inconsistencias detectadas

- Las rutas administrativas probadas sin sesión redirigen al acceso; `config/correo.php` y el informe JSON en `scripts` devuelven 403, y `.git/config` devuelve 404.
- No se encontraron referencias huérfanas de productos, categorías, áreas, atributos, opciones ni unidades en las comprobaciones ejecutadas.
- No se encontraron productos activos sin código vigente, códigos vigentes duplicados, productos con varios códigos vigentes ni incompatibilidad de categoría del código.
- Los tres equipos inactivos tienen movimiento de baja, no tienen responsables ni códigos vigentes y no están marcados disponibles. No se detectaron bajas duplicadas, bajas anteriores al historial ni equipos activos con movimiento de baja.
- Las pruebas de transacciones verificaron rollback, liberación de códigos, devolución parcial y cierre de asignaciones sin modificar equipos reales.

## Evidencias guardadas

- `scripts/resultados_pruebas_20261125.json`: salida de la primera ejecución de las 25 suites; conserva las dos fallas iniciales por ZIP.
- `scripts/revision_modulos_20261125.php`: reproducción adicional de pérdida de cambios, comprobación del alcance público y listado de atributos pendientes. Devuelve éxito cuando reproduce el hallazgo; no es una suite que certifique ausencia de fallas.

Prioridad recomendada: resolver la pérdida de cambios, proteger las credenciales y limitar privilegios; después ajustar el alcance público y completar los datos pendientes.
