# Importación GM-FT02-01

Se incluye exclusivamente la hoja AÑO 2026 por indicación del usuario: 27 filas, 25 herramientas tras eliminar los duplicados de las filas 17 y 32. Todas las demás hojas quedan excluidas. Una serie repetida se considera un mismo equipo. Sin serie se compara nombre, fabricante, modelo, rango y código interno.

Campos de registro: categoría, área, descripción, código generado y estado del registro. Atributos conservados: fabricante, modelo, serie (texto), magnitud/rango (texto para conservar unidades), qué mide, ubicación, tipo de trabajo, frecuencia de calibración, código interno original, observaciones y hoja/fila de origen. No se deducen fechas a partir de los números del calendario, ni se considera que registrar una herramienta certifique su calibración.

Categoría propuesta: HERRAMIENTAS METROLÓGICAS; prefijo ADP-MET, consecutivo generado por el servicio existente. Área administrativa propuesta: Mantenimiento. La ubicación exacta del Excel se conserva como atributo. No se asignan responsables a partir de menciones informales en la ubicación. Los códigos internos se repiten entre herramientas, por lo que se conservan como referencia, sin usarlos como identificador único.

El estado ACTIVO del sistema indica un registro de inventario, no una autorización para usar instrumentos inhabilitados. Se mantienen textualmente sus observaciones metrológicas.

## Ejecución

Desde la raíz del proyecto, análisis sin modificar la base de datos:

```powershell
C:\xampp_nuevo\php\php.exe -d extension_dir=C:\xampp_nuevo\php\ext -d extension=zip scripts/importar_plan_metrologico.php 'C:\Users\Ytellez\Downloads\GM-FT02-01 PLAN METROLOGICO.xlsx'
```

Para insertar, agregar `--insertar --administrador=ID` usando el ID real de un administrador activo. La auditoría requiere ese dato explícito. El importador valida el administrador, reutiliza catálogos compatibles, usa el servicio habitual para códigos, atributos y auditoría, y evita reimportar mediante una clave estable por herramienta. También busca series existentes en atributos Número de serie, Serial y Serie. Ante un error puede quedar un lote parcial: repetir la ejecución omite los registros ya guardados. El resultado se escribe después de cada equipo en resultado_importacion_metrologica.json.

## Verificación realizada

- PHP: sintaxis correcta.
- Lectura real del XLSX: 25 registros de 2026 y avisos exportados a plan_metrologico_analizado.json.
- Inserción pendiente: MySQL local escucha en el puerto 3306 pero no completa el saludo inicial. El cliente oficial mysqladmin también falla con `Lost connection ... handshake: reading initial communication packet`.
- No se insertaron herramientas ni se validó el importador contra la estructura actual de la base de datos. Se requiere restablecer MySQL y conocer el administrador para continuar.
