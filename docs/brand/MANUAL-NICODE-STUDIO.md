# Nicode Studio — Manual transversal de identidad

Versión 1.0 · 27 de noviembre de 2026 · Responsable de marca: Nicolau Roca.

Este documento sirve como briefing para cualquier agente, diseñador, desarrollador o empresa que cree productos, interfaces, documentos, presentaciones, correos, campañas o piezas audiovisuales en nombre de Nicode Studio. Entregar preferentemente junto al ZIP de recursos de esta misma versión. El Markdown contiene las reglas; los logos y las fuentes originales están en el paquete.

## 1. Instrucción de uso

Antes de diseñar, lee este manual y utiliza sus archivos originales. Conserva identidad, tipografía, jerarquía, lenguaje y criterios de calidad; adapta la composición al medio. La coherencia no exige que un panel de administración, una diapositiva y un correo tengan la misma distribución.

La identidad **Enlace v6** fue aprobada por el propietario el 10/10/2026, petición 697010020. Este manual documenta esa identidad y la dirección visual de la web local vigente, y fija criterios de aplicación transversal para el encargo actual. Las escalas orientativas para nuevos soportes son convenciones de este manual, no aprobaciones históricas independientes. No rediseñar el logo ni rescatar propuestas anteriores.

Una instrucción posterior explícita del propietario prevalece. Si un encargo exige una excepción, identificarla y conservarla en la entrega; no convertirla en una nueva identidad global. Este manual no autoriza publicar, enviar campañas ni cambiar productos existentes por sí solo.

## 2. Marca y alcance

**Nombre en texto:** Nicode Studio. **Marca gráfica:** nicode studio, en minúsculas, tal como aparece trazada en los SVG. No reconstruir el logotipo escribiendo el nombre con una fuente.

Nicode Studio es la marca de productos digitales y desarrollo a medida de Nicolau Roca. Su actividad actual incluye extensiones y soluciones para Joomla; su identidad debe admitir otros productos y tecnologías. Joomla es una especialidad, no una parte inseparable del nombre ni del símbolo.

Descripción transversal utilizable: «Productos digitales y desarrollo a medida». Para material específicamente Joomla: «Extensiones y desarrollo a medida para Joomla». Son descriptores contextuales, no elementos obligatorios del logotipo ni promesas de catálogo disponible.

Personalidad: precisión técnica, claridad, criterio, cuidado visual y utilidad. Mostrar qué hace una solución, para quién, cómo se utiliza y cuáles son sus límites. La calidad se demuestra con producto, explicaciones y pruebas reales.

Nicode Studio y Nicolau Roca son identidades relacionadas pero distintas. No incorporar automáticamente el isotipo personal, las portadas del blog ni las reglas de otras marcas. «Un estudio de Nicolau Roca» puede emplearse como atribución secundaria cuando sea pertinente.

## 3. Arquitectura de productos

- Marca principal: Nicode Studio.
- Productos: conservar su nombre establecido, por ejemplo Nicode Form Studio o Nicode Web Monitor. No renombrarlos para adaptar una pieza.
- En un producto nuevo, presentar el nombre y una atribución discreta a Nicode Studio. La especialidad o categoría puede variar sin cambiar la identidad principal.
- En trabajos para clientes, respetar su identidad y el acuerdo de atribución. Una marca «por Nicode Studio» no autoriza sustituir su diseño ni divulgar el proyecto.
- No inventar submarcas, logos o paletas por cada función. Una identidad diferenciada de producto requiere una decisión específica, documentada.
- Identificadores documentales técnicos: `Nicode.<ámbito>.<producto>[.<componente>]`, segmentos descriptivos en minúsculas tras Nicode; conservar los históricos. No son nombres comerciales ni obligan a cambiar namespaces, slugs o paquetes.

## 4. Logotipo y símbolo

Enlace combina la **n minúscula original con dos bandas plegadas enlazadas**. No lleva círculo decorativo. Las bandas, los pliegues, la letra y sus proporciones forman una unidad.

| Recurso del paquete | Uso |
| --- | --- |
| `logos/imagotipo-light.svg` | Símbolo y nombre sobre fondo claro; opción principal |
| `logos/imagotipo-dark.svg` | Símbolo y nombre sobre fondo oscuro |
| `logos/vertical-light.svg`, `vertical-dark.svg` | Composición apilada para espacios estrechos |
| `logos/logotipo-light.svg`, `logotipo-dark.svg` | Nombre sin símbolo cuando el contexto ya identifica la marca |
| `logos/isotipo-light.svg`, `isotipo-dark.svg` | Símbolo solo: avatar, producto o espacio compacto |
| Variantes `*-black.svg`, `*-white.svg` | Una tinta o producción que no admita color |
| `logos/avatar.svg`, `logos/avatar.png` | Perfil cuadrado |
| `logos/favicon.svg`, `logos/favicon.png` | Tamaños mínimos; dibujo simplificado |

