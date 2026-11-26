<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
?>
<section class="nwm-panel nwm-detail-panel">
        <section><h2>Conexión del sitio</h2><dl class="nwm-tech"><dt>Identidad del conector</dt><dd><?= $escape($site['site_id']) ?></dd><dt>Destino</dt><dd><?= ($site['connection_type']??'https')==='local'?'Inventario local sin peticiones HTTP al propio sitio':$escape($site['address']) ?></dd></dl></section>
        <?php if($canDelete): ?><details class="nwm-remove"><summary>Eliminar registro</summary><p>Elimina este sitio y su historial del panel. El acceso remoto se revoca por separado desde el Conector.</p><form method="post" action="index.php?option=com_nicodewebmonitor"><input type="hidden" name="task" value="remove"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>"><label class="nwm-confirm"><input type="checkbox" name="confirmed" value="1" required><span>Quiero eliminar el registro de <?= $escape($site['label']) ?> y su historial.</span></label><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--danger" type="submit" aria-label="Eliminar registro de <?= $escape($site['label']) ?>">Eliminar registro</button></form></details><?php endif; ?>
</section>
