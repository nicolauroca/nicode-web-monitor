<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
?>
<?php if ($canUpdate): ?>
<section aria-label="Actualizaciones de extensiones"><h2>Actualizaciones preparadas de extensiones</h2>
<p class="nwm-tool-summary">El paquete se prepara en el sitio remoto. Registrar su referencia no ejecuta la actualización.</p>
<?= $maintenanceHelp('Cómo preparar una actualización','Prepara y verifica el paquete y la recuperación en el sitio remoto. Después registra aquí el identificador de esa operación. Ejecutarla requiere una confirmación adicional. Una operación incierta solo admite consulta de estado, sin reintentos automáticos.') ?>
<?php foreach ($sites as $site): if($canDispatch): ?>
<details><summary><?= $escape($site['label']) ?></summary>
<?php if ($canDispatch && $user->authorise('core.admin', 'com_nicodewebmonitor')): ?><details class="nwm-maint-access" <?= !$maintenanceCredentialStored?'open':'' ?>><summary>Configurar acceso avanzado</summary><p>Usa la credencial de esta capacidad preparada en el Conector. Conectar inventario no concede este acceso.</p>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>" autocomplete="off">
<input type="hidden" name="task" value="updateEnroll"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label>Credencial de actualizaciones para <?= $escape($site['label']) ?> <input type="password" name="update_credential" required autocomplete="new-password"></label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Guardar credencial de actualizaciones</button></form>
</details><?php endif; ?>
<?php if($canDispatch): ?><form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>">
<input type="hidden" name="task" value="updateBegin"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label>Referencia de la preparación local para <?= $escape($site['label']) ?> <input name="operation" pattern="[a-z0-9-]{8,64}" required></label>
<label><input type="checkbox" name="confirmed" value="1" required> He verificado este paquete local y su procedimiento de recuperación.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Registrar actualización preparada</button></form><?php endif; ?>
</details><?php endif; endforeach; ?>
<?php foreach ($updateOperations as $operationIndex=>$op): ?>
<article class="nwm-update">
<details class="nwm-maint-operation" <?= $operationIndex===0?'open':'' ?>><summary><span>Actualización de extensión · <?= $escape($operationLabels[$op['status']]??$op['status']) ?></span><time><?= $escape(gmdate('Y-m-d H:i:s', (int)$op['updated_at'])) ?> UTC</time></summary><div class="nwm-maint-operation-body">
<h3><?= $escape($op['operation']) ?></h3><p>Sitio #<?= (int)$op['site'] ?>; estado: <?= $escape($op['status']) ?> (<?= $escape($operationLabels[$op['status']]??'Consultar estado') ?>)</p>
<?php if (isset($op['compound'])): ?>
<details><summary>Observaciones del paquete y sus extensiones</summary>
<?php foreach (['before'=>'Antes de actualizar','observed'=>'Última observación guardada'] as $phase=>$label): ?>
<h4><?= $label ?></h4>
<?php if ($op['compound'][$phase] === null): ?><p>No hay una observación disponible. Consulta el estado antes de realizar otra instalación.</p>
<?php else: ?><ul>
<?php foreach (array_merge($op['compound'][$phase]['parents'],$op['compound'][$phase]['children']) as $row): ?>
<li><?= $escape($row['identity']['type'].' '.$row['identity']['element']) ?>; ID <?= (int)$row['id'] ?>; ID del paquete <?= (int)$row['package_id'] ?>; versión <?= $escape($row['version'] ?? 'No disponible') ?></li>
<?php endforeach; ?></ul><?php endif; ?>
<?php endforeach; ?></details>
<?php endif; ?>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>"><input type="hidden" name="task" value="updateStatus"><input type="hidden" name="operation" value="<?= $escape($op['operation']) ?>">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Consultar estado de la actualización</button></form>
<?php if ($op['status']==='uncertain'): ?><p class="nwm-action-warning" role="status">Resultado sin confirmar. Consulta el estado; revisa los efectos antes de solicitar otra ejecución.</p><?php endif; ?>
<?php if ($canDispatch && $op['status']==='ready'): ?>
<p class="nwm-action-warning">Ejecuta scripts de instalación y puede dejar cambios parciales. Verifica recuperación y procesos detenidos antes de confirmar.</p>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>"><input type="hidden" name="task" value="updateExecute"><input type="hidden" name="operation" value="<?= $escape($op['operation']) ?>">
<label><input type="checkbox" name="confirmed" value="1" required> Quiero ejecutar ahora esta actualización preparada.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary">Ejecutar actualización</button></form>
<?php endif; ?>
<details><summary>Historial de mantenimiento (últimas 50 observaciones)</summary>
<p>Observaciones guardadas para esta operación. Las fechas se muestran en UTC.</p>
<ol><?php foreach ($op['history'] ?? [] as $event): ?>
<li><?= $escape(gmdate('Y-m-d H:i:s', (int)$event['recorded_at'])) ?> — <?= $escape($event['status']) ?>; usuario #<?= (int)$event['actor_id'] ?><?php if ($event['next_sequence'] !== null): ?>; siguiente paso <?= (int)$event['next_sequence'] ?><?php endif; ?></li>
<?php endforeach; ?></ol></details>
</div></details></article>
<?php endforeach; ?></section>
<?php endif; ?>
