<?php
/** @license GPL-2.0-or-later — stored and remote values remain plain text. */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventorySchema;
$inspectedLabel='Sitio #'.(int)($inspectedId??0);$inspectedState=null;
foreach($sites as $registeredSite)if((int)$registeredSite['id']===(int)($inspectedId??0)){$inspectedLabel=$registeredSite['label'];$inspectedState=$registeredSite['check_state'];break;}
$inventory=$inspection['inventory']??[];$sections=is_array($inventory['sections']??null)?$inventory['sections']:[];
$categories=InventorySchema::categories();$fieldDefinitions=InventorySchema::fields();$rowDefinitions=InventorySchema::rowFields();
$availability=['available'=>['Compartido','success'],'denied'=>['No compartido','neutral'],'unavailable'=>['No disponible','neutral'],'error'=>['Error de lectura','warning']];
$unknown='No disponible';
$value=static function($section,$key)use($sections,$unknown){$part=$sections[$section]??[];$v=$part['data'][$key]??null;return ($part['status']??'')==='denied'?'No compartido':((($part['status']??'')==='available'&&is_scalar($v))?(string)$v:$unknown);};
$format=static function($value,$type,$field='')use($unknown,$date){
    if($type==='version')return is_array($value)&&($value['status']??'')==='available'&&is_scalar($value['value']??null)?(string)$value['value']:$unknown;
    if($value===null)return $unknown;
    if($type==='bool')return $value===true?'Sí':($value===false?'No':$unknown);
    if($type==='strings')return is_array($value)?implode(' · ',array_map(static fn($v)=>is_scalar($v)?(string)$v:'No disponible',$value)):$unknown;
    if($type==='date')return is_string($value)?$date($value):$unknown;
    if(str_ends_with($field,'_bytes')&&is_int($value)&&$value>=0){$units=['B','KiB','MiB','GiB','TiB'];$n=$value;$unit=0;while($n>=1024&&$unit<4){$n/=1024;$unit++;}return number_format($n,$unit?1:0,',','.').' '.$units[$unit].' ('.$value.' bytes)';}
    return is_scalar($value)?(string)$value:$unknown;
};
$shared=count(array_filter($sections,static fn($section)=>($section['status']??'denied')!=='denied'));
?>
<section id="nwm-inventory" class="nwm-panel nwm-inventory" aria-label="Resultado de inventario" tabindex="-1">
<div class="nwm-section-heading"><div><p class="nwm-section-label"><?= !empty($inspection['from_cache'])?'Última observación guardada':'Resultado de la comprobación' ?></p><h2>Inventario del sitio</h2><p class="nwm-muted"><?= $escape($inspectedLabel) ?></p></div><a href="#nwm-sites" class="nwm-button nwm-button--quiet">Volver a sitios</a></div>
<?php if(($inspection['history_saved']??true)===false): ?><p class="nwm-notice nwm-notice--warning" role="alert">No se ha podido guardar esta comprobación en el historial.</p><?php endif; ?>
<?php if(($inspection['snapshot_saved']??true)===false): ?><p class="nwm-notice nwm-notice--warning" role="alert">No se ha podido conservar este inventario. Central muestra el resultado de esta consulta, pero el detalle guardado anterior no se ha actualizado.</p><?php endif; ?>
<?php if(!$inspection['ok']): $error=$inspection['error']??'unknown'; ?>
<div class="nwm-notice nwm-notice--danger" role="alert"><h3>No se ha obtenido el inventario</h3><p><?= $escape($detailLabels[$error]??'Revisa la conexión y los permisos del Conector antes de volver a comprobar.') ?></p><p class="nwm-field-help">Referencia técnica: <code><?= $escape($error) ?></code></p></div>
<?php else: ?>
<?php if(!empty($inspection['from_cache'])): ?><div class="nwm-notice" role="status"><p>Estás viendo el último inventario recibido. Abrir este detalle no consulta el sitio. Los permisos o datos pueden haber cambiado desde esa fecha.</p><?php if(in_array($inspectedState,['check_failed','stale','clock_uncertain'],true)): ?><p><strong>La última comprobación requiere revisión: <?= $escape($states[$inspectedState][0]) ?>.</strong> Este inventario no acredita su estado actual.</p><?php endif; ?></div><?php endif; ?>
<div class="nwm-inventory-meta"><span class="nwm-badge nwm-badge--<?= ($inventory['status']??'')==='complete'?'success':'warning' ?>"><?= ($inventory['status']??'')==='complete'?'Consulta completada':'Consulta con datos parciales' ?></span><span><?= $shared ?> de <?= count($categories) ?> categorías permitidas</span><span>Recopilado <strong><?= $escape($date($inventory['collected_at']??null)) ?> UTC</strong></span><span>Recibido <strong><?= $escape($date($inspection['received_at']??null)) ?> UTC</strong></span></div>
<h3>Software y entorno</h3><dl class="nwm-software-grid"><?php foreach([['Joomla','joomla','version'],['PHP','runtime','php_version'],['Ejecución PHP','runtime','sapi'],['Sistema operativo','runtime','os_family'],['Base de datos','database','version']] as [$label,$section,$key]): ?><div><dt><?= $label ?></dt><dd><?= $escape($value($section,$key)) ?></dd></div><?php endforeach; ?></dl>
<nav class="nwm-inventory-nav" aria-label="Categorías de inventario"><?php foreach($categories as $key=>[$label]): ?><a href="#nwm-category-<?= $key ?>"><?= $escape($label) ?></a><?php endforeach; ?></nav>
<details class="nwm-subdetails"><summary>Cobertura del inventario</summary><p>No compartido expresa una decisión del sitio. No disponible o Error de lectura no equivalen a ausencia de software ni a un fallo de la web. Una consulta completada respeta las categorías denegadas.</p><dl class="nwm-coverage"><?php foreach($categories as $key=>[$label]): $status=$availability[$sections[$key]['status']??'unavailable']??$availability['unavailable']; ?><div><dt><?= $escape($label) ?></dt><dd><?= $status[0] ?></dd></div><?php endforeach; ?></dl></details>
<?php foreach($categories as $key=>[$label,$description]): $section=$sections[$key]??['status'=>'unavailable','data'=>null];$status=$availability[$section['status']]??$availability['unavailable'];$data=is_array($section['data'])?$section['data']:[]; ?>
<section id="nwm-category-<?= $key ?>" class="nwm-inventory-category" data-category="<?= $key ?>" aria-label="<?= $escape($label) ?>">
<div class="nwm-section-heading"><h3><?= $key==='extensions'?'Extensiones instaladas':$escape($label) ?></h3><span class="nwm-badge nwm-badge--<?= $status[1] ?>"><?= $status[0] ?></span></div><p class="nwm-field-help"><?= $escape($description) ?></p>
<?php if($section['status']!=='available'): ?>
<p class="nwm-category-unavailable"><?= match($section['status']){'denied'=>'El administrador de este Joomla no permite compartir esta categoría. No se ha consultado ni enviado su contenido.','error'=>'El Conector no ha podido leer esta categoría. El resto del inventario sigue disponible.',default=>'Información no disponible en esta instalación.'} ?></p>
<?php else: ?>
<?php if($key==='updates'): ?><p class="nwm-notice">Esta es la caché de actualizaciones de Joomla; Nicode no consulta proveedores ni descarga paquetes. Sin una fecha de consulta válida, cero entradas no significa que el sitio esté actualizado. Una versión ofrecida no garantiza compatibilidad ni autoriza su instalación.</p><?php endif; ?>
<?php if($key==='security'): ?><p class="nwm-field-help">Estas señales son observaciones de configuración, no una auditoría de seguridad. HTTPS observado no está disponible en procesos CLI. Un registro MFA no acredita uso efectivo por todos los usuarios.</p><?php endif; ?>
<?php if($key==='storage'): ?><p class="nwm-field-help">Las cifras corresponden al sistema de archivos visible para PHP y pueden incluir otros sitios. No miden el tamaño de este Joomla ni su cuota de hosting.</p><?php endif; ?>
<?php if($fieldDefinitions[$key]): ?><dl class="nwm-inventory-fields"><?php foreach($fieldDefinitions[$key] as $field=>[$fieldLabel,$type]): ?><div><dt><?= $escape($fieldLabel) ?></dt><dd><?= $escape($format($data[$field]??null,$type,$field)) ?></dd></div><?php endforeach; ?></dl><?php endif; ?>
<?php if(isset($rowDefinitions[$key])): $items=is_array($data['items']??null)?$data['items']:[]; ?>
<?php if($key==='extensions'): ?><div class="nwm-search" data-nwm-extension-filter hidden><?= $icon('search') ?><label class="nwm-visually-hidden" for="nwm-extension-search">Buscar extensiones</label><input type="search" id="nwm-extension-search" placeholder="Buscar extensiones…" autocomplete="off"></div><?php endif; ?>
<p class="nwm-field-help" <?= $key==='extensions'?'data-nwm-extension-count role="status"':'' ?>><?= count($items) ?> de <?= $escape($data['total']??'total desconocido') ?> registros<?= !empty($data['truncated'])?' · Lista limitada a los primeros '.InventorySchema::ROW_LIMIT:'' ?>. Desplaza la tabla para consultar todos los campos.</p>
<?php if($items): ?><div class="nwm-table-scroll" role="region" aria-label="<?= $escape($label) ?>" tabindex="0"><table class="nwm-extension-table"><thead><tr><?php foreach($rowDefinitions[$key] as [$column]): ?><th scope="col"><?= $escape($column) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($items as $item): ?><tr <?= $key==='extensions'?'data-nwm-extension':'' ?>><?php foreach($rowDefinitions[$key] as $field=>[$column,$type]): ?><td><?= $escape($format(is_array($item)?($item[$field]??null):null,$type)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><p>No hay registros en esta categoría.</p><?php endif; ?>
<?php if($key==='extensions'): ?><p class="nwm-field-help" data-nwm-no-extensions hidden>No hay extensiones que coincidan con la búsqueda.</p><?php endif; ?>
<?php endif; endif; ?></section><?php endforeach; ?>
<?php endif; ?></section>
