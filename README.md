# Nicode Web Monitor

Monitorización de inventario y mantenimiento experimental de varios Joomla desde
un Joomla central. Software libre, GPL-2.0-or-later; desarrollo público.

**1.1.1-dev.6: desarrollo previo a beta.** La validación en los hostings del propietario
está pendiente. No se promete compatibilidad con versiones anteriores de desarrollo.

## Instalar y conectar

Descarga los dos ZIP `pkg_` de la [prerelease de desarrollo](https://github.com/nicolauroca/nicode-web-monitor/releases/tag/v1.1.1-dev.6).
Los archivos automáticos «Source code» de GitHub no son instaladores Joomla.
Para construir desde el repositorio: `python tools/build.py`.

1. Instala `dist/pkg_nicodemonitor-1.1.1-dev.6.zip` en el Joomla central.
2. Instala `dist/pkg_nicodeconnector-1.1.1-dev.6.zip` en cada Joomla supervisado.
3. En **Componentes → Nicode Web Monitor — Central**, pulsa **Añadir sitio**, indica su nombre y genera un código.
4. En **Componentes → Nicode Web Monitor — Conector** del supervisado, introduce la dirección del
   central, pega el código y pulsa **Conectar**.

Identidad, credenciales y selección de IP se preparan automáticamente. El central
verifica y guarda el primer inventario antes de confirmar el alta. No hay que
rellenar parámetros de plugins para empezar.

El Joomla central también puede monitorizarse: instala ambos paquetes en él y
vincúlalo al mismo panel. En ese caso el inventario se consulta dentro de Joomla,
sin DNS ni llamadas HTTPS a su propia web. Los nombres visibles son **Nicode Web
Monitor — Central** y **Nicode Web Monitor — Conector**, partes del mismo producto.

El Central abre un directorio a todo el ancho, con una fila por sitio. **Entrar**
abre su ficha: Resumen, Inventario, Historial, Conexión y Mantenimiento. El inventario
se explora categoría a categoría, con migas y enlaces para volver. Alta y programación
tienen pantallas propias. [Navegación del Central](docs/central-ui.txt).

El Conector presenta un panel de estado, central vinculada, tipo de conexión y
fecha de vinculación, con instrucciones específicas para cada estado. El diseño
se adapta a móvil y al tema oscuro de Joomla; las acciones de desconexión quedan
separadas del formulario principal. [Diseño y comportamiento](docs/connector-ui.txt).

Necesitas Joomla 6 compatible con tu PHP/base de datos, cURL, Sodium y HTTPS válido.
El panel distingue conexión, tarea preparada y ejecución automática reciente.
Para intervalos regulares sin visitas, configura un cron en el hosting central;
el asistente muestra la ruta concreta. Python solo se usa para construir los ZIP.

## Información y permisos por sitio

Central puede consultar **13 categorías**: Joomla, PHP, base de datos, extensiones,
actualizaciones detectadas, configuración general, señales de seguridad, usuarios
agregados, contenido agregado, idiomas, plantillas, tareas y almacenamiento.

En **Conector → Información que compartes**, cada Joomla permite o deniega cada
categoría. Las cuatro esenciales están permitidas de entrada; las nueve nuevas
requieren consentimiento. Los perfiles Esencial/Todo/Nada necesitan guardar.
La denegación se cumple antes de leer los datos, también al monitorizar el propio
central. Usuarios y contenido solo en cifras: sin nombres, correos, contraseñas,
textos de artículos, parámetros, rutas ni claves de descarga.

Central conserva el último inventario recibido, su fecha y cobertura. **Actualizar
inventario** consulta el sitio; la pestaña **Inventario** de su ficha abre la observación sin red.
Una nueva recepción sustituye la anterior, incluidas las categorías denegadas.
Denegar no borra lo que un central haya recibido previamente. Consulta el
[alcance de los campos y su conservación](docs/inventory-and-sharing.txt).

Los datos parciales, antiguos, no disponibles y no compartidos se distinguen.
Las actualizaciones proceden de la caché de Joomla; el almacenamiento corresponde
al sistema de archivos visible para PHP, no a la cuota del hosting. Inventario no
acredita disponibilidad pública, ausencia de malware o recuperación de una copia.

Copias y actualizaciones preparadas de extensiones/paquetes/core permanecen en
Mantenimiento con credenciales, permisos y confirmaciones independientes.

## Documentación y pruebas

Toda la documentación vigente comienza en [docs/README.md](docs/README.md).
Consulta [instalación](docs/installation-from-zero.txt),
[contexto de desarrollo](docs/development-context.txt),
[pruebas](docs/testing.txt) y [verificación actual](docs/verification.txt).
Los documentos históricos están en Git, fuera de las guías activas.

Los ZIP tienen orden, timestamps y permisos reproducibles. `dist/` y `.local/`
están ignorados: no guardar en Git secretos, inventarios, instalaciones ni dumps.
