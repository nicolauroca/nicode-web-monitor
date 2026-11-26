<?php
/** @license GPL-2.0-or-later — stored and remote values remain plain text. */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventorySchema;
$inspectedLabel='Sitio #'.(int)($inspectedId??0);$inspectedState=null;
foreach($sites as $registeredSite)if((int)$registeredSite['id']===(int)($inspectedId??0)){$inspectedLabel=$registeredSite['label'];$inspectedState=($registeredSite['check_state']??'never_checked');break;}
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
<div class="nwm-section-heading"><div><p class="nwm-section-label"><?= !empty($inspection['from_cache'])?'Última observación guardada':'Resultado de la comprobación' ?></p><h2><?= $viewCategory!==''?$escape($categories[$viewCategory][0]??'Categoría no disponible'):'Inventario del sitio' ?></h2></div><span class="nwm-utc-note">Fechas en UTC</span></div>
<?php if(($inspection['history_saved']??true)===false): ?><p class="nwm-notice nwm-notice--warning" role="alert">No se ha podido guardar esta comprobación en el historial.</p><?php endif; ?>
<?php if(($inspection['snapshot_saved']??true)===false): ?><p class="nwm-notice nwm-notice--warning" role="alert">No se ha podido conservar este inventario. El detalle guardado anterior no se ha actualizado.</p><?php endif; ?>
<?php if(empty($inspection['ok'])): $error=$inspection['error']??'inventory_not_saved'; ?>
<div class="nwm-notice <?= $error==='inventory_not_saved'?'':'nwm-notice--danger' ?>" role="status"><h3><?= $error==='inventory_not_saved'?'Aún no hay un inventario guardado':'No se ha obtenido el inventario' ?></h3><p><?= $error==='inventory_not_saved'?'Pulsa Actualizar inventario para consultar el sitio y guardar su primera observación.':$escape($detailLabels[$error]??'Revisa la conexión y los permisos del Conector antes de volver a comprobar.') ?></p><?php if($error!=='inventory_not_saved'): ?><p class="nwm-field-help">Referencia técnica: <code><?= $escape($error) ?></code></p><?php endif; ?></div>
<?php if(!empty($site['inventory_received_at'])): ?><p><a href="<?= $escape($siteLink('inventory')) ?>">Ver la última observación guardada<?= $icon('arrow') ?></a></p><?php endif; ?>
<?php elseif($viewCategory!==''&&!isset($categories[$viewCategory])): ?><p>Esta categoría no existe. Vuelve al inventario y elige una de las categorías disponibles.</p>
<?php else: ?>
<?php if(!empty($inspection['from_cache'])): ?><div class="nwm-observation-note" role="status"><p>Estás viendo el último inventario recibido. Abrir este detalle no consulta el sitio. Los permisos o datos pueden haber cambiado desde esa fecha.</p><?php if(in_array($inspectedState,['check_failed','stale','clock_uncertain'],true)): ?><p><strong>La última comprobación requiere revisión: <?= $escape($states[$inspectedState][0]) ?>.</strong> Este inventario no acredita su estado actual.</p><?php endif; ?></div><?php endif; ?>
<div class="nwm-inventory-meta"><span class="nwm-badge nwm-badge--<?= ($inventory['status']??'')==='complete'?'success':'warning' ?>"><?= ($inventory['status']??'')==='complete'?'Consulta completada':'Consulta con datos parciales' ?></span><span><?= $shared ?> de <?= count($categories) ?> categorías permitidas</span><span>Recopilado <strong><?= $escape($date($inventory['collected_at']??null)) ?> UTC</strong></span><span>Recibido <strong><?= $escape($date($inspection['received_at']??null)) ?> UTC</strong></span></div>
<?php if($viewCategory===''): ?>
<p class="nwm-section-intro">Elige una categoría para ver sus datos. Los permisos los decide el administrador del Conector de este sitio.</p>
<div class="nwm-category-directory" aria-label="Categorías de inventario">
<?php foreach($categories as $key=>[$label,$description]): $status=$availability[$sections[$key]['status']??'unavailable']??$availability['unavailable']; ?>
<a class="nwm-category-link" href="<?= $escape($siteLink('inventory',$key)) ?>" data-nwm-category-link="<?= $key ?>"><span class="nwm-badge nwm-badge--<?= $status[1] ?>"><?= $status[0] ?></span><strong><?= $escape($label) ?></strong><span><?= $escape($description) ?></span><small>Ver categoría <?= $icon('arrow') ?></small></a>
<?php endforeach; ?></div>
<?php else: ?>
<?php foreach([$viewCategory=>$categories[$viewCategory]] as $key=>[$label,$description]): $section=$sections[$key]??['status'=>'unavailable','data'=>null];$status=$availability[$section['status']]??$availability['unavailable'];$data=is_array($section['data'])?$section['data']:[]; ?>
<section id="nwm-category-<?= $key ?>" class="nwm-inventory-category" data-category="<?= $key ?>" aria-label="<?= $escape($label) ?>">
<div class="nwm-section-heading"><h3>Datos de esta categoría</h3><span class="nwm-badge nwm-badge--<?= $status[1] ?>"><?= $status[0] ?></span></div><p class="nwm-field-help"><?= $escape($description) ?></p>
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
<?php endif; endif; ?></section>
