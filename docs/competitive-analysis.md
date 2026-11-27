# Comparativa de producto y prioridades

Revisión: 26 de noviembre de 2026. Estado de Nicode Web Monitor **1.1.1-dev.8**, desarrollo previo a beta.

Se han consultado las páginas oficiales de [mySites.guru](https://mysites.guru/features/), su [directorio y semáforos](https://mysites.guru/blog/site-information-all-in-one-place-with-mysites-guru/) y [Watchful](https://watchful.net/watchful-features/). Describen prestaciones comerciales; esta comparación no es un ensayo de sus servicios ni afirma paridad de Nicode.

| Capacidad observada | Nicode actual | Siguiente trabajo |
| --- | --- | --- |
| Directorio, versiones y prioridades | Filas compactas, soporte/versión con semáforos, filtros y diagnóstico explicado; referencias oficiales con fecha | Ordenación y paginación para carteras grandes |
| Inventario y extensiones | 13 categorías con consentimiento individual, detalle por sitio y búsqueda de extensiones | Buscador de extensiones entre sitios y comparación de cambios |
| Disponibilidad pública y certificados | La consulta autenticada detecta fallos del Conector; no es uptime ni verifica la portada | P1: sonda HTTPS separada, TTFB, expiración TLS, historial y alertas; consentimiento explícito. Un central caído necesita un observador externo |
| Rendimiento | Duración de consulta de inventario, identificada como local o remota | P1: auditoría Lighthouse/Pagespeed consentida, origen de medición, fecha, límites y tendencias. No usar tiempo del Conector como velocidad de la web |
| Seguridad | Fin de soporte, configuración compartida, permisos separados y TLS verificado | P1: fuente mantenida de avisos Joomla/extensiones, rangos afectados y fecha; después auditoría de archivos con alcance definido. No mostrar “limpio” sin escaneo |
| Disco y tareas | Espacio del sistema de archivos, tareas fallidas/bloqueadas/sin resultado | Alertas configurables e historial de capacidad; cuotas solo si el proveedor las expone |
| Copias | Captura y diario experimentales, credencial propia y confirmaciones | Integración de proveedores, programación y antigüedad; verificar restauración antes de prometer recuperación |
| Actualizaciones | Preparación, ejecución y reconciliación experimentales por capacidad | Cola por servidor, comprobaciones previas, recuperación probada y después lotes autorizados |
| Roles y actividad | ACL Joomla, credenciales por propósito y diarios de mantenimiento | Informes de actividad y equipos con alcance por cliente/sitio |
| Informes para clientes | No hay informes comerciales ni exportación general | P2: CSV y reportes fechados, con datos denegados y conservación explícitos |
| Organización por cliente/servidor | Búsqueda por nombre/URL y filtros de prioridad/consulta | P2: etiquetas, grupos y vistas guardadas |
| Acceso administrativo y archivos | Enlace a la web; no se delega sesión de administrador ni edición de archivos | P3: evaluar necesidad, permisos específicos y auditoría. No introducir acceso remoto como efecto de conectar inventario |

P1 es el siguiente bloque de monitorización; P2 organiza carteras e informes; P3 necesita una decisión de producto y seguridad. Son trabajo pendiente, no funciones publicadas ni compromisos de fecha.

La mejora buscada es explicar cada resultado y permitir actuar sobre su causa. Los colores necesitan datos recientes, fuentes mantenidas y un estado “sin evaluar”. Se preservan la navegación por niveles, la denegación desde cada Conector y las confirmaciones de mantenimiento.
