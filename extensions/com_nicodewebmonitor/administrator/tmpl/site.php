<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventorySchema;
$site=$selectedSite;$inspectedId=(int)$site['id'];$viewTool=$viewTool??'';
$maintenanceTitles=['backups'=>'Copias de seguridad','extensions'=>'Extensiones','joomla'=>'Joomla'];
$siteLink=static fn($section='overview',$category='')=>$siteUrl($site['id'],$section,$category);
$maintenanceUrl=static fn($tool='')=>$siteLink('maintenance').($tool!==''?'&tool='.rawurlencode($tool):'');
$state=$states[$site['check_state']??'never_checked']??['Estado desconocido','warning','review'];
$categoryLabel=$viewCategory!==''?(InventorySchema::categories()[$viewCategory][0]??null):null;
?>
<nav class="nwm-breadcrumb" aria-label="Ruta de navegación"><a href="<?= $escape($pageUrl('sites')) ?>">Sitios</a><span aria-hidden="true">/</span><?php if($viewSection==='overview'): ?><span aria-current="page"><?= $escape($site['label']) ?></span><?php else: ?><a href="<?= $escape($siteLink()) ?>"><?= $escape($site['label']) ?></a><span aria-hidden="true">/</span><?php if($viewSection==='maintenance'&&$viewTool!==''): ?><a href="<?= $escape($siteLink('maintenance')) ?>">Mantenimiento</a><span aria-hidden="true">/</span><span aria-current="page"><?= $escape($maintenanceTitles[$viewTool]??'Herramienta no disponible') ?></span><?php elseif($viewCategory!==''&&$viewSection==='inventory'): ?><a href="<?= $escape($siteLink('inventory')) ?>">Inventario</a><span aria-hidden="true">/</span><span aria-current="page"><?= $escape($categoryLabel??'Categoría no disponible') ?></span><?php else: ?><span aria-current="page"><?= $escape($siteLabels[$viewSection]) ?></span><?php endif; endif; ?></nav>
<section class="nwm-site-heading" aria-label="Sitio seleccionado"><div><p class="nwm-section-label">Ficha del sitio</p><h2><?= $escape($site['label']) ?></h2><p><?= $escape($site['base_url']) ?></p></div><span class="nwm-badge nwm-badge--<?= $state[1] ?>"><?= $escape($state[0]) ?></span><?php if($user->authorise('core.manage','com_nicodewebmonitor')): ?><form method="post" action="<?= $escape($siteLink('inventory',$viewSection==='inventory'?$viewCategory:'')) ?>" data-nwm-submit><input type="hidden" name="task" value="inspect"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>"><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--primary" type="submit" aria-label="Actualizar inventario de <?= $escape($site['label']) ?>"><span data-nwm-button-label>Actualizar inventario</span><?= $icon('arrow') ?></button></form><?php endif; ?></section>
<nav class="nwm-site-tabs" aria-label="Secciones del sitio"><?php foreach($siteLabels as $section=>$label): ?><a href="<?= $escape($siteLink($section)) ?>" <?= $section===$viewSection?'aria-current="page"':'' ?>><?= $escape($label) ?></a><?php endforeach; ?></nav>
<?php if($viewSection==='overview'): require __DIR__.'/site-overview.php';
elseif($viewSection==='health'): require __DIR__.'/site-health.php';
elseif($viewSection==='inventory'): require __DIR__.'/inventory.php';
elseif($viewSection==='history'): require __DIR__.'/site-history.php';
elseif($viewSection==='connection'): require __DIR__.'/site-connection.php';
elseif($viewSection==='maintenance'):
    $sites=[$selectedSite];
    $own=static fn($op)=>(int)$op['site']===(int)$selectedSite['id'];
    $backupOperations=array_values(array_filter($backupOperations??[],$own));
    $updateOperations=array_values(array_filter($updateOperations??[],$own));
    $coreUpdateOperations=array_values(array_filter($coreUpdateOperations??[],$own)); ?>
<section id="nwm-maintenance" class="nwm-panel nwm-maintenance"><div class="nwm-detail-title"><h2>Mantenimiento del sitio</h2><p>Copias y actualizaciones experimentales. Revisa las precondiciones y confirma cada acción.</p></div><div class="nwm-maintenance-body"><?php require __DIR__.'/maintenance.php'; ?></div></section>
<?php endif; ?>
<p class="nwm-back-row"><?php if($viewSection==='maintenance'&&$viewTool!==''): ?><a href="<?= $escape($siteLink('maintenance')) ?>">← Volver a herramientas de mantenimiento</a><?php elseif($viewSection==='inventory'&&$viewCategory!==''): ?><a href="<?= $escape($siteLink('inventory')) ?>">← Volver a categorías de inventario</a><?php elseif($viewSection!=='overview'): ?><a href="<?= $escape($siteLink()) ?>">← Volver al resumen del sitio</a><?php else: ?><a href="<?= $escape($pageUrl('sites')) ?>">← Volver a sitios</a><?php endif; ?></p>
