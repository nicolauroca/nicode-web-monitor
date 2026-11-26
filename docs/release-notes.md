# Nicode Web Monitor 1.1.1-dev.5 — Desarrollo

**Prerelease de desarrollo. No es una versión definitiva, estable ni una beta final.**

Amplía el inventario y permite que cada Joomla decida qué comparte desde su Conector.

- Trece categorías: Joomla, PHP, base de datos, extensiones, actualizaciones en caché,
  configuración, seguridad, usuarios agregados, contenido agregado, idiomas,
  plantillas, tareas y almacenamiento.
- Un permiso por categoría en el Conector, con perfiles Esencial/Todo/Nada y guardado
  explícito. Solo las cuatro esenciales están permitidas de entrada.
- La denegación se aplica antes de recoger datos y funciona tanto por HTTPS como
  al monitorizar el propio Central. Desconectar conserva la política local.
- Central distingue compartido, no compartido, no disponible y error. Las categorías
  denegadas no generan por sí mismas una alerta de inventario parcial.
- Última observación guardada por sitio, versiones y cobertura en las tarjetas y
  detalle fechado sin peticiones de red. Cada recepción sustituye el dato anterior.
- Sin identidades de usuarios, correos, contraseñas, textos, parámetros de extensiones,
  URLs de actualización, rutas locales ni claves de descarga.
- Formularios nativos, uso sin JavaScript, tema oscuro y pantallas móviles.

## Instalar

En Central: **pkg_nicodemonitor-1.1.1-dev.5.zip**.
En cada sitio: **pkg_nicodeconnector-1.1.1-dev.5.zip**.
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
