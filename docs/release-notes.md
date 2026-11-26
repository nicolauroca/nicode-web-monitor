# Nicode Web Monitor 1.1.1-dev.7 — Desarrollo

**Prerelease de desarrollo. No es una versión definitiva, estable ni una beta final.**

Central interpreta la información del sitio para mostrar prioridades y próximos pasos.

- Semáforos Joomla/PHP: versión al día, pendiente, solo seguridad o sin soporte.
  Catálogo oficial con fechas y caducidad; sin verde para datos antiguos o desconocidos.
- Referencias PHP/Joomla actualizadas por la tarea y mediante un POST autorizado.
  Los fallos conservan las fechas; no se envían inventarios a los proveedores.
- Actualizaciones distintas realmente superiores a la versión instalada. Se separan
  Joomla core, duplicados, versiones desconocidas y caché sin refrescar.
- Señales compartidas de configuración, espacio libre, modo offline y tareas
  fallidas/bloqueadas/sin resultado, respetando los permisos del Conector.
- Filtros por prioridad, también sin JavaScript, y página Diagnóstico con criterio,
  fuentes y acceso a cada categoría. Resumen muestra los primeros asuntos a revisar.
- Duración de la consulta de inventario medida desde Central, identificada como
  local o HTTPS; no se presenta como velocidad de carga de la web ni uptime.
- Navegación por sitio, automonitorización, formularios y mantenimiento independiente
  conservados; diseño móvil y tema oscuro.

La comparativa con mySites.guru y Watchful está en docs/competitive-analysis.md.
Sonda pública/SSL, Lighthouse, base de vulnerabilidades e informes siguen pendientes;
no se atribuyen a esta entrega. docs/site-health.txt explica los criterios publicados.

## Instalar

En Central: **pkg_nicodemonitor-1.1.1-dev.7.zip**.
En cada sitio: **pkg_nicodeconnector-1.1.1-dev.7.zip**.
Si Central también se supervisa, instalar ambos en ese Joomla. Actualizar los dos
paquetes completos, sin desinstalar ni borrar tablas. «Source code» no es instalable.

El inventario actual usa **schema_version=2** y no incorpora compatibilidad con
esquemas de anteriores versiones de desarrollo. El instalador asegura la tabla
actual de observaciones y conserva la reparación de connection_type. Copias y
actualizaciones mantienen sus permisos, credenciales y preparación independientes.

Denegar afecta a futuras consultas; no borra datos que Central ya haya recibido.
Una nueva recepción reemplaza su última observación; eliminar el registro elimina
esa copia de la base de datos del componente. Las copias externas tienen su propia
conservación. Actualizaciones en caché no garantizan que el sitio esté actualizado;
espacio del sistema de archivos no equivale a cuota del alojamiento.

Campos, límites y privacidad: docs/inventory-and-sharing.txt.
Pruebas ejecutadas y límites: docs/verification.txt. Pendiente la validación del
propietario en sus alojamientos; publicar una release no la convierte en producción.
