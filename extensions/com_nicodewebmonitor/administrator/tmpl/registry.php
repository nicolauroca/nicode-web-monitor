<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
require __DIR__.'/ui.php';
$uiVersion='1.1.1-dev.5';
$counts=['recent'=>0,'review'=>0,'never'=>0];
foreach($sites as $site){$counts[($states[$site['check_state']??'never_checked']??['Estado desconocido','warning','review'])[2]]++;}
$canCreate=$user->authorise('core.create','com_nicodewebmonitor');
$canAdmin=$user->authorise('core.admin','com_nicodewebmonitor');
$canDelete=$user->authorise('core.delete','com_nicodewebmonitor');
$canBackup=$canBackup??false;$canUpdate=$canUpdate??false;$canCoreUpdate=$canCoreUpdate??false;
$monitoring=$monitoring??['state'=>'pending','last'=>null,'next'=>null,'tasks'=>0];
$hasConnector=false;
if(isset($this) && isset($this->app)){
    $assets=$this->app->getDocument()->getWebAssetManager();
    $base=\Joomla\CMS\Uri\Uri::root().'administrator/components/com_nicodewebmonitor/tmpl/';
    $assets->registerAndUseStyle('nwm.central',$base.'central.css',['version'=>$uiVersion]);
    $assets->registerAndUseScript('nwm.central',$base.'central.js',['version'=>$uiVersion],['defer'=>true]);
    if($canBackup)$assets->registerAndUseScript('nwm.backup.runner',$base.'backup-runner.js',['version'=>$uiVersion],['defer'=>true]);
    $hasConnector=\Joomla\CMS\Component\ComponentHelper::isInstalled('com_nicodeconnector') && $user->authorise('core.manage','com_nicodeconnector');
}
?>
<div class="nwm-central">
<header class="nwm-central-header">
    <span class="nwm-brand-mark"><?= $icon('monitor') ?></span><div class="nwm-header-copy"><p class="nwm-eyebrow">Nicode Web Monitor</p><h1 aria-label="Nicode Web Monitor — Central">Central</h1><p>Una visión clara del inventario de tus sitios Joomla.</p></div>
    <div class="nwm-header-end"><span class="nwm-release">En desarrollo <small><?= $escape($uiVersion) ?> · No definitiva</small></span><?php if($canCreate): ?><a class="nwm-button nwm-button--light" href="#nwm-add-site" data-nwm-open="nwm-add-site"><?= $icon('plus') ?>Añadir sitio</a><?php endif; ?></div>
</header>
<nav class="nwm-nav" aria-label="Secciones del panel"><a href="#nwm-sites"><?= $icon('server') ?>Sitios</a><a href="#nwm-monitoring"><?= $icon('clock') ?>Comprobaciones</a><?php if($canBackup||$canUpdate||$canCoreUpdate): ?><a href="#nwm-maintenance" data-nwm-open="nwm-maintenance"><?= $icon('settings') ?>Mantenimiento</a><?php endif; ?><?php if($hasConnector): ?><a class="nwm-nav-connector" href="index.php?option=com_nicodeconnector">Conector de este Joomla<?= $icon('arrow') ?></a><?php endif; ?></nav>
<section class="nwm-metrics" aria-label="Resumen de inventario">
<?php foreach([['all','Sitios registrados',count($sites),'server'],['recent','Inventario reciente',$counts['recent'],'check'],['review','Requieren revisión',$counts['review'],'alert'],['never','Sin comprobar',$counts['never'],'clock']] as [$group,$label,$count,$symbol]): ?>
<a class="nwm-metric nwm-metric--<?= $group ?>" href="#nwm-sites" data-nwm-filter="<?= $group ?>"><span class="nwm-metric-icon"><?= $icon($symbol) ?></span><span><strong><?= (int)$count ?></strong><span><?= $label ?></span></span></a>
<?php endforeach; ?>
</section>
<?php require __DIR__.'/monitoring.php'; ?>
<?php if(isset($inspection)): require __DIR__.'/inventory.php'; endif; ?>

