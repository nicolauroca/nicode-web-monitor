<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
?>
<?php if ($canCoreUpdate): ?>
<section aria-label="Actualizaciones de Joomla"><h2>Actualizaciones preparadas de Joomla</h2>
<p class="nwm-tool-summary">Central envía una operación preparada a la cola. La instalación y verificación necesitan el ejecutor del sitio.</p>
<?= $maintenanceHelp('Cómo funciona la cola de Joomla','Prepara y verifica el paquete y la recuperación en el sitio remoto. Registra aquí su operación y confirma por separado el envío a la cola. En cola significa que espera al ejecutor CLI remoto. Verificada significa que otro proceso ha comprobado las versiones instaladas y el esquema de base de datos. Una operación incierta solo admite consulta de estado, sin reintentos automáticos.') ?>
<?php foreach ($sites as $site): if($canDispatch): ?>
<details><summary><?= $escape($site['label']) ?></summary>
<?php if ($canDispatch && $user->authorise('core.admin', 'com_nicodewebmonitor')): ?><details class="nwm-maint-access" <?= !$maintenanceCredentialStored?'open':'' ?>><summary>Configurar acceso avanzado</summary><p>Usa la credencial de esta capacidad preparada en el Conector. Conectar inventario no concede este acceso.</p>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>" autocomplete="off">
<input type="hidden" name="task" value="coreUpdateEnroll"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label>Credencial independiente de Joomla para <?= $escape($site['label']) ?> <input type="password" name="core_update_credential" required autocomplete="new-password"></label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Guardar credencial de actualizaciones</button></form>
</details><?php endif; ?>
<?php if($canDispatch): ?><form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>">
<input type="hidden" name="task" value="coreUpdateBegin"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label>Referencia de la preparación local para <?= $escape($site['label']) ?> <input name="operation" pattern="[a-z0-9-]{8,64}" required></label>
<label><input type="checkbox" name="confirmed" value="1" required> He verificado este paquete local y su procedimiento de recuperación.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Registrar actualización preparada</button></form><?php endif; ?>
</details><?php endif; endforeach; ?>
<?php foreach ($coreUpdateOperations as $operationIndex=>$op): ?>
<article class="nwm-update">
<details class="nwm-maint-operation" <?= $operationIndex===0?'open':'' ?>><summary><span>Actualización de Joomla · <?= $escape($operationLabels[$op['status']]??$op['status']) ?></span><time><?= $escape(gmdate('Y-m-d H:i:s', (int)$op['updated_at'])) ?> UTC</time></summary><div class="nwm-maint-operation-body">
<h3><?= $escape($op['operation']) ?></h3><p>Sitio #<?= (int)$op['site'] ?>; estado: <?= $escape($op['status']) ?> (<?= $escape($operationLabels[$op['status']]??'Consultar estado') ?>)</p>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>"><input type="hidden" name="task" value="coreUpdateStatus"><input type="hidden" name="operation" value="<?= $escape($op['operation']) ?>">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Consultar estado de la actualización</button></form>
<?php if ($op['status']==='uncertain'): ?><p class="nwm-action-warning" role="status">Resultado sin confirmar. Consulta el estado; revisa los efectos antes de solicitar otra ejecución.</p><?php endif; ?>
<?php if ($canDispatch && $op['status']==='ready'): ?>
<p class="nwm-action-warning">Enviar a cola no instala Joomla. El ejecutor local debe realizar la actualización y verificarla en otro proceso. Verifica recuperación y procesos detenidos.</p>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>"><input type="hidden" name="task" value="coreUpdateExecute"><input type="hidden" name="operation" value="<?= $escape($op['operation']) ?>">
<label><input type="checkbox" name="confirmed" value="1" required> Quiero poner esta actualización de Joomla en cola. El ejecutor CLI remoto debe ejecutarla y verificarla.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary">Poner actualización de Joomla en cola</button></form>
<?php endif; ?>
<details><summary>Historial de mantenimiento (últimas 50 observaciones)</summary>
<p>Observaciones guardadas para esta operación. Las fechas se muestran en UTC.</p>
<ol><?php foreach ($op['history'] ?? [] as $event): ?>
<li><?= $escape(gmdate('Y-m-d H:i:s', (int)$event['recorded_at'])) ?> — <?= $escape($event['status']) ?>; usuario #<?= (int)$event['actor_id'] ?><?php if ($event['next_sequence'] !== null): ?>; siguiente paso <?= (int)$event['next_sequence'] ?><?php endif; ?></li>
<?php endforeach; ?></ol></details>
</div></details></article>
<?php endforeach; ?></section>
<?php endif; ?>
