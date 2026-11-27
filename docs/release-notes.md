# Nicode Web Monitor 1.1.1-dev.8 — Desarrollo

**Prerelease de desarrollo. No es una versión definitiva, estable ni una beta final.**

Corrige el diagnóstico y el orden de las comprobaciones al instalar desde cero.

- Los paquetes Central y Conector verifican Joomla 6+, Sodium y cURL antes de
  instalar sus piezas. Un requisito ausente no deja hijos nuevos instalados.
- El error identifica módulos ausentes, versiones Joomla/PHP detectadas y el paso
  necesario en el alojamiento, en español o inglés.
- Los componentes comprueban también sus requisitos si se instalan individualmente.
- Desinstalar sigue siendo posible aunque falten Sodium o cURL.
- Validado el Conector solo, sin Central, en un Joomla 6.2.0 nuevo; reinstalación,
  conservación de permisos y automonitorización con ambos paquetes.

La ausencia de Sodium o cURL en el PHP del servidor sigue requiriendo su activación
en el alojamiento. El ZIP no puede habilitar esos módulos. No se modifica el cifrado
ni se rebaja la verificación HTTPS para evitar el requisito.

## Instalar

En Central: **pkg_nicodemonitor-1.1.1-dev.8.zip**.
En cada sitio: **pkg_nicodeconnector-1.1.1-dev.8.zip**.
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