`light` significa destinado a fondo claro; `dark`, a fondo oscuro. No invertir esta convención. Cada SVG tiene también una versión PNG para aplicaciones que no admitan vector.

Área libre mínima: **25 % del ancho del isotipo**, alrededor del conjunto. Si el símbolo mide 40 px de ancho, dejar al menos 10 px libres por cada lado del conjunto. Aplicar la misma relación en impresión. El margen queda fuera del dibujo y no debe recortarse al exportar.

Isotipo desde **24 px**; para **16 px**, utilizar el favicon simplificado. En composiciones con texto, comprobar que «studio» siga legible al tamaño final; si no lo es, ampliar o usar el símbolo donde el contexto lo permita. El uso de 174 × 46 px del imagotipo en la web es una referencia de cabecera, no un mínimo universal.

No deformar, girar, recortar bandas, cambiar la n, añadir contornos/sombras, recolorear ni generar el logo con IA. No usar un fondo que oculte los pliegues. En fotografía, elegir un área limpia o colocar la marca sobre una superficie uniforme con margen. La monocromía ya está resuelta en los archivos: no improvisar filtros.

## 5. Color: identidad y aplicaciones

Trabajar en sRGB para pantallas. Los colores del dibujo son inmutables en los archivos maestros. Los colores de interfaz responden a funciones distintas: no sustituir uno por otro solo por ser violetas.

| Capa / función | HEX | Aplicación |
| --- | --- | --- |
| Símbolo: violeta | `#7828F5` | Banda principal del logo |
| Símbolo: lavanda | `#B88CFF` | Segunda banda |
| Símbolo: pliegue | `#5603AD` | Planos de profundidad |
| Símbolo: n oscura | `#070708` | Letra en versión clara |
| Fondo principal | `#FFFFFF` | Lectura, documentos, interfaz |
| Superficie secundaria | `#F5F3F8` | Agrupaciones suaves |
| Texto principal | `#201429` | Títulos y cuerpo |
| Texto secundario | `#62586B` | Ayudas y metadatos |
| Divisor suave | `#E1DCE7` | Separadores; no basta para todo control |
| Acción principal sobre claro | `#7023DF` | Botón con texto blanco |
| Acción: hover | `#5216AC` | Estado de interacción |
| Acento editorial sobre claro | `#7628EB` | Énfasis breve, no párrafos completos |
| Fondo oscuro destacado | `#1C0D29` | Aperturas y portadas |
| Acento sobre oscuro | `#C495FF` | Énfasis y botón con texto `#241034` |
| Texto claro | `#FFFFFF` | Texto sobre fondo oscuro |
| Texto secundario sobre oscuro | `#DDD0E8` | Entradillas |
| Borde de control sobre claro | `#817687` | Controles que necesitan delimitarse |

La web carga `store.css` y después `studio.css`: para la apariencia vigente prevalecen las reglas posteriores. Los neutros antiguos como `#FAF9F6` no son una segunda paleta que deba alternarse arbitrariamente en piezas nuevas.

Regla de composición: fondos neutros amplios, texto oscuro y violeta reservado a énfasis/acción. Las aperturas oscuras son un recurso expresivo; los formularios, tablas y textos largos necesitan superficies tranquilas. No imponer porcentajes de color por cuota.

Estados de éxito, aviso y error deben distinguirse mediante texto e icono además de color. En extensiones integradas, utilizar los estados nativos del entorno. No usar el violeta corporativo como sinónimo de error.

Para impresión, convertir con el perfil de la imprenta y revisar una prueba. No inventar equivalencias Pantone o CMYK universales: no se ha definido una tinta plana corporativa.

## 6. Tipografía

**Sora** es la familia principal. Se entregan el TTF variable, el WOFF2 y su licencia OFL. Usar los archivos reales. Los logos ya tienen sus letras trazadas y no necesitan instalar la fuente.

| Uso | Criterio de aplicación |
| --- | --- |
| Título | Sora 600–650; jerarquía clara y frase breve |
| Subtítulo | Sora 600 |
| Texto | Sora 400, interlineado aproximadamente 1,5–1,7 |
| Etiquetas y botones | Sora 500–600 |
| Código | Fuente monoespaciada del entorno; conservar Sora alrededor |
| Fallback sin soporte | Arial, Helvetica, sans-serif |

Si una aplicación solo permite pesos estáticos, usar 600 para títulos y 400 para texto. No simular otro estilo de logo. Evitar mayúsculas continuas, espaciado excesivo y títulos comprimidos para hacerlos caber.

