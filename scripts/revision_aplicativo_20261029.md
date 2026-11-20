# Revisión del aplicativo — 29 de octubre de 2026

## Errores corregidos

1. **Entradas malformadas generaban advertencias de PHP.** Se reprodujo el problema enviando listas en campos de texto de consulta, recuperación y filtros administrativos. Se agregó una lectura de texto común que rechaza listas y objetos, y se aplicó a los formularios públicos y a los listados y procesadores administrativos afectados. La consulta y el acceso validan cédulas de 4 a 20 dígitos, como el registro de usuarios. La herramienta de creación de administradores usa el mismo formato.
2. **El restablecimiento no comprobaba nuevamente el vencimiento al guardar.** El enlace se consume ahora mediante una actualización condicional que verifica cuenta, estado, vencimiento y ausencia de uso previo. El consumo y el cambio de contraseña pertenecen a una misma transacción. Se verificaron enlaces vencidos, cancelados, usados, de otra cuenta y el rollback cuando falta la cuenta. Un enlace rechazado permite solicitar otro desde la pantalla.
3. **La herramienta `verificar_inventario.php` no tenía restricción CLI en su código.** Se agregó la restricción, antes de abrir la conexión o realizar operaciones de verificación. No se ejecutó esa herramienta contra registros reales.
4. **Pruebas desactualizadas impedían comprobar varios procesos.** Se corrigieron los fixtures de auditoría para usar administradores y eventos en tablas temporales, se actualizaron pruebas del esquema antiguo de códigos y se repararon expectativas y rutas antiguas del modal de recuperación. El servicio de productos se prueba con MariaDB, que es el motor que utilizan sus bloqueos y códigos vigentes.

## Validación

- 24 suites `scripts/probar_*.php` aprobadas. Incluyen productos, códigos, atributos, unidades, búsquedas, asignaciones, devoluciones, desactivaciones, bajas, auditoría, Excel, límites de acceso, recuperación de formularios, entradas públicas y restablecimiento.
- Nueve vistas administrativas renderizadas con sesión de prueba, tanto con filtros normales como malformados: dashboard, áreas, atributos, categorías, usuarios, productos, inventario, bajas y auditoría.
- Sintaxis PHP y referencias estáticas de archivos incluidos verificadas.
- La consulta pública responde HTTP 200; las rutas administrativas verificadas redirigen al acceso cuando no hay sesión. Inventario funciona con y sin barra final; la ruta incompleta devuelve 404.
- Para leer y verificar los XLSX se cargó ZIP únicamente en el comando de prueba mediante `php -d extension=zip`. No se cambió la configuración de PHP del servidor.
- Revisión de integridad de solo lectura: cero inconsistencias de disponibilidad, asignaciones vigentes duplicadas, equipos dados de baja con asignaciones abiertas, usuarios inactivos con asignaciones abiertas, códigos vigentes en equipos dados de baja o productos sin área/categoría existente.

## Datos pendientes de completar

El dashboard detecta cuatro productos activos de la categoría EQUIPOS DE COMPUTO con atributos obligatorios pendientes:

| ID de producto | Atributos pendientes |
| --- | --- |
| 3 | DISCO DURO |
| 9 | DISCO DURO, PROCESADOR, SERVICE TAG |
| 20 | DISCO DURO |
| 22 | DISCO DURO |

Estos valores requieren consultar las especificaciones reales de cada equipo. No se inventaron valores ni se modificaron esos registros.

## Alcance y límites

La revisión combina lectura de código, consultas de integridad, pruebas automatizadas, renderizado de vistas por CLI y solicitudes HTTP. No equivale a probar manualmente cada interacción JavaScript en un navegador. No se enviaron correos de recuperación ni se cambiaron contraseñas de cuentas reales; los escenarios de cambio de contraseña usan tablas temporales.