<section id="nwm-sites" class="nwm-sites-section" aria-label="Sitios registrados">
<div class="nwm-section-heading"><div><p class="nwm-section-label">Tu inventario centralizado</p><h2>Sitios registrados <span class="nwm-count"><?= count($sites) ?></span></h2></div><span class="nwm-utc-note">Fechas en UTC</span></div>
<p class="nwm-section-intro">Revisa la última observación de cada sitio o solicita un inventario ahora. Estos estados no acreditan la disponibilidad pública de la web.</p>
<?php if($sites): ?>
<div class="nwm-site-filters" hidden data-nwm-filter-controls><div class="nwm-search"><?= $icon('search') ?><label class="nwm-visually-hidden" for="nwm-site-search">Buscar sitios por nombre o URL</label><input id="nwm-site-search" type="search" placeholder="Buscar por nombre o dirección…" autocomplete="off"></div><label class="nwm-visually-hidden" for="nwm-site-state">Filtrar por estado</label><select id="nwm-site-state"><option value="all">Todos los estados</option><option value="review">Requieren revisión</option><option value="recent">Inventario reciente</option><option value="never">Sin comprobar</option></select><button class="nwm-button nwm-button--quiet" type="button" data-nwm-clear-filters>Limpiar</button><span class="nwm-filter-count" role="status" data-nwm-filter-count><?= count($sites) ?> sitios</span></div>
<div class="nwm-site-list">
<?php foreach($sites as $site): $state=$states[$site['check_state']??'never_checked']??['Estado desconocido','warning','review'];$last=$site['checks'][0]??null; ?>
<article class="nwm-site" data-nwm-site data-state="<?= $escape($state[2]) ?>" data-search="<?= $escape($site['label'].' '.$site['base_url']) ?>">
    <div class="nwm-site-main"><span class="nwm-site-icon"><?= $icon('server') ?></span><div class="nwm-site-identity"><h3><?= $escape($site['label']) ?></h3><p><?= $escape($site['base_url']) ?></p></div><span class="nwm-badge nwm-badge--<?= $state[1] ?>"><?= $icon($state[1]==='success'?'check':($state[1]==='neutral'?'clock':'alert')) ?><?= $escape($state[0]) ?></span></div>
    <div class="nwm-site-overview"><div><span class="nwm-data-label">Última comprobación</span><strong><?= $last ? $escape($date($last['checked_at'])) : 'Todavía no realizada' ?></strong></div><div><span class="nwm-data-label">Conexión</span><strong><?= ($site['connection_type']??'https')==='local'?'Este mismo Joomla':'HTTPS remoto' ?></strong></div><form method="post" action="index.php?option=com_nicodewebmonitor#nwm-inventory" data-nwm-submit><input type="hidden" name="task" value="inspect"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>"><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--primary" type="submit" aria-label="Actualizar inventario de <?= $escape($site['label']) ?>"><span data-nwm-button-label>Actualizar inventario</span><?= $icon('arrow') ?></button></form></div>
    <?php if(is_array($site['inventory_summary']??null)): $summary=$site['inventory_summary'];$coverage=$summary['coverage']??[];$brief=static function($category,$field)use($summary,$coverage){return ($coverage[$category]??'')==='denied'?'No compartido':($summary[$category][$field]??'No disponible');}; ?>
    <div class="nwm-site-inventory"><div><span class="nwm-data-label">Joomla</span><strong><?= $escape($brief('joomla','version')) ?></strong></div><div><span class="nwm-data-label">PHP</span><strong><?= $escape($brief('runtime','php_version')) ?></strong></div><div><span class="nwm-data-label">Actualizaciones en caché</span><strong><?= $escape($brief('updates','total')) ?></strong></div><div><span class="nwm-data-label">Categorías compartidas</span><strong><?= count(array_filter($coverage,static fn($status)=>$status!=='denied')) ?> de <?= count($coverage) ?></strong></div><a class="nwm-button nwm-button--secondary" href="index.php?option=com_nicodewebmonitor&amp;site=<?= (int)$site['id'] ?>#nwm-inventory">Detalle guardado<?= $icon('arrow') ?></a><p>Último inventario recibido: <?= $escape($date($site['inventory_received_at'])) ?> UTC. Los permisos reflejan esa observación.</p></div>
    <?php endif; ?>
    <details class="nwm-site-details"><summary>Historial y opciones<?= $icon('chevron') ?></summary><div class="nwm-site-details-body">
        <div class="nwm-details-grid"><section><h4>Comprobaciones recientes</h4><?php if(!empty($site['checks'])): ?><ol class="nwm-timeline"><?php foreach($site['checks'] as $check): ?><li><time><?= $escape($date($check['checked_at'])) ?></time><span><?= $escape($detailLabels[$check['detail']]??$check['detail']) ?></span></li><?php endforeach; ?></ol><?php else: ?><p>El sitio todavía no tiene comprobaciones guardadas.</p><?php endif; ?></section>
        <section><h4>Alertas de inventario</h4><p><?= !isset($site['alerts']) ? 'Sin información de alertas.' : ($site['alerts']['active']?'Hay una observación que requiere revisión.':(($site['alerts']['state']??'')==='never_checked'?'A la espera de la primera comprobación.':'Sin alertas activas de inventario.')) ?></p>
        <?php if(!empty($site['alerts']['transitions'])): ?><details class="nwm-subdetails"><summary>Cambios de estado</summary><ol class="nwm-timeline"><?php foreach($site['alerts']['transitions'] as $event): ?><li><time><?= $escape($date($event['at'])) ?></time><span><?= $escape(($states[$event['from']][0]??$event['from']).' → '.($states[$event['to']][0]??$event['to'])) ?></span><small><?= $escape(['recovered'=>'Recuperación','opened'=>'Alerta detectada','changed'=>'Cambio de estado','baseline'=>'Primera observación'][$event['kind']]??$event['kind']) ?></small></li><?php endforeach; ?></ol></details><?php endif; ?>
        <?php if(!empty($site['notification'])): $notice=$site['notification']; ?><p class="nwm-notification">Correo: <?= $escape($deliveryLabels[$notice['delivery']]??$notice['delivery']) ?>.<br>Evaluado: <?= $escape($date($notice['evaluated_at'])) ?>.</p><p class="nwm-muted">Aceptación por SMTP no confirma la entrega al buzón.</p>
        <?php if($canAdmin && in_array($notice['delivery'],['uncertain','not_accepted'],true)): ?><form method="post" action="index.php?option=com_nicodewebmonitor"><input type="hidden" name="task" value="notificationRetry"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>"><label class="nwm-confirm"><input type="checkbox" name="confirmed" value="1" required><span>He comprobado la entrega; otro intento podría duplicar el correo.</span></label><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--secondary" type="submit">Autorizar otro intento de correo</button></form><?php endif; endif; ?>
        </section></div>
        <details class="nwm-subdetails"><summary>Detalles técnicos de conexión</summary><dl class="nwm-tech"><dt>Identidad del conector</dt><dd><?= $escape($site['site_id']) ?></dd><dt>Destino</dt><dd><?= ($site['connection_type']??'https')==='local'?'Inventario local sin peticiones HTTP al propio sitio':$escape($site['address']) ?></dd></dl></details>
        <?php if($canDelete): ?><details class="nwm-remove"><summary>Eliminar registro</summary><p>Elimina este sitio y su historial del panel. El acceso remoto se revoca por separado desde el Conector.</p><form method="post" action="index.php?option=com_nicodewebmonitor"><input type="hidden" name="task" value="remove"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>"><label class="nwm-confirm"><input type="checkbox" name="confirmed" value="1" required><span>Quiero eliminar el registro de <?= $escape($site['label']) ?> y su historial.</span></label><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--danger" type="submit" aria-label="Eliminar registro de <?= $escape($site['label']) ?>">Eliminar registro</button></form></details><?php endif; ?>
    </div></details>
