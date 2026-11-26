# Nicode Web Monitor

Monitorización de inventario y mantenimiento experimental de varios Joomla desde
un Joomla central. Software libre, GPL-2.0-or-later; desarrollo público.

**1.1.1-dev.4: desarrollo previo a beta.** La validación en los hostings del propietario
está pendiente. No se promete compatibilidad con versiones anteriores de desarrollo.

## Instalar y conectar

Descarga los dos ZIP `pkg_` de la [prerelease de desarrollo](https://github.com/nicolauroca/nicode-web-monitor/releases/tag/v1.1.1-dev.4).
Los archivos automáticos «Source code» de GitHub no son instaladores Joomla.
Para construir desde el repositorio: `python tools/build.py`.

1. Instala `dist/pkg_nicodemonitor-1.1.1-dev.4.zip` en el Joomla central.
2. Instala `dist/pkg_nicodeconnector-1.1.1-dev.4.zip` en cada Joomla supervisado.
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

El Central ofrece un resumen de estados reales, búsqueda y filtros de sitios,
historial desplegable, inventario legible y alta guiada. La programación y el
mantenimiento tienen secciones propias. [Interfaz del Central](docs/central-ui.txt).

El Conector presenta un panel de estado, central vinculada, tipo de conexión y
fecha de vinculación, con instrucciones específicas para cada estado. El diseño
se adapta a móvil y al tema oscuro de Joomla; las acciones de desconexión quedan
separadas del formulario principal. [Diseño y comportamiento](docs/connector-ui.txt).

Necesitas Joomla 6 compatible con tu PHP/base de datos, cURL, Sodium y HTTPS válido.
El panel distingue conexión, tarea preparada y ejecución automática reciente.
Para intervalos regulares sin visitas, configura un cron en el hosting central;
el asistente muestra la ruta concreta. Python solo se usa para construir los ZIP.

## Alcance

Inventario de Joomla, PHP web, base de datos y extensiones, resúmenes de comprobación,
historial, alertas internas y correo SMTP opcional. Los datos parciales o antiguos
se muestran explícitamente. Inventario no equivale a comprobar disponibilidad
pública, ausencia de malware o recuperación de una copia.

Copias conjuntas y actualizaciones preparadas de extensiones/paquetes/core quedan
en la sección Mantenimiento, con credenciales, permisos, almacenamiento privado y
confirmaciones independientes. No se activan al conectar el inventario.

## Documentación y pruebas

Toda la documentación vigente comienza en [docs/README.md](docs/README.md).
Consulta [instalación](docs/installation-from-zero.txt),
[contexto de desarrollo](docs/development-context.txt),
[pruebas](docs/testing.txt) y [verificación actual](docs/verification.txt).
Los documentos históricos están en Git, fuera de las guías activas.

Los ZIP tienen orden, timestamps y permisos reproducibles. `dist/` y `.local/`
están ignorados: no guardar en Git secretos, inventarios, instalaciones ni dumps.
