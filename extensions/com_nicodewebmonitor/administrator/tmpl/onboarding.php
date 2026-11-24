<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
$monitoring=$monitoring??['state'=>'pending','last'=>null,'next'=>null,'tasks'=>0];
$monitorLabels=['pending'=>'Pendiente de preparar','paused'=>'Comprobaciones pausadas','permissions'=>'Revisar permisos del usuario de monitorización',
    'waiting'=>'Tarea preparada; esperando la primera ejecución automática','late'=>'Sin ejecuciones recientes: revisa el disparador del hosting',
    'running'=>'Comprobaciones automáticas verificadas recientemente','failed'=>'La última ejecución encontró errores; revisa los sitios y la tarea'];
?>
<p><span class="badge bg-warning text-dark">Versión de desarrollo · 1.1.1-dev.1 · No definitiva</span></p>
<?php if ($user->authorise('core.manage','com_nicodewebmonitor') && \Joomla\CMS\Component\ComponentHelper::isInstalled('com_nicodeconnector')): ?><p><a href="index.php?option=com_nicodeconnector">Nicode Web Monitor — Conector de este Joomla</a></p><?php endif; ?>
<section aria-label="Comprobaciones automáticas" class="card card-body mb-4">
<h2>Comprobaciones automáticas</h2>
<p role="status"><strong><?= $escape($monitorLabels[$monitoring['state']]??'Revisar programación') ?></strong></p>
<p>Última ejecución (UTC): <?= $escape($monitoring['last']??'Todavía no se ha ejecutado') ?>.<br>
Próxima ejecución prevista (UTC): <?= $escape($monitoring['next']??'Pendiente') ?>.</p>
<?php if ($monitoring['tasks']>1): ?><p role="alert">Hay varias tareas Nicode. Revisa la programación para evitar comprobaciones repetidas.</p><?php endif; ?>
<?php if ($user->authorise('core.admin','com_nicodewebmonitor')): ?>
<?php if ($monitoring['state']==='pending'): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor"><input type="hidden" name="task" value="setupMonitoring">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Preparar comprobaciones automáticas</button></form>
<?php endif; ?>
<details><summary>Configurar la ejecución en mi hosting</summary>
<p>La tarea consulta hasta cinco sitios cada cinco minutos. Joomla puede ejecutarla cuando recibe visitas. Para mantener los intervalos aunque no haya visitas, configura una tarea cron en el hosting central, cada minuto:</p>
<pre><code><?= $escape('php '.JPATH_ROOT.'/cli/joomla.php scheduler:run --all') ?></code></pre>
<p>Selecciona en el hosting la misma versión de PHP que utiliza este Joomla. Si utilizas cron, desactiva el programador por visitas en las opciones de tareas. No hace falta configurar un cron en cada sitio supervisado.</p>
<p><a href="index.php?option=com_scheduler&amp;view=tasks">Revisar tareas de Joomla</a> · <a href="index.php?option=com_config&amp;view=component&amp;component=com_scheduler">Opciones del programador</a></p>
</details>
<?php endif; ?></section>
<?php if ($user->authorise('core.create','com_nicodewebmonitor')): ?>
<section aria-label="Añadir sitio" class="card card-body mb-4">
<h2>Añadir sitio</h2>
<p>Instala el paquete Nicode Web Monitor — Conector en el Joomla que quieras supervisar. Después abre allí Componentes → Nicode Web Monitor — Conector.</p>
<form method="post" action="index.php?option=com_nicodewebmonitor" autocomplete="off">
<label for="new-site-name">Nombre para identificar el sitio</label><input id="new-site-name" name="label" class="form-control mb-3" maxlength="120" required>
<input type="hidden" name="task" value="issueCode"><?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Generar código de vinculación</button>
</form>
<?php if (!empty($pairingCode)): ?>
<div role="status" class="alert alert-info mt-3">
<p>En el conector del sitio, introduce estos dos datos y pulsa «Conectar». El código caduca en diez minutos; generar otro sustituye al anterior.</p>
<label for="central-address">Dirección de este central</label><input id="central-address" class="form-control mb-3" value="<?= $escape(rtrim(Uri::root(),'/')) ?>" readonly>
<label for="pairing-code">Código de vinculación</label><input id="pairing-code" class="form-control" value="<?= $escape($pairingCode) ?>" readonly autocomplete="off" spellcheck="false">
<p>El código permite vincular un solo sitio. Trátalo como una contraseña temporal. Al volver al panel verás el sitio y su primer inventario.</p>
</div>
<?php endif; ?>
<details class="mt-3"><summary>Monitorizar también este Joomla central</summary>
<p>Instala aquí el paquete Nicode Web Monitor — Conector y vincúlalo con un código de este mismo panel. Central y conector pueden convivir y también conectarse a otro central.</p>
<?php if (\Joomla\CMS\Component\ComponentHelper::isInstalled('com_nicodeconnector')): ?><p><a href="index.php?option=com_nicodeconnector">Abrir Nicode Web Monitor — Conector en este Joomla</a></p><?php endif; ?>
</details>
</section>
<?php endif; ?>
