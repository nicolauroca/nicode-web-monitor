<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
?>

<?php if ($canBackup ?? false): ?>
<section aria-label="Copias de seguridad">
<h2>Copias de seguridad</h2>
<p>Captura archivos y base de datos con el proveedor de copias del Conector y una credencial independiente. Detén todos los procesos que escriban antes de avanzar; marcar la confirmación aquí no los detiene. Crea una única operación y avanza por pasos o con un lote confirmado. Ante un resultado incierto, consulta su estado antes de hacer nada más. Una copia completa no acredita una restauración probada.</p>
<?php foreach ($sites as $site): ?>
<details><summary>Preparar copia de <?= $escape($site['label']) ?></summary>
<?php if ($user->authorise('core.admin', 'com_nicodewebmonitor')): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance" autocomplete="off">
<input type="hidden" name="task" value="backupEnroll"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label for="backup-secret-<?= (int)$site['id'] ?>">Credencial independiente de copias</label>
<input class="form-control" id="backup-secret-<?= (int)$site['id'] ?>" name="backup_credential" type="password" required pattern="[a-f0-9]{64}" maxlength="64" autocomplete="new-password">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary" type="submit">Guardar credencial de copias</button></form>
<?php endif; ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance">
<input type="hidden" name="task" value="backupBegin"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label><input type="checkbox" name="confirmed" value="1" required> Crear una operación nueva para <?= $escape($site['label']) ?> (todavía no captura datos remotos).</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Crear operación de copia</button></form>
</details>
<?php endforeach; ?>
<h3>Últimas 100 operaciones</h3>
<p>Este registro muestra el estado actual de cada operación. Despliega su historial para consultar las observaciones guardadas.</p>
<?php if(!$sites): ?><p>No hay sitios registrados para preparar una copia.</p><?php endif; ?>
<?php foreach ($backupOperations as $operation): ?>
<article class="nwm-backup" style="overflow-wrap:anywhere" data-site="<?= (int)$operation['site'] ?>" data-operation="<?= $escape($operation['operation']) ?>" data-sequence="<?= (int)$operation['next_sequence'] ?>">
<h4><?= $escape($operation['operation']) ?></h4>
<p>Sitio #<?= (int)$operation['site'] ?>; estado: <?= $escape($operation['status']) ?> (<?= $escape($operationLabels[$operation['status']]??'Consultar estado') ?>); siguiente paso: <?= (int)$operation['next_sequence'] ?>; actualizado <?= $escape(gmdate('Y-m-d H:i:s', (int)$operation['updated_at'])) ?> UTC.</p>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance">
<input type="hidden" name="task" value="backupStatus"><input type="hidden" name="operation" value="<?= $escape($operation['operation']) ?>">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary" type="submit">Consultar estado de la copia</button></form>
<?php if (in_array($operation['status'], ['ready','progress'], true)): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance">
<input type="hidden" name="task" value="backupAdvance"><input type="hidden" name="operation" value="<?= $escape($operation['operation']) ?>">
<label><input type="checkbox" name="confirmed" value="1" required> He detenido todos los procesos que escriben. Quiero avanzar un paso en esta operación.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Avanzar un paso</button></form>
<form class="nwm-backup-runner" method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance" hidden>
<input type="hidden" name="task" value="backupContinue"><input type="hidden" name="operation" value="<?= $escape($operation['operation']) ?>">
<input type="hidden" name="expected_sequence" value="<?= (int)$operation['next_sequence'] ?>">
<label><input type="checkbox" name="confirmed" value="1" required> Los procesos que escriben siguen detenidos. Autorizo hasta 100 pasos consecutivos en esta pestaña.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Ejecutar pasos de copia</button>
<button class="btn btn-outline-secondary nwm-backup-pause" type="button" disabled>Pausar tras el paso actual</button>
<p class="nwm-backup-progress" role="status" aria-live="polite">Preparada. Mantén esta pestaña abierta. Pausar permite terminar la petición actual; no deshace una captura.</p>
</form>
<?php endif; ?>
<details><summary>Historial de mantenimiento (últimas 50 observaciones)</summary>
<p>Observaciones guardadas para esta operación. Las fechas se muestran en UTC.</p>
<ol><?php foreach ($operation['history'] ?? [] as $event): ?>
<li><?= $escape(gmdate('Y-m-d H:i:s', (int)$event['recorded_at'])) ?> — <?= $escape($event['status']) ?>; usuario #<?= (int)$event['actor_id'] ?><?php if ($event['next_sequence'] !== null): ?>; siguiente paso <?= (int)$event['next_sequence'] ?><?php endif; ?></li>
<?php endforeach; ?></ol></details>
</article>
<?php endforeach; ?></section>
<?php endif; ?>
<?php if ($canUpdate): ?>
<section aria-label="Actualizaciones de extensiones"><h2>Actualizaciones preparadas de extensiones</h2>
<p>Prepara y verifica el paquete y la recuperación en el sitio remoto. Después registra aquí el identificador de esa operación. Ejecutarla requiere una confirmación adicional. Una operación incierta solo admite consulta de estado, sin reintentos automáticos.</p>
<?php foreach ($sites as $site): ?>
<details><summary><?= $escape($site['label']) ?></summary>
<?php if ($user->authorise('core.admin', 'com_nicodewebmonitor')): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance" autocomplete="off">
<input type="hidden" name="task" value="updateEnroll"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label>Credencial de actualizaciones para <?= $escape($site['label']) ?> <input type="password" name="update_credential" required autocomplete="new-password"></label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Guardar credencial de actualizaciones</button></form>
<?php endif; ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance">
<input type="hidden" name="task" value="updateBegin"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label>Identificador de operación preparada para <?= $escape($site['label']) ?> <input name="operation" pattern="[a-z0-9-]{8,64}" required></label>
<label><input type="checkbox" name="confirmed" value="1" required> He verificado este paquete local y su procedimiento de recuperación.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Registrar actualización preparada</button></form>
</details><?php endforeach; ?>
<?php foreach ($updateOperations as $op): ?>
<article class="nwm-update"><h3><?= $escape($op['operation']) ?></h3><p>Sitio #<?= (int)$op['site'] ?>; estado: <?= $escape($op['status']) ?> (<?= $escape($operationLabels[$op['status']]??'Consultar estado') ?>)</p>
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
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance"><input type="hidden" name="task" value="updateStatus"><input type="hidden" name="operation" value="<?= $escape($op['operation']) ?>">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Consultar estado de la actualización</button></form>
<?php if ($op['status']==='ready'): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance"><input type="hidden" name="task" value="updateExecute"><input type="hidden" name="operation" value="<?= $escape($op['operation']) ?>">
<label><input type="checkbox" name="confirmed" value="1" required> Quiero ejecutar ahora esta actualización preparada.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary">Ejecutar actualización</button></form>
<?php endif; ?>
<details><summary>Historial de mantenimiento (últimas 50 observaciones)</summary>
<p>Observaciones guardadas para esta operación. Las fechas se muestran en UTC.</p>
<ol><?php foreach ($op['history'] ?? [] as $event): ?>
<li><?= $escape(gmdate('Y-m-d H:i:s', (int)$event['recorded_at'])) ?> — <?= $escape($event['status']) ?>; usuario #<?= (int)$event['actor_id'] ?><?php if ($event['next_sequence'] !== null): ?>; siguiente paso <?= (int)$event['next_sequence'] ?><?php endif; ?></li>
<?php endforeach; ?></ol></details>
</article>
<?php endforeach; ?></section>
<?php endif; ?>
<?php if ($canCoreUpdate): ?>
<section aria-label="Actualizaciones de Joomla"><h2>Actualizaciones preparadas de Joomla</h2>
<p>Prepara y verifica el paquete y la recuperación en el sitio remoto. Registra aquí su operación y confirma por separado el envío a la cola. En cola significa que espera al ejecutor CLI remoto. Verificada significa que otro proceso ha comprobado las versiones instaladas y el esquema de base de datos. Una operación incierta solo admite consulta de estado, sin reintentos automáticos.</p>
<?php foreach ($sites as $site): ?>
<details><summary><?= $escape($site['label']) ?></summary>
<?php if ($user->authorise('core.admin', 'com_nicodewebmonitor')): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance" autocomplete="off">
<input type="hidden" name="task" value="coreUpdateEnroll"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label>Credencial independiente de Joomla para <?= $escape($site['label']) ?> <input type="password" name="core_update_credential" required autocomplete="new-password"></label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Guardar credencial de actualizaciones</button></form>
<?php endif; ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance">
<input type="hidden" name="task" value="coreUpdateBegin"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label>Identificador de operación preparada para <?= $escape($site['label']) ?> <input name="operation" pattern="[a-z0-9-]{8,64}" required></label>
<label><input type="checkbox" name="confirmed" value="1" required> He verificado este paquete local y su procedimiento de recuperación.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Registrar actualización preparada</button></form>
</details><?php endforeach; ?>
<?php foreach ($coreUpdateOperations as $op): ?>
<article class="nwm-update"><h3><?= $escape($op['operation']) ?></h3><p>Sitio #<?= (int)$op['site'] ?>; estado: <?= $escape($op['status']) ?> (<?= $escape($operationLabels[$op['status']]??'Consultar estado') ?>)</p>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance"><input type="hidden" name="task" value="coreUpdateStatus"><input type="hidden" name="operation" value="<?= $escape($op['operation']) ?>">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary">Consultar estado de la actualización</button></form>
<?php if ($op['status']==='ready'): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor#nwm-maintenance"><input type="hidden" name="task" value="coreUpdateExecute"><input type="hidden" name="operation" value="<?= $escape($op['operation']) ?>">
<label><input type="checkbox" name="confirmed" value="1" required> Quiero poner esta actualización de Joomla en cola. El ejecutor CLI remoto debe ejecutarla y verificarla.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary">Poner actualización de Joomla en cola</button></form>
<?php endif; ?>
<details><summary>Historial de mantenimiento (últimas 50 observaciones)</summary>
<p>Observaciones guardadas para esta operación. Las fechas se muestran en UTC.</p>
<ol><?php foreach ($op['history'] ?? [] as $event): ?>
<li><?= $escape(gmdate('Y-m-d H:i:s', (int)$event['recorded_at'])) ?> — <?= $escape($event['status']) ?>; usuario #<?= (int)$event['actor_id'] ?><?php if ($event['next_sequence'] !== null): ?>; siguiente paso <?= (int)$event['next_sequence'] ?><?php endif; ?></li>
<?php endforeach; ?></ol></details>
</article>
<?php endforeach; ?></section>
<?php endif; ?>
