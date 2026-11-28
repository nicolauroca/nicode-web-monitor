<?php
/** @license GPL-2.0-or-later — one page and one site context at a time. */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
require __DIR__.'/ui.php';
$releaseCatalog=isset($this)&&isset($this->app)?(new \Nicode\Component\NicodeWebMonitor\Administrator\Service\ReleaseCatalog(\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class)))->read():\Nicode\Component\NicodeWebMonitor\Administrator\Service\ReleaseCatalog::seed();
foreach($sites as &$row)$row['health']=\Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteHealth::assess($row,$releaseCatalog);unset($row);
$priorityFilters=['all'=>'Todas las prioridades','critical'=>'Prioritarias','review'=>'Para revisar','updates'=>'Extensiones pendientes','php'=>'PHP requiere revisión','joomla'=>'Joomla requiere revisión','security'=>'Señales de configuración','storage'=>'Espacio escaso','unknown'=>'Datos insuficientes'];
$priorityFilter=isset($priorityFilters[$priorityFilter??'all'])?($priorityFilter??'all'):'all';
$canReferences=$user->authorise('nwm.monitor','com_nicodewebmonitor');
$uiVersion='1.1.1-dev.10';
$canCreate=$user->authorise('core.create','com_nicodewebmonitor');
$canAdmin=$user->authorise('core.admin','com_nicodewebmonitor');
$canDelete=$user->authorise('core.delete','com_nicodewebmonitor');
$canBackup=$canBackup??false;$canUpdate=$canUpdate??false;$canCoreUpdate=$canCoreUpdate??false;
$canMaintain=$canBackup||$canUpdate||$canCoreUpdate;
$monitoring=$monitoring??['state'=>'pending','last'=>null,'next'=>null,'tasks'=>0];
$rootUrl='index.php?option=com_nicodewebmonitor';
$pageUrl=static fn($screen)=>$rootUrl.'&screen='.rawurlencode($screen);
$siteUrl=static fn($id,$section='overview',$category='')=>$rootUrl.'&site='.(int)$id.'&section='.rawurlencode($section).($category!==''?'&category='.rawurlencode($category):'');
$selectedSiteId=$selectedSiteId??($inspectedId??0);
$viewPage=$viewPage??'sites';$viewSection=$viewSection??(isset($inspection)?'inventory':'overview');$viewCategory=$viewCategory??'';
$pageLabels=['sites'=>'Sitios','add'=>'Añadir sitio','monitoring'=>'Comprobaciones','maintenance'=>'Mantenimiento'];
$siteLabels=['overview'=>'Resumen','health'=>'Diagnóstico','inventory'=>'Inventario','history'=>'Historial','connection'=>'Conexión'];
if($canMaintain)$siteLabels['maintenance']='Mantenimiento';
$selectedSite=null;
foreach($sites as $row)if((int)$row['id']===(int)$selectedSiteId){$selectedSite=$row;break;}
$hasConnector=false;
$base=(isset($this)&&isset($this->app)?\Joomla\CMS\Uri\Uri::root().'administrator/':'').'components/com_nicodewebmonitor/tmpl/';
if(isset($this)&&isset($this->app)){
    $assets=$this->app->getDocument()->getWebAssetManager();
    $assets->registerAndUseStyle('nwm.central.brand',$base.'brand/brand.css',['version'=>$uiVersion]);
    $assets->registerAndUseStyle('nwm.central',$base.'central.css',['version'=>$uiVersion]);
    $assets->registerAndUseScript('nwm.central',$base.'central.js',['version'=>$uiVersion],['defer'=>true]);
    if($canBackup)$assets->registerAndUseScript('nwm.backup.runner',$base.'backup-runner.js',['version'=>$uiVersion],['defer'=>true]);
    $hasConnector=\Joomla\CMS\Component\ComponentHelper::isInstalled('com_nicodeconnector')&&$user->authorise('core.manage','com_nicodeconnector');
}
?>
<div class="nwm-central" data-nwm-page="<?= $selectedSiteId?'site':$escape($viewPage) ?>">
<header class="nwm-central-header"><span class="nwm-brand-mark"><img class="nwm-brand-symbol" src="<?= $escape($base.'brand/isotipo-dark.svg') ?>" alt="Nicode Studio" width="40" height="40"></span><div class="nwm-header-copy"><p class="nwm-eyebrow">Nicode Web Monitor</p><h1 aria-label="Nicode Web Monitor — Central">Central</h1><p><?= $selectedSiteId?'La información de un sitio, organizada por secciones.':'Todos tus Joomla, en un único lugar.' ?></p></div><div class="nwm-header-end"><span class="nwm-release">En desarrollo <small><?= $escape($uiVersion) ?> · No definitiva</small></span><?php if($canCreate&&!$selectedSiteId&&$viewPage!=='add'): ?><a class="nwm-button nwm-button--light" href="<?= $escape($pageUrl('add')) ?>"><?= $icon('plus') ?>Añadir sitio</a><?php endif; ?></div></header>
<nav class="nwm-nav" aria-label="Navegación de Central"><?php foreach(['sites'=>'Sitios','monitoring'=>'Comprobaciones']+($canMaintain?['maintenance'=>'Mantenimiento']:[]) as $key=>$label): ?><a href="<?= $escape($pageUrl($key)) ?>" <?= !$selectedSiteId&&$viewPage===$key?'aria-current="page"':'' ?>><?= $icon(['sites'=>'server','monitoring'=>'clock','maintenance'=>'settings'][$key]) ?><?= $label ?></a><?php endforeach; ?><?php if($hasConnector): ?><a class="nwm-nav-connector" href="index.php?option=com_nicodeconnector">Conector de este Joomla<?= $icon('arrow') ?></a><?php endif; ?></nav>
<?php if($selectedSiteId): ?>
    <?php if(!$selectedSite||!isset($siteLabels[$viewSection])): ?><section class="nwm-panel nwm-detail-panel"><h2>Sitio o sección no disponible</h2><p>El registro puede haberse eliminado o la dirección no corresponde a una sección disponible.</p><a class="nwm-button nwm-button--primary" href="<?= $escape($pageUrl('sites')) ?>">Volver a sitios</a></section>
    <?php else: require __DIR__.'/site.php'; endif; ?>
