<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
$escape=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$connected=$state['state']==='connected'; $pending=$state['state']==='pending';
?>
<h1>Nicode Web Monitor — Conector</h1>
<p><span class="badge bg-warning text-dark">Versión de desarrollo · 1.1.1-dev.2 · No definitiva</span></p>
<p>Conecta este Joomla a tu panel Nicode Web Monitor. También puedes conectar el Joomla que aloja el propio panel.</p>
<section aria-label="Estado de conexión">
<h2><?= $connected ? 'Conectado' : ($pending ? 'Conexión pendiente de confirmar' : 'Conectar este sitio') ?></h2>
<?php if ($connected || $pending): ?>
<p>Central: <?= $escape($state['central']) ?></p>
<?php if (($state['connection_type']??'https')==='local'): ?><p>Este mismo Joomla · Inventario local sin conexiones HTTP al propio servidor.</p><?php endif; ?>
<?php if ($connected): ?><p>Primera comprobación realizada: <?= $escape($state['connected_at']) ?> UTC. Consulta las últimas comprobaciones y su programación en el panel central.</p><?php endif; ?>
<?php if (!$state['enabled']): ?><p role="alert">El acceso de inventario está desactivado. Desconecta y vuelve a vincular para restablecerlo.</p><?php endif; ?>
<?php endif; ?>
<?php if ($user->authorise('core.admin','com_nicodeconnector')): ?>
<?php if (!$connected): ?>
<ol><li>En el panel central, abre «Añadir sitio» y genera un código.</li><li>Introduce su dirección y pega aquí el código. Caduca a los diez minutos.</li><li>Pulsa «Conectar». La identidad y las credenciales se preparan automáticamente.</li></ol>
<?php if ($pending): ?><p>Si se perdió la respuesta, repite el mismo código. El central recuperará el resultado sin crear otro sitio. Para usar un código nuevo, cancela primero este intento.</p><?php endif; ?>
<form method="post" action="index.php?option=com_nicodeconnector" autocomplete="off">
<div class="mb-3"><label for="central-url">Dirección del Joomla central</label><input id="central-url" class="form-control" type="url" name="central_url" value="<?= $escape($state['central']) ?>" placeholder="https://central.ejemplo.es" maxlength="2048" required></div>
<div class="mb-3"><label for="connection-code">Código de vinculación</label><input id="connection-code" class="form-control" type="password" name="code" pattern="[a-f0-9]{64}" minlength="64" maxlength="64" autocomplete="new-password" required></div>
<input type="hidden" name="task" value="connect"><?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Conectar</button>
</form>
<?php endif; ?>
<?php if ($connected || $pending): ?>
<form method="post" action="index.php?option=com_nicodeconnector" class="mt-4">
<input type="hidden" name="task" value="disconnect"><label><input type="checkbox" name="confirmed" value="1" required> Revocar el acceso de inventario de este central.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-danger" type="submit"><?= $pending ? 'Cancelar intento' : 'Desconectar' ?></button>
</form>
<?php endif; endif; ?></section>
<?php if (\Joomla\CMS\Component\ComponentHelper::isInstalled('com_nicodewebmonitor')): ?><p><a href="index.php?option=com_nicodewebmonitor">Abrir Nicode Web Monitor — Central</a></p><?php endif; ?>
<details class="mt-4"><summary>Copias y actualizaciones (avanzado)</summary>
<p>La vinculación activa únicamente el inventario. Las funciones experimentales de mantenimiento requieren preparación y permisos independientes.</p>
<p><a href="index.php?option=com_plugins&amp;view=plugins&amp;filter[search]=Nicode">Configuración avanzada del motor de conexión</a></p>
</details>
