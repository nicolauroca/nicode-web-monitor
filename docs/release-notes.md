# Nicode Web Monitor 1.1.1-dev.3 — Desarrollo

**Prerelease de desarrollo. No es una versión definitiva, estable ni una beta final.**

Esta entrega rediseña la interfaz de **Nicode Web Monitor — Conector** para hacer
más claros la vinculación, el estado y el siguiente paso. La lógica de conexión,
revocación, permisos, credenciales y transportes se conserva.

- Cabecera de producto, panel principal y ayuda contextual con jerarquía visual.
- Estados propios para sin conexión, pendiente, conectado y acceso desactivado.
- Central vinculada, método local/HTTPS y fecha de vinculación expresados claramente.
- Instrucciones adaptadas al estado y desconexión en una zona de gestión separada.
- Atajo para utilizar el Central de este Joomla, Mostrar/Ocultar código, ayuda de
  validación y feedback durante el envío. Los formularios nativos funcionan sin JS.
- Diseño adaptable a móvil, tema oscuro, foco visible y controles de teclado.
- Recursos incluidos en el paquete, sin fuentes o imágenes de terceros.

## Instalar

En el Central: **pkg_nicodemonitor-1.1.1-dev.3.zip**.
En cada sitio: **pkg_nicodeconnector-1.1.1-dev.3.zip**.
Si el Central también se supervisa, instalar ambos en ese mismo Joomla.
Actualizar los dos ZIP completos para mantener coherentes las versiones de las
piezas. No desinstalar ni borrar tablas. «Source code» no es un instalador Joomla.

El rediseño corresponde al Conector; el Central conserva su interfaz y sus funciones.
Se mantiene la reparación de esquema del instalador y la automonitorización local.
Los detalles y las pruebas vigentes están en docs/connector-ui.txt y docs/verification.txt.
La validación del propietario en su hosting sigue pendiente. Publicar una release
no implica aceptación para producción.