<?php elseif($viewPage==='sites'): require __DIR__.'/sites.php'; ?>
<?php elseif($viewPage==='add'&&$canCreate): ?><nav class="nwm-breadcrumb" aria-label="Ruta de navegación"><a href="<?= $escape($pageUrl('sites')) ?>">Sitios</a><span aria-hidden="true">/</span><span aria-current="page">Añadir sitio</span></nav><?php require __DIR__.'/onboarding.php'; ?>
<?php elseif($viewPage==='monitoring'): ?><nav class="nwm-breadcrumb" aria-label="Ruta de navegación"><a href="<?= $escape($pageUrl('sites')) ?>">Sitios</a><span aria-hidden="true">/</span><span aria-current="page">Comprobaciones</span></nav><?php require __DIR__.'/monitoring.php'; ?>
<?php elseif($viewPage==='maintenance'&&$canMaintain): ?><nav class="nwm-breadcrumb" aria-label="Ruta de navegación"><a href="<?= $escape($pageUrl('sites')) ?>">Sitios</a><span aria-hidden="true">/</span><span aria-current="page">Mantenimiento</span></nav><section class="nwm-panel nwm-detail-panel"><h2>Mantenimiento por sitio</h2><p>Elige el Joomla en el que quieres preparar una copia o revisar una actualización. Las confirmaciones y credenciales son independientes del inventario.</p><div class="nwm-section-links"><?php foreach($sites as $row): ?><a href="<?= $escape($siteUrl($row['id'],'maintenance')) ?>"><strong><?= $escape($row['label']) ?></strong><span><?= $escape($row['base_url']) ?></span><?= $icon('arrow') ?></a><?php endforeach; ?></div><?php if(!$sites): ?><p>Primero necesitas registrar un sitio.</p><?php endif; ?></section>
<?php else: ?><section class="nwm-panel nwm-detail-panel"><h2>Sección no disponible</h2><a href="<?= $escape($pageUrl('sites')) ?>">Volver a sitios</a></section><?php endif; ?>
<footer class="nwm-central-footer"><p>Inventario reciente: observación de las últimas 24 horas. Fechas en UTC. El inventario no acredita disponibilidad pública.</p><?php if($canAdmin): ?><a href="index.php?option=com_config&amp;view=component&amp;component=com_nicodewebmonitor">Permisos y avisos por correo<?= $icon('arrow') ?></a><?php endif; ?></footer>
</div>
