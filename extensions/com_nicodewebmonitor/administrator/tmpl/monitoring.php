<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
$monitorLabels=['pending'=>['Sin preparar','neutral','Prepara la tarea para empezar las comprobaciones periódicas.'],
 'paused'=>['En pausa','warning','La tarea o su plugin están desactivados. Revisa su configuración para continuar.'],
 'permissions'=>['Revisar permisos','danger','La cuenta propietaria o ejecutora no tiene los permisos necesarios.'],
 'waiting'=>['Esperando ejecución','neutral','La tarea está preparada. Aún no consta una ejecución automática.'],
 'late'=>['Sin ejecución reciente','warning','Revisa el disparador de tareas del hosting y la programación de Joomla.'],
 'running'=>['Ejecución reciente verificada','success','La última ejecución automática registrada ha terminado correctamente.'],
 'failed'=>['Última ejecución con errores','danger','Revisa las observaciones de los sitios y el resultado de la tarea.']];
$monitor=$monitorLabels[$monitoring['state']]??['Revisar programación','warning','Consulta la tarea de Joomla.'];
?>
<section id="nwm-monitoring" class="nwm-panel nwm-monitoring" aria-label="Comprobaciones automáticas">
<div class="nwm-monitoring-main"><span class="nwm-monitoring-icon"><?= $icon('clock') ?></span><div><h2>Comprobaciones automáticas</h2><p><?= $escape($monitor[2]) ?></p></div><span class="nwm-badge nwm-badge--<?= $monitor[1] ?>"><?= $escape($monitor[0]) ?></span></div>
<div class="nwm-monitoring-times"><span>Última ejecución <strong><?= $monitoring['last']?$escape($date($monitoring['last'])).' UTC':'Todavía no registrada' ?></strong></span><span>Próxima prevista <strong><?= $monitoring['next']?$escape($date($monitoring['next'])).' UTC':'Pendiente de configurar' ?></strong></span></div>
<?php if($monitoring['tasks']>1): ?><p class="nwm-notice nwm-notice--warning" role="alert">Hay varias tareas Nicode. Revisa la programación para evitar comprobaciones repetidas.</p><?php endif; ?>
<?php if($canAdmin): ?>
<?php if($monitoring['state']==='pending'): ?><form method="post" action="index.php?option=com_nicodewebmonitor#nwm-monitoring" class="nwm-setup-monitoring" data-nwm-submit><input type="hidden" name="task" value="setupMonitoring"><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--secondary" type="submit"><span data-nwm-button-label>Preparar comprobaciones automáticas</span></button></form><?php endif; ?>
<details class="nwm-monitoring-help"><summary>Programación y ejecución en el hosting<?= $icon('chevron') ?></summary><div><p>La tarea inicial consulta hasta cinco sitios cada cinco minutos. Para mantener los intervalos aunque no haya visitas, configura un cron en el hosting central cada minuto:</p><pre><code><?= $escape('php '.JPATH_ROOT.'/cli/joomla.php scheduler:run --all') ?></code></pre><p>Usa la versión de PHP compatible de tu hosting. Si eliges cron, desactiva la ejecución por visitas en las opciones del programador. Solo necesitas cron en el central.</p><div class="nwm-link-row"><a href="index.php?option=com_scheduler&amp;view=tasks">Revisar tareas de Joomla<?= $icon('arrow') ?></a><a href="index.php?option=com_config&amp;view=component&amp;component=com_scheduler">Opciones del programador<?= $icon('arrow') ?></a></div></div></details>
<?php endif; ?></section>