Escala orientativa para nuevas interfaces: cuerpo 16 px, ayuda 14 px, subtítulo 20–24 px, sección 28–40 px, título principal 40–64 px adaptado al ancho. La portada web puede ser más expresiva; sus títulos grandes y tracking cerrado no se trasladan a tablas, correos o manuales.

Documentos A4: cuerpo 10,5–12 pt, interlineado mínimo cómodo, márgenes 18–24 mm. Presentaciones: cuerpo preferentemente desde 24 pt, títulos 36–48 pt; reducir contenido antes que tamaño. Estas medidas son puntos de partida, revisables según soporte y distancia real.

## 7. Composición y lenguaje visual

Usar alineación a la izquierda, espacio libre, bloques ordenados y jerarquía evidente. Una idea principal por pantalla o diapositiva. El ritmo combina superficies blancas con secciones oscuras y acentos lavanda.

Escala práctica de espaciado: 4, 8, 12, 16, 24, 32, 48 y 64 unidades. Ajustar ópticamente cuando sea necesario. Botones con radio aproximado de 6 px; tarjetas 8–12 px; bordes finos y sombras suaves solo para explicar elevación. Evitar convertir cada párrafo en una tarjeta.

Motivos de marca: bandas, pliegues, conexiones, módulos que encajan y profundidad suave. Pueden inspirar ilustraciones, diagramas y transiciones, sin duplicar ni modificar el símbolo. Evitar decoración tecnológica genérica, lluvia de código, neones y diagramas que no expliquen nada.

Iconos simples de trazo consistente, sin mezclar familias. La web utiliza habitualmente trazo 1,6 sobre caja 24. Cada icono funcional necesita un nombre comprensible; la silueta no debe ser la única explicación.

Capturas: datos ficticios claramente reconocibles cuando se trate de una demostración, sin credenciales ni datos de clientes. Separar capturas reales de ilustraciones conceptuales. No presentar un mockup como funcionalidad entregada. Usar imágenes y música propias o con derechos documentados.

Movimiento breve y funcional: orientar cambios, explicar relaciones o dar continuidad. Respetar reducción de movimiento y ofrecer contenido accesible sin animación. Ninguna información depende exclusivamente de hover, scroll animado o reproducción automática.

## 8. Aplicación según el soporte

### Productos, componentes y plugins

Mantener nombres, iconos, tono y acentos corporativos. En administración Joomla u otro sistema anfitrión, respetar navegación, formularios, foco, tablas y convenciones nativas. No recolorear globalmente el administrador ni imponer Sora a toda la aplicación anfitriona. Aplicar estilos dentro del ámbito del componente.

Pantallas iniciales: propósito, siguiente acción y ayuda. Estados vacíos: explicar qué falta y cómo empezar. Errores: qué ha ocurrido, qué se conserva y qué puede hacer la persona. No sustituir una explicación por «Error desconocido» si existe una causa útil. Mantener legibilidad en modo claro/oscuro sin confiar en la inversión automática del logo.

### Webs y páginas de producto

Orden de partida: necesidad → solución → funcionamiento/evidencia → límites y disponibilidad → siguiente paso. Una acción principal por bloque. Separar producto disponible, prueba, desarrollo y concepto. No crear testimonios, precios, compatibilidades o cifras para completar el diseño.

### Presentaciones y propuestas

Formato habitual 16:9. Portada clara u oscura con título, marca y contexto. Interiores claros; separadores oscuros cuando ayuden al relato. Estructura: problema, propuesta, ejemplo o evidencia, alcance, siguiente paso. Diagramas con leyenda y datos fechados. Los presupuestos distinguen alcance, entregables, exclusiones y condiciones reales.

### Documentación y entregables

Priorizar lectura: título, propósito, requisitos, recorrido, ejemplos, comprobaciones y resolución de problemas. Mostrar versiones relevantes; mantener código seleccionable y copiable. El logo puede aparecer en portada y cabecera, sin repetirse como marca de agua. En PDF, conservar texto, enlaces y estructura accesibles cuando el exportador lo permita.

### Correos y mailing

Diseño de una columna, ancho máximo orientativo 600–640 px; fondo blanco, texto oscuro, acento violeta y logo horizontal PNG. Cuerpo desde 16 px con fallback Arial/Helvetica. Sora solo si el cliente la admite; el mensaje debe conservar legibilidad sin ella y con imágenes bloqueadas.

Título claro, introducción breve, contenido útil y una acción principal. No enviar el mensaje entero como imagen. Botón con texto y enlace real, alternativa de texto plano y alt «Nicode Studio» para la marca. Comprobar modo oscuro. Usar CSS sencillo compatible con el canal; no depender de scripts, SVG embebidos o animaciones para comunicar.

