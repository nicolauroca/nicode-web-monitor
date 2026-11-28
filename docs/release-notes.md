# Nicode Web Monitor 1.1.1-dev.10 — Desarrollo

**Prerelease de desarrollo. No es una versión definitiva, estable ni una beta final.**

Aplica el manual corporativo Nicode Studio 1.0 a Central y Conector, conservando
los nombres de producto y la navegación existente, sin publicidad añadida.

- Sora variable original servida desde cada instalación, con fallback del sistema
  y licencia OFL incluida. No depende de Google Fonts ni de un CDN.
- Paleta de acciones violeta, superficies neutras y cabeceras oscuras del manual.
  Éxito, advertencia y error conservan colores semánticos y texto/iconos.
- Isotipo Enlace v6 original con variante de fondo oscuro, proporciones y área libre.
- Modo oscuro adaptado, bordes de control, foco de teclado y tamaño de lectura
  revisados. El administrador Joomla conserva su propia identidad y tipografía.
- Una fuente de recursos en tools/brand; cada componente instala su propia copia,
  sin depender del otro paquete. Funciones, permisos, formularios y transportes
  conservados. Esta revisión no cambia el requisito actual de Sodium nativo.
- Manual y reglas de aplicación vigentes en docs/brand, con procedencia de recursos.

## Instalar

En Central: **pkg_nicodemonitor-1.1.1-dev.10.zip**.
En cada sitio: **pkg_nicodeconnector-1.1.1-dev.10.zip**.
Si Central también se supervisa, instalar ambos en ese Joomla. Actualizar los dos
paquetes completos, sin desinstalar ni borrar tablas. «Source code» no es instalable.

Copias y actualizaciones siguen siendo experimentales y requieren preparación
local, permisos y credenciales independientes. Configuración remota, selección
simple desde proveedores, caché y lotes siguen siendo propuestas en
**docs/central-actions.md**, no funciones nuevas de esta entrega.

El inventario conserva schema_version=2 y sus permisos por categoría. Denegar
limita futuras consultas; no elimina datos ya recibidos ni copias externas.
Campos, límites y privacidad: docs/inventory-and-sharing.txt.
Pruebas ejecutadas y límites: docs/verification.txt. Pendiente la validación del
propietario en sus alojamientos; publicar una release no la convierte en producción.