</article>
<?php endforeach; ?></div><div class="nwm-empty nwm-empty--filtered" data-nwm-no-results hidden><h3>No hay coincidencias</h3><p>Prueba otro nombre, dirección o estado, o limpia los filtros.</p></div>
<?php else: ?><div class="nwm-empty"><span><?= $icon('server') ?></span><h3>Tu primer sitio empieza aquí</h3><p>Vincula un Joomla para ver su inventario y seguir sus comprobaciones desde este panel.</p><?php if($canCreate): ?><a class="nwm-button nwm-button--primary" href="#nwm-add-site" data-nwm-open="nwm-add-site"><?= $icon('plus') ?>Añadir el primer sitio</a><?php else: ?><p>Un administrador con permiso para añadir sitios puede completar la vinculación.</p><?php endif; ?></div><?php endif; ?>
</section>
<?php require __DIR__.'/onboarding.php'; ?>
<?php if($canBackup||$canUpdate||$canCoreUpdate): ?><details id="nwm-maintenance" class="nwm-panel nwm-maintenance" <?= preg_match('/^(backup|update|coreUpdate)/',$task??'')?'open':'' ?>><summary><?= $icon('settings') ?><span><strong>Mantenimiento</strong><small>Copias y actualizaciones preparadas · Funciones experimentales</small></span><?= $icon('chevron') ?></summary><div class="nwm-maintenance-body"><?php require __DIR__.'/maintenance.php'; ?></div></details><?php endif; ?>
<footer class="nwm-central-footer"><p>Inventario reciente: comprobado y recopilado en las últimas 24 horas. Se conservan hasta 100 resúmenes por sitio.</p><?php if($canAdmin): ?><a href="index.php?option=com_config&amp;view=component&amp;component=com_nicodewebmonitor">Permisos y avisos por correo<?= $icon('arrow') ?></a><?php endif; ?></footer>
</div>