Firma: nombre real del remitente y Nicode Studio; añadir únicamente contacto y enlaces confirmados. Si envía Cooper, identificar a Cooper como encargado por Nicolau Roca, sin suplantarlo. No inventar dirección postal, correo, dominio ni avisos legales. El diseño de mailing no implica permiso para enviarlo ni altera límites comerciales vigentes.

### Redes, vídeo y nuevos formatos

Mantener Sora, paleta y marca original. Adaptar composición a la relación de aspecto, zonas de interfaz y tamaño real de lectura. Subtítulos legibles, información importante lejos de bordes y cierre breve. No convertir la marca en una animación que deforme su geometría. Seleccionar dimensiones según el destino concreto; no existe una plantilla universal para todas las plataformas.

## 9. Voz y escritura

Hablar de tú, con tono profesional, directo y cercano. Explicar beneficios concretos y después el mecanismo o la evidencia. Conservar términos técnicos necesarios y aclararlos según el público; no infantilizar al profesional.

Preferir: «Consulta los envíos y exporta los resultados» frente a «Revoluciona tu productividad con una solución disruptiva». Preferir «No se ha podido guardar. Revisa la conexión y vuelve a intentarlo» cuando esa causa esté comprobada, frente a atribuir cualquier fallo a la red.

Botones con verbos y destino: «Ver documentación», «Descargar paquete» cuando exista y esté disponible, «Consultar mi proyecto». Evitar varios botones indistinguibles con «Más». Español cuidado; en inglés conservar el mismo tono, no traducción literal forzada. Nombres comerciales y etiquetas coherentes en todas las pantallas.

No inventar clientes, experiencia, métricas, disponibilidad, certificaciones ni alianzas con Joomla u otras marcas. Cualquier promesa técnica o comercial debe estar respaldada por el estado real de la entrega.

## 10. Legibilidad y control de calidad

Objetivos de diseño de este manual: contraste mínimo 4,5:1 en texto habitual y 3:1 en texto grande; foco e indicadores de control distinguibles, interacción por teclado, texto ampliable y diseño sin pérdidas en móvil. Comprobar las combinaciones concretas, incluidos hover, desactivado y error; no afirmar conformidad integral por usar esta paleta.

Los enlaces dentro del texto se distinguen también mediante subrayado u otra señal persistente. Imágenes informativas con alt específico; decorativas con alt vacío. No meter texto esencial en imágenes. Las áreas táctiles deben tener tamaño y separación cómodos; referencia práctica de 44 px cuando el entorno lo permita.

Antes de entregar:

1. Nombre y recursos correctos; logo sin deformación, margen y variante adecuados.
2. Sora real o fallback previsto; texto legible al tamaño de uso.
3. Colores por función, contraste y jerarquía revisados.
4. Contenido, datos, versiones y enlaces verificados; ningún placeholder accidental.
5. Navegación y acciones comprensibles; pruebas en los soportes relevantes.
6. Derechos y atribuciones conservados; sin datos privados ni enlaces a repositorios privados en material público.
7. Archivo editable, exportación final y lista de dependencias entregados.
8. Distinguir preparado, revisado y publicado. Registrar excepciones concretas.

## 11. Brief reutilizable para agentes o proveedores

> Crea [entregable] para [público] con el objetivo [objetivo] y destino [canal/formato]. Aplica MANUAL-NICODE-STUDIO.md v1.0 y los recursos adjuntos. Conserva Enlace v6, Sora y la paleta por funciones. Adapta la composición al medio y respeta el sistema anfitrión si existe. Utiliza únicamente datos y enlaces confirmados; señala lo que falte. Entrega fuente editable, exportación utilizable y comprobaciones relevantes. No rediseñes la marca ni interpretes este briefing como autorización de publicación o envío.

## 12. Paquete, versiones y procedencia

El paquete incluye este manual, `logos/` (SVG/PNG originales), `fuentes/` (TTF, WOFF2 y OFL), `tokens.css` para reutilizar colores y `MANIFIESTO-SHA256.json` para comprobar integridad. Los tokens son un punto de partida; no sustituyen una plantilla completa ni el CSS nativo del entorno.

Fuentes internas contrastadas el 27/11/2026: aprobación 697010020; recursos `repositorio/assets/brand-v6/`; página `repositorio/identidad.html`; estilos `repositorio/assets/storefront/store.css` y `studio.css`. No se incluyen conversaciones, credenciales, código privado de productos ni configuraciones de acceso en el paquete externo.

Para colaboradores externos, entregar el ZIP completo. El MD puede utilizarse solo para briefing textual, pero un agente no debe reconstruir los logos porque no tenga los archivos. Mantener este archivo como referencia canónica; actualizar la versión y el manifiesto al cambiar reglas o recursos y conservar las versiones entregadas.
