# Acciones desde Central: catálogo y diseño

Revisión: 27 de noviembre de 2026. Nicode Web Monitor **1.1.1-dev.10**, desarrollo previo a beta.

## Qué ofrecen las referencias

[mySites.guru](https://mysites.guru/features/) ofrece actualizaciones de core y extensiones, instalación, copias, gestión de usuarios y acceso administrativo. Su [flujo de actualización](https://mysites.guru/blog/how-to-mass-upgrade-joomla-and-wordpress-sites-from-one-dashboard/) distingue core de extensiones e incorpora selección, cola por servidor, copia previa, pausa y resultados. Son prestaciones declaradas por el proveedor, no ensayos propios.

[Watchful](https://watchful.net/watchful-features/) documenta actualizaciones individuales y por lotes, copias, programación, instalación, registros e informes. [Akeeba Panopticon](https://www.akeeba.com/products/panopticon.html) permite actualizar Joomla y extensiones manual o automáticamente, con preferencias por sitio/extensión e integración de copias con Akeeba Backup Professional. Esta integración concreta necesita ese producto adicional; no se supone que todos los proveedores de copia estén disponibles.

Estas referencias justifican prioridades de producto. No se les atribuye edición arbitraria de configuración Joomla ni se afirma paridad con Nicode.

## Catálogo propuesto

| Acción desde Central | Estado actual de Nicode | Prioridad / condición para ampliarla |
| --- | --- | --- |
| Recoger inventario, ver diagnóstico e historial | Disponible, con permisos locales de consulta | Mantener lectura separada de las escrituras |
| Buscar nuevas actualizaciones en los proveedores del sitio | Solo se lee la caché existente | P1: permiso propio, llamada explícita y límites; recoger inventario no debe refrescar proveedores silenciosamente |
| Actualizar plugins, módulos, componentes, plantillas y librerías | Motor experimental para paquetes preparados localmente; confirmación en Central | P1: elegir extensión/versiones en UI y automatizar preparación controlada desde proveedores registrados del propio sitio |
| Actualizar paquetes con varios hijos | Motor experimental; conjunto completo validado | P1: presentar el padre y todos los hijos afectados, dependencias y posibles efectos parciales |
| Actualizar Joomla | Motor experimental: preparación local, cola, ejecutor CLI y verificación independiente | P1: asistente guiado y estado verificable del ejecutor; después programación y lotes |
| Crear una copia y consultar su resultado | Captura experimental por pasos; restauración no acreditada | P1: integración de proveedor, copia previa a actualizar, antigüedad y comprobación de recuperación |
| Limpiar caché seleccionada | No implementado | P1: API nativa acotada a grupos permitidos; no aceptar rutas ni incluir sesiones |
| Activar/desactivar modo offline | No implementado | P1: permiso específico, alcance visible, recuperación y acceso local; offline no detiene cron ni otros escritores |
| Cambiar caché, duración, compresión o depuración | Solo consulta de los campos compartidos | P1: allowlist de ajustes, tipos y valores validados; lectura actual y comparación antes/después |
| Cambiar SEO, robots, URLs o forzar HTTPS | Solo consulta de parte de la configuración | P2: flujo propio por efectos sobre indexación, redirecciones, proxy y acceso; no activar sin poder comprobar las precondiciones |
| Activar/desactivar una extensión | No implementado | P2: proteger core, autenticación, Conector y dependencias; no usar como atajo para desconectar el sitio |
| Ejecutar o pausar tareas del sitio | Solo inventario y ejecución del monitor propio de Central | P2: permiso propio, tipos admitidos y efectos explicados; no desbloquear tareas inciertas a la fuerza |
| Programar actualizaciones y ejecutar por lotes | No implementado para escrituras | P2: primero estabilizar cada acción individual, exclusión por sitio/servidor, pausa, política de fallo y resultado por sitio |
| Instalar una extensión nueva | No es una acción de usuario disponible | P2: origen permitido, identidad/manifiesto revisados y alcance; nunca una URL o ZIP remoto arbitrario enviado a un ejecutor genérico |
| Usuarios, bloqueo y contraseñas | Solo cifras agregadas | P3: consentimiento nuevo para datos personales y permisos por operación; proteger al último administrador y no eludir MFA |
| Acceso administrativo delegado | Enlaces a herramientas locales cuando corresponde; no SSO remoto | P3: autorización específica, sesión de un uso, caducidad y auditoría; no derivarlo del permiso de inventario |
| Restaurar una copia | No disponible como acción remota de usuario | P3: identidad del punto, restauración ensayada y recuperación externa; puede sustituir tanto archivos como base de datos |
| Editar archivos, PHP, SQL o configuración completa | No disponible | Fuera del primer alcance: no introducir consola remota ni escritura arbitraria para facilitar otras funciones |

P1, P2 y P3 son orden de desarrollo, no funciones publicadas ni compromisos de fecha. La edición de configuración de la tabla es una propuesta de Nicode, no una prestación atribuida a todos los competidores.

## Permisos que necesita cada acción nueva

El Conector debe decidir independientemente qué permite leer y qué permite modificar. Permitir la categoría Configuración del inventario no autoriza editarla. Permitir Actualizaciones no autoriza instalar. Las capacidades de mantenimiento mantienen credenciales y permisos separados; no reutilizar el token de inventario.

Para nuevas escrituras: definir una acción concreta y sus campos permitidos, no una API de claves arbitrarias. El sitio valida autorización, versión de lo observado, tipos, rangos y condiciones locales. Si otro administrador cambió la configuración desde que se abrió el formulario, se rechaza el plan y se pide revisar los valores actuales. Los valores sensibles y las claves de descarga no vuelven a Central en respuestas, informes o mensajes.

La primera configuración remota no debe incluir conexión de base de datos, secret, rutas de logs/temporales, permisos de archivos o manejador de sesiones. Esos cambios pueden dejar la aplicación inaccesible o afectar a la coherencia de una captura. Cambios de HTTPS/SEO necesitan su flujo particular y comprobaciones del entorno.

## Flujo objetivo de una actualización guiada

1. Seleccionar sitio y extensión, o entrar al actualizador Joomla separado.
2. Consultar ofertas recientes y presentar versión instalada, destino, origen y cambios relevantes.
3. Preparar un plan en el sitio: validar identidad, licencia local, paquete, integridad, dependencias y compatibilidad. Central no proporciona URLs arbitrarias al ejecutor.
4. Mostrar comprobaciones reales: acceso, ejecutor disponible, recuperación, operaciones incompatibles y ventana necesaria. Un desconocido no se muestra como correcto.
5. Confirmar una pantalla breve con sitio, elementos afectados, antes/después y consecuencias. La preparación por sí sola no ejecuta.
6. Ejecutar una sola vez, guardar incertidumbre antes de efectos y verificar con observaciones nuevas.
7. Mostrar resultado por elemento. Si se pierde la respuesta, ofrecer Consultar estado; no reintentar instalación a ciegas.

El objetivo es que el usuario seleccione qué actualizar. El recorrido experimental actual sigue necesitando al operador local para preparar el paquete y su referencia. La nueva organización visual no sustituye esa preparación ni constituye una actualización automática desde los proveedores.

## Diseño de la interfaz

Sitios → ficha → Mantenimiento → Copias / Extensiones / Joomla. La entrada muestra tarjetas cortas con el propósito y el siguiente paso. Se renderiza una herramienta por URL; hay migas y enlace para regresar. Configuración y otras acciones futuras no aparecen como botones que todavía no funcionen.

Dentro de una herramienta:

- Una frase de alcance y el estado de credencial registrada en Central. Esto no acredita autorización remota ni preparación del paquete.
- Preparación, operaciones y resultado separados. Las credenciales están bajo Configurar acceso avanzado y se abren inicialmente si faltan. No se imprimen valores guardados.
- Una operación abierta inicialmente y el resto plegadas, con estado y fecha visibles en el encabezado; el historial técnico queda desplegable.
- Ayuda `(i)` mediante un control nativo de teclado y táctil con título comprensible. No depende de hover, JS ni texto solo en un tooltip.
- Riesgo esencial visible junto a la acción y antes de confirmar. Los detalles ampliables explican el motivo y la preparación, sin sustituir el aviso.
- Consentimiento nuevo para cada ejecución de riesgo. No recordar “no volver a preguntar” para instalar, sustituir datos, usuarios o permisos.

Ejemplo objetivo de confirmación: «Actualizar Formulario 4.1 → 4.2 en tienda.example». Mostrar el punto de recuperación realmente acreditado y las condiciones pendientes. Aviso breve: «Ejecuta código de instalación y puede dejar cambios parciales». Botones Cancelar y Confirmar actualización. Un cambio de configuración muestra valores antes/después; un lote muestra los sitios y elementos seleccionados.

La UI actual conserva formularios nativos, CSRF y confirmación de los motores existentes. No reduce sus precondiciones, credenciales, bloqueo compartido ni reconciliación. Un resultado incierto muestra una advertencia visible y conserva consulta de estado sin un botón de ejecución ciega.

## El propio Joomla Central

Puede seguir siendo un sitio monitorizado: identidad y propietarios independientes. Su conexión local proporciona inventario; no configura un transporte remoto de escritura. La pantalla lo explica y ofrece los actualizadores nativos del propio Joomla cuando la cuenta tiene permisos. No ofrece crear/ejecutar operaciones remotas desde ese registro, aunque mantiene consulta e historial de registros existentes.

La orquestación futura de mantenimiento del propio Central necesita un ejecutor independiente que sobreviva a la actualización y compruebe el resultado en un proceso nuevo. No resolverlo haciendo peticiones HTTP a sí mismo ni suponiendo que offline detiene todos los procesos.
