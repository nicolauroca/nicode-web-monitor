# Nicode Web Monitor 1.1.1-dev.6 — Desarrollo

**Prerelease de desarrollo. No es una versión definitiva, estable ni una beta final.**

Reorganiza Central para navegar de lo general al detalle sin mostrarlo todo a la vez.

- Directorio a todo el ancho, una fila por Joomla y acción Entrar; búsqueda y filtros.
- Ficha independiente: Resumen, Inventario, Historial, Conexión y Mantenimiento.
- Inventario con índice de trece categorías; cada categoría abre su propia página.
- Migas, pestañas y enlaces Volver para subir o bajar de nivel. Navegar consulta
  la observación guardada; Actualizar inventario realiza una consulta nueva.
- Alta y programación en pantallas independientes. Mantenimiento muestra únicamente
  formularios y operaciones del sitio elegido, con los mismos permisos y confirmaciones.
- Consulta guardada con retorno POST/303/GET para evitar repetirla al recargar.
  Los fallos conservan su diagnóstico y no se ocultan con observaciones anteriores.
- Formularios nativos, navegación sin JavaScript, tema oscuro y adaptación móvil.
- Se conservan los permisos por categoría del Conector y la automonitorización.

## Instalar

En Central: **pkg_nicodemonitor-1.1.1-dev.6.zip**.
En cada sitio: **pkg_nicodeconnector-1.1.1-dev.6.zip**.
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
