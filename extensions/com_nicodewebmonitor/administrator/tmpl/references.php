<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
?>
<div class="nwm-reference-note"><div><strong>Referencias oficiales</strong><p>PHP: <?= $escape($date($releaseCatalog['php']['checked_at']??null)) ?> UTC · Joomla: <?= $escape($date($releaseCatalog['joomla']['checked_at']??null)) ?> UTC. Caducan a los 7 días; la tarea comprueba cambios diariamente.</p></div><?php if($canReferences): ?><form method="post" action="<?= $escape($selectedSiteId?$siteUrl($selectedSiteId,'health'):$pageUrl('sites')) ?>" data-nwm-submit><input type="hidden" name="task" value="refreshReferences"><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--secondary" type="submit"><span data-nwm-button-label>Actualizar referencias</span></button></form><?php endif; ?></div>
