# Nicode Web Monitor 1.1.1-dev.2 — Desarrollo

**Prerelease de desarrollo. No es una versión definitiva, estable ni una beta final.**
La compatibilidad con versiones anteriores de desarrollo no se garantiza.

**Corrección de esta revisión:** resuelve `1054 Unknown column 'connection_type'`
al abrir Central cuando una tabla conservada no contiene la columna aunque Joomla
considere actualizado el esquema. El instalador comprueba la tabla real y añade
la columna una sola vez, preservando los sitios, secretos cifrados e historial.
Se ha reproducido y probado con marcadores de esquema actual, anterior y ausente.

Esta entrega simplifica la instalación y corrige la vinculación cuando el central
y el sitio supervisado son el mismo Joomla. Las dos interfaces forman parte de
**Nicode Web Monitor**: **Central** y **Conector**.

- Dos paquetes Joomla completos con los componentes y plugins necesarios.
- Vinculación con código temporal; identidad, credenciales e IP remota automáticas.
- El propio central usa inventario local, sin DNS ni peticiones HTTP a sí mismo,
  también en las comprobaciones periódicas. Mantiene identidad y revocación.
- Diagnósticos específicos para motores antiguos, versiones mezcladas, HTTP
  401/403/404/5xx, respuestas inválidas, TLS, DNS y timeout.
- Tarea periódica preparada desde el asistente, con estado de ejecución real.
- Documentación y contexto vigentes en `docs`, sin manuales antiguos contradictorios.

## Descargar e instalar

1. En el Joomla central: **pkg_nicodemonitor-1.1.1-dev.2.zip**.
2. En cada Joomla supervisado: **pkg_nicodeconnector-1.1.1-dev.2.zip**.
3. Para supervisar el propio central, instalar **ambos ZIP en ese Joomla**.

Usar Sistema → Instalar → Extensiones. Los archivos automáticos **Source code** de
GitHub no son instaladores. No mezclar estos paquetes con plugins de otra versión.
Para corregir el error 1054, instalar ambos ZIP encima de los existentes: no hace
falta desinstalar, borrar tablas ni ejecutar SQL manualmente.

Si ya hay un intento pendiente, instalar ambos paquetes completos, abrir
**Componentes → Nicode Web Monitor — Conector** y cancelarlo. Después generar un
código nuevo en **Nicode Web Monitor — Central** y conectar usando la URL canónica
HTTPS de Joomla, sin `/administrator`. Cancelar el intento revoca el acceso anterior.

El central prepara una tarea nativa. Para intervalos regulares sin visitas hace
falta configurar cron en el hosting central; el panel muestra la ruta concreta.
Copias y actualizaciones siguen siendo capacidades experimentales independientes.

## Verificación y límites

Instalación y retirada nativas; conexión local con DNS bloqueado; conexión remota
por TLS; permisos, credenciales, revocación y recibos; regresión del central;
recurrencia del programador; pantallas de escritorio y móvil en Chrome.
Los ensayos se realizan en Joomla desechable con datos sintéticos. La conexión
remota del laboratorio utiliza una CA y destino loopback de prueba, sin cambiar
las restricciones de los ZIP publicados. Ver `docs/verification.txt` para resultados.

La validación del propietario en su hosting, su cron y el correo externo siguen
pendientes. La existencia de una release en GitHub no implica aceptación de producción.
