<?php
/** @license GPL-2.0-or-later — one tool at a time; provider contracts are unchanged. */
defined('_JEXEC') or die;
$viewTool=$viewTool??'';
$maintenanceCredentialStored=$maintenanceCredentialStored??false;
$canDispatch=($selectedSite['connection_type']??'https')!=='local';
$tools=[];
if($canBackup)$tools['backups']=['Copias de seguridad','Captura de archivos y base de datos por pasos. No acredita una restauración probada.','Crear una operación → avanzar → verificar estado','server'];
if($canUpdate)$tools['extensions']=['Extensiones','Plugins, módulos, componentes, plantillas, librerías y paquetes preparados.','Preparar en el sitio → registrar → confirmar ejecución','settings'];
if($canCoreUpdate)$tools['joomla']=['Joomla','Actualización del propio Joomla mediante cola y ejecutor independiente.','Preparar en el sitio → encolar → verificar en otro proceso','monitor'];
$maintenanceHelp=static function(string $title,string $body)use($escape): string{
    return '<details class="nwm-inline-help"><summary><span class="nwm-info-icon" aria-hidden="true">i</span><span>'.$escape($title).'</span></summary><div>'.$escape($body).'</div></details>';
};
?>
<?php if(!$canDispatch): ?><div class="nwm-local-maintenance"><strong>Este Joomla utiliza inventario local</strong><p>La conexión local permite monitorizarlo. Las operaciones remotas de mantenimiento necesitan otro transporte y preparación; no quedan habilitadas al vincularlo.</p><?php if($user->authorise('core.manage','com_installer')): ?><a href="index.php?option=com_installer&amp;view=update">Abrir actualizaciones de extensiones de este Joomla <?= $icon('arrow') ?></a><?php endif; ?><?php if($user->authorise('core.manage','com_joomlaupdate')): ?><a href="index.php?option=com_joomlaupdate">Abrir el actualizador de este Joomla <?= $icon('arrow') ?></a><?php endif; ?></div><?php endif; ?>
<?php if($viewTool===''): ?>
<div class="nwm-maint-intro"><h3>¿Qué quieres hacer?</h3><p>Elige una herramienta. Cada una tiene su acceso y confirmaciones independientes.</p><span class="nwm-badge nwm-badge--warning">Mantenimiento experimental</span></div>
<div class="nwm-maint-tools" aria-label="Herramientas de mantenimiento"><?php foreach($tools as $key=>[$title,$description,$steps,$symbol]): ?><a class="nwm-maint-tool" href="<?= $escape($maintenanceUrl($key)) ?>" data-nwm-maint-tool="<?= $key ?>"><span class="nwm-maint-tool-icon"><?= $icon($symbol) ?></span><h3><?= $escape($title) ?></h3><p><?= $escape($description) ?></p><small><?= $escape($steps) ?></small><strong>Abrir herramienta <?= $icon('arrow') ?></strong></a><?php endforeach; ?></div>
<?= $maintenanceHelp('Permisos y preparación','Leer el inventario no concede permiso para modificar el sitio. El Conector necesita habilitar cada capacidad y su credencial independiente. La preparación local se realiza antes de ejecutar; esta pantalla no detiene cron ni otros procesos. Los permisos visibles corresponden a tu cuenta de Central, no confirman el acceso remoto.') ?>
<?php elseif(!isset($tools[$viewTool])): ?><h3>Herramienta no disponible</h3><p>La dirección no corresponde a una herramienta autorizada para tu cuenta.</p>
<?php else: ?>
<?php if($canDispatch): ?><p class="nwm-maint-access-state" role="status"><strong>Acceso en Central:</strong> <?= $maintenanceCredentialStored?'credencial registrada. El Conector debe tener permiso y preparación vigentes antes de ejecutar.':'pendiente de configurar. Un administrador debe registrar la credencial independiente de esta capacidad.' ?></p><?php endif; ?>
<?php if($viewTool==='backups'): require __DIR__.'/maintenance-backups.php';
elseif($viewTool==='extensions'): require __DIR__.'/maintenance-extensions.php';
elseif($viewTool==='joomla'): require __DIR__.'/maintenance-joomla.php';
endif; endif; ?>
