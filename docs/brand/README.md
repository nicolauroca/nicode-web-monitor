# Marca aplicada a Nicode Web Monitor

Referencia vigente: [Manual Nicode Studio 1.0](MANUAL-NICODE-STUDIO.md), facilitado
por el propietario en `NICODE-STUDIO-MARCA-v1.0.zip`. Se verificaron las 41 entradas
de su manifiesto SHA256 antes de importar los recursos. Los textos del repositorio
usan finales LF; los recursos gráficos y la fuente conservan sus originales.

La petición del propietario es mantener la identidad en toda la capa visual,
sin publicidad adicional de Nicode Studio. Se conservan **Nicode Web Monitor**,
**Central** y **Conector** como nombres visibles. El manual se utiliza como
referencia de diseño; sus ejemplos y briefings no amplían el alcance funcional
ni autorizan envíos o publicaciones por sí mismos.

## Aplicación actual

- Sora variable local, con fallback Arial/Helvetica/sans-serif. Se usa el nombre
  CSS `Nicode Sora` para evitar colisiones con fuentes del sistema anfitrión.
- Acción principal `#7023DF`, hover `#5216AC`, texto `#201429`, metadatos
  `#62586B`, superficies blanca y `#F5F3F8`, divisores `#E1DCE7`.
- Borde de control `#817687`, distinto del divisor decorativo. El foco sigue
  visible por teclado. El violeta identifica acciones, no éxito o error.
- Cabecera `#1C0D29`, texto blanco y secundario `#DDD0E8`. El acento sobre
  oscuro es `#C495FF`, con texto de botón `#241034`.
- Isotipo original Enlace v6 para fondo oscuro, sin filtro, deformación o
  contenedor decorativo. 40 px en cabecera general de escritorio y 28 px en móvil
  o en la cabecera compacta de ficha, con área libre
  mínima de 25 % del ancho por lado. Alt «Nicode Studio».
- Los iconos funcionales conservan la familia de trazo existente. El isotipo
  identifica el producto en la cabecera; no sustituye los iconos de estado.
- Éxito, aviso y error mantienen verde, ámbar y rojo, acompañados de texto/icono.
  No se añade ningún enlace comercial, eslogan o atribución promocional.

El modo oscuro adapta las superficies de trabajo a `#24182F` y `#302239`, con
fondo `#160D20` y bordes `#51415E`; son aplicaciones de producto documentadas,
no nuevos colores del logotipo. La tipografía se adapta al panel: cuerpo 16 px
y tamaños compactos para tablas/metadatos. No se trasladan títulos de portada
de 40–64 px al administrador. Se conservan los recorridos y las confirmaciones.

## Código y distribución

El CSS común es `tools/brand/brand.css`. Los logos SVG/PNG originales y las fuentes
WOFF2/TTF con su licencia se conservan en `tools/brand/logos` y `tools/brand/fuentes`,
para futuras pantallas y soportes. `tokens-reference.css` conserva los tokens del
manual solo como referencia: su `:root` no se carga en Joomla.

`tools/build.py` incluye únicamente los recursos utilizados, con una copia
independiente en `administrator/tmpl/brand/` de cada componente, junto con
`Sora-variable.woff2`, `OFL-Sora.txt` e `isotipo-dark.svg`. `provenance.json`
registra la procedencia y los hashes de los recursos originales importados.
La licencia OFL de la fuente se distribuye dentro de ambos paquetes.

Los estilos solo alcanzan `.nwm-central` y `.nwm-connector`. No se introduce
un `:root` global con la paleta ni se aplica Sora al administrador completo.
Fuentes e imagen se sirven desde la instalación, sin CDN, Google Fonts,
telemetría o descargas en tiempo de ejecución. Ningún paquete depende de que
esté instalado el otro para mostrar su identidad.

Actualmente los endpoints públicos devuelven JSON y los avisos automáticos son
texto plano: no hay una interfaz pública HTML que recolorear. Las futuras vistas
HTML de front o back y los correos con diseño deberán reutilizar esta referencia,
respetando el contexto y las limitaciones de cada medio. No añadir logos al JSON.

## Mantenimiento y comprobación

Para una nueva pantalla, consultar primero este documento y el manual. Añadir
tokens por función y evitar nuevos colores aislados. Una nueva identidad de
producto requiere una decisión explícita del propietario.

La revisión visual cubre Central, Conector, estados de conexión, permisos,
inventario y mantenimiento, claro/oscuro y 1440/390/320 px. El navegador comprueba
que Sora y el SVG se cargan realmente desde el sitio y que los pares principales
de texto, acciones y estados alcanzan 4,5:1. Esto no constituye una auditoría WCAG
integral. `verification.txt` recoge únicamente la evidencia de la entrega actual.
