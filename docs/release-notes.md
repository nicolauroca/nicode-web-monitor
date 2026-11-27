# Nicode Web Monitor 1.1.1-dev.9 — Desarrollo

**Prerelease de desarrollo. No es una versión definitiva, estable ni una beta final.**

Organiza las acciones existentes de Central con navegación por herramientas y advertencias en su contexto.

- Mantenimiento comienza con Copias, Extensiones o Joomla; cada herramienta abre
  su propia página y carga solo sus operaciones.
- Ayuda ampliable con icono (i), accesible por teclado y sin JavaScript. El aviso
  necesario para decidir permanece visible junto a la confirmación.
- Credenciales bajo Configurar acceso avanzado y estado de credencial registrada;
  registrar una credencial no se presenta como preparación remota completada.
- Registros plegables con estado/fecha; la primera operación se abre inicialmente.
  Resultado incierto mantiene consulta de estado y advertencia, sin ejecución ciega.
- La ficha del propio Central explica el alcance local y enlaza a sus actualizadores
  nativos cuando hay permisos, sin ofrecer un transporte remoto que no está preparado.
- Retornos al sitio y herramienta correctos; permisos, CSRF, confirmaciones y
  protocolos de copia/actualización conservados.

El catálogo revisado de acciones y prioridades, con mySites.guru, Watchful y
Panopticon como referencias, está en docs/central-actions.md. Configuración remota,
limpieza de caché, selección directa desde proveedores, lotes y nuevas automatizaciones
siguen siendo propuestas. Esta entrega no añade esos endpoints ni elimina la
preparación local de los motores experimentales.

## Instalar

En Central: **pkg_nicodemonitor-1.1.1-dev.9.zip**.
En cada sitio: **pkg_nicodeconnector-1.1.1-dev.9.zip**.
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
