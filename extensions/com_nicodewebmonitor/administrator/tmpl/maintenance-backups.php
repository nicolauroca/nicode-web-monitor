<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
?>
<?php if ($canBackup ?? false): ?>
<section aria-label="Copias de seguridad">
<h2>Copias de seguridad</h2>
<p class="nwm-tool-summary">Captura experimental de archivos y base de datos. Una copia completa no acredita una restauración probada.</p>
<?= $maintenanceHelp('Cómo funciona la captura','Captura archivos y base de datos con el proveedor de copias del Conector y una credencial independiente. Detén todos los procesos que escriban antes de avanzar; marcar la confirmación aquí no los detiene. Crea una única operación y avanza por pasos o con un lote confirmado. Ante un resultado incierto, consulta su estado antes de hacer nada más. Una copia completa no acredita una restauración probada.') ?>
<?php foreach ($sites as $site): if($canDispatch): ?>
<details><summary>Preparar copia de <?= $escape($site['label']) ?></summary>
<?php if ($canDispatch && $user->authorise('core.admin', 'com_nicodewebmonitor')): ?><details class="nwm-maint-access" <?= !$maintenanceCredentialStored?'open':'' ?>><summary>Configurar acceso avanzado</summary><p>Usa la credencial de esta capacidad preparada en el Conector. Conectar inventario no concede este acceso.</p>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>" autocomplete="off">
<input type="hidden" name="task" value="backupEnroll"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label for="backup-secret-<?= (int)$site['id'] ?>">Credencial independiente de copias</label>
<input class="form-control" id="backup-secret-<?= (int)$site['id'] ?>" name="backup_credential" type="password" required pattern="[a-f0-9]{64}" maxlength="64" autocomplete="new-password">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary" type="submit">Guardar credencial de copias</button></form>
</details><?php endif; ?>
<?php if($canDispatch): ?><form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>">
<input type="hidden" name="task" value="backupBegin"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label><input type="checkbox" name="confirmed" value="1" required> Crear una operación nueva para <?= $escape($site['label']) ?> (todavía no captura datos remotos).</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Crear operación de copia</button></form><?php endif; ?>
</details>
<?php endif; endforeach; ?>
<h3>Últimas 100 operaciones</h3>
<p>Este registro muestra el estado actual de cada operación. Despliega su historial para consultar las observaciones guardadas.</p>
<?php if(!$sites): ?><p>No hay sitios registrados para preparar una copia.</p><?php endif; ?>
<?php foreach ($backupOperations as $operationIndex=>$operation): ?>
<article class="nwm-backup" style="overflow-wrap:anywhere" data-site="<?= (int)$operation['site'] ?>" data-operation="<?= $escape($operation['operation']) ?>" data-sequence="<?= (int)$operation['next_sequence'] ?>">
<details class="nwm-maint-operation" <?= $operationIndex===0?'open':'' ?>><summary><span>Copia · <?= $escape($operationLabels[$operation['status']]??$operation['status']) ?></span><time><?= $escape(gmdate('Y-m-d H:i:s', (int)$operation['updated_at'])) ?> UTC</time></summary><div class="nwm-maint-operation-body">
<h4><?= $escape($operation['operation']) ?></h4>
<p>Sitio #<?= (int)$operation['site'] ?>; estado: <?= $escape($operation['status']) ?> (<?= $escape($operationLabels[$operation['status']]??'Consultar estado') ?>); siguiente paso: <?= (int)$operation['next_sequence'] ?>; actualizado <?= $escape(gmdate('Y-m-d H:i:s', (int)$operation['updated_at'])) ?> UTC.</p>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>">
<input type="hidden" name="task" value="backupStatus"><input type="hidden" name="operation" value="<?= $escape($operation['operation']) ?>">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary" type="submit">Consultar estado de la copia</button></form>
<?php if ($operation['status']==='uncertain'): ?><p class="nwm-action-warning" role="status">Resultado sin confirmar. Consulta el estado antes de avanzar o crear otra operación.</p><?php endif; ?>
<?php if ($canDispatch && in_array($operation['status'], ['ready','progress'], true)): ?>
<p class="nwm-action-warning">Detén todos los procesos que escriben antes de avanzar. Poner Joomla offline no detiene cron, colas ni otros administradores.</p>
<form method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>">
<input type="hidden" name="task" value="backupAdvance"><input type="hidden" name="operation" value="<?= $escape($operation['operation']) ?>">
<label><input type="checkbox" name="confirmed" value="1" required> He detenido todos los procesos que escriben. Quiero avanzar un paso en esta operación.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Avanzar un paso</button></form>
<form class="nwm-backup-runner" method="post" action="<?= $escape($maintenanceUrl($viewTool)) ?>" hidden>
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
</div></details></article>
<?php endforeach; ?></section>
<?php endif; ?>
