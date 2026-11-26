<?php
/** @license GPL-2.0-or-later — every remote value remains escaped plain text. */
defined('_JEXEC') or die;
$inspectedLabel='Sitio #'.(int)($inspectedId??0);
foreach($sites as $registeredSite){if((int)$registeredSite['id']===(int)($inspectedId??0)){$inspectedLabel=$registeredSite['label'];break;}}
$inventory=$inspection['inventory']??[];
$sections=is_array($inventory['sections']??null)?$inventory['sections']:[];
$sectionNames=['joomla'=>'Joomla','runtime'=>'Entorno PHP','database'=>'Base de datos','extensions'=>'Extensiones','hosting'=>'Hosting'];
$availability=['available'=>'Disponible','unavailable'=>'No disponible','error'=>'Error de lectura'];
$unknown='No disponible';
$value=static function($section,$key)use($sections,$unknown){$part=$sections[$section]??[];$v=$part['data'][$key]??null;return ($part['status']??'')==='available'&&is_scalar($v)?(string)$v:$unknown;};
?>
<section id="nwm-inventory" class="nwm-panel nwm-inventory" aria-label="Resultado de inventario" tabindex="-1">
<div class="nwm-section-heading"><div><p class="nwm-section-label">Resultado de la comprobación</p><h2>Inventario del sitio</h2><p class="nwm-muted"><?= $escape($inspectedLabel) ?></p></div><a href="#nwm-sites" class="nwm-button nwm-button--quiet">Volver a sitios</a></div>
<?php if(($inspection['history_saved']??true)===false): ?><p class="nwm-notice nwm-notice--warning" role="alert">No se ha podido guardar esta comprobación en el historial.</p><?php endif; ?>
<?php if(!$inspection['ok']): $error=$inspection['error']??'unknown'; ?>
<div class="nwm-notice nwm-notice--danger" role="alert"><h3>No se ha obtenido el inventario</h3><p><?= $escape($detailLabels[$error]??'Revisa la conexión y los permisos del Conector antes de volver a comprobar.') ?></p><p class="nwm-field-help">Referencia técnica: <code><?= $escape($error) ?></code></p></div>
<?php else: ?>
<div class="nwm-inventory-meta"><span class="nwm-badge nwm-badge--<?= ($inventory['status']??'')==='complete'?'success':'warning' ?>"><?= ($inventory['status']??'')==='complete'?'Inventario completo':'Inventario parcial' ?></span><span>Recopilado <strong><?= $escape($date($inventory['collected_at']??null)) ?> UTC</strong></span><span>Recibido <strong><?= $escape($date($inspection['received_at']??null)) ?> UTC</strong></span></div>
<h3>Software y entorno</h3><dl class="nwm-software-grid">
<?php foreach([['Joomla','joomla','version'],['PHP','runtime','php_version'],['Ejecución PHP','runtime','sapi'],['Sistema operativo','runtime','os_family'],['Base de datos','database','version']] as [$label,$section,$key]): ?><div><dt><?= $label ?></dt><dd><?= $escape($value($section,$key)) ?></dd></div><?php endforeach; ?></dl>
<details class="nwm-subdetails"><summary>Cobertura del inventario</summary><p>Un dato no disponible no equivale a ausencia del software. Cada sección indica lo que el Conector ha podido consultar.</p><dl class="nwm-coverage"><?php foreach($sectionNames as $key=>$label): ?><div><dt><?= $label ?></dt><dd><?= $escape($availability[$sections[$key]['status']??'unavailable']??'No disponible') ?></dd></div><?php endforeach; ?></dl></details>
<div class="nwm-section-heading nwm-extension-heading"><h3>Extensiones instaladas</h3><?php if(($sections['extensions']['status']??'')==='available' && is_array($sections['extensions']['data']??null)): ?><div class="nwm-search" data-nwm-extension-filter hidden><?= $icon('search') ?><label class="nwm-visually-hidden" for="nwm-extension-search">Buscar extensiones</label><input type="search" id="nwm-extension-search" placeholder="Buscar extensiones…" autocomplete="off"></div><?php endif; ?></div>
<?php if(($sections['extensions']['status']??'')==='available' && is_array($sections['extensions']['data']??null)): ?>
<p class="nwm-field-help" data-nwm-extension-count role="status"><?= count($sections['extensions']['data']) ?> extensiones · Desplaza la tabla para consultar todos los campos.</p><div class="nwm-table-scroll" role="region" aria-label="Extensiones instaladas" tabindex="0"><table class="nwm-extension-table"><thead><tr><th scope="col">Nombre</th><th scope="col">Tipo</th><th scope="col">Versión</th><th scope="col">Activada</th><th scope="col">ID</th><th scope="col">Paquete</th></tr></thead><tbody>
<?php foreach($sections['extensions']['data'] as $extension): if(!is_array($extension))$extension=[];$version=$extension['version']??null; ?>
<tr data-nwm-extension><th scope="row"><?= $escape($extension['name']??$unknown) ?></th><td><?= $escape($extension['type']??$unknown) ?></td><td><?= $escape(is_array($version)&&($version['status']??'')==='available'?($version['value']??$unknown):$unknown) ?></td><td><?= ($extension['enabled']??null)===true?'Sí':(($extension['enabled']??null)===false?'No':$unknown) ?></td><td><?= $escape($extension['id']??$unknown) ?></td><td><?= ($extension['package_id']??null)===0?'Ninguno':$escape($extension['package_id']??$unknown) ?></td></tr>
<?php endforeach; ?></tbody></table></div><p class="nwm-field-help" data-nwm-no-extensions hidden>No hay extensiones que coincidan con la búsqueda.</p>
<?php else: ?><p class="nwm-notice">Inventario de extensiones no disponible.</p><?php endif; ?>
<?php endif; ?></section>
