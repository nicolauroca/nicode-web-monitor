<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
?>
<section id="nwm-sharing" class="nwm-panel nwm-sharing" aria-label="Permisos de consulta">
<div class="nwm-panel-heading"><span class="nwm-heading-icon"><?= $icon('shield') ?></span><div><p class="nwm-section-label">Tú decides qué se comparte</p><h2>Información que compartes</h2></div></div>
<div class="nwm-sharing-overview"><strong><?= count(array_filter($sharing)) ?> de <?= count($sharingCategories) ?> categorías permitidas</strong><p>El central solo puede consultar las categorías que permitas aquí. Las nuevas categorías están desactivadas de entrada.</p></div>
<details class="nwm-sharing-options" <?= $this->input->getBool('sharing')?'open':'' ?>><summary>Elegir categorías de información<?= $icon('chevron') ?></summary>
<?php if($canAdmin): ?><form method="post" action="index.php?option=com_nicodeconnector" class="nwm-sharing-form"><input type="hidden" name="task" value="saveSharing">
<div class="nwm-sharing-presets" hidden data-nwm-sharing-presets><span>Seleccionar:</span><button class="nwm-text-button" type="button" data-nwm-sharing-profile="essential">Esencial</button><button class="nwm-text-button" type="button" data-nwm-sharing-profile="all">Todo</button><button class="nwm-text-button" type="button" data-nwm-sharing-profile="none">Nada</button></div><?php endif; ?>
<div class="nwm-sharing-list"><?php foreach($sharingCategories as $key=>[$label,$description,$essential]): ?>
<div class="nwm-sharing-category"><<?= $canAdmin?'label':'div' ?> class="nwm-sharing-label" <?= $canAdmin?'for="sharing-'.$key.'"':'' ?>><?php if($canAdmin): ?><input id="sharing-<?= $key ?>" type="checkbox" name="sharing[<?= $key ?>]" value="1" <?= $sharing[$key]?'checked':'' ?> data-essential="<?= $essential?'1':'0' ?>" aria-describedby="sharing-help-<?= $key ?>"><?php endif; ?><span><strong><?= $escape($label) ?></strong><small id="sharing-help-<?= $key ?>"><?= $escape($description) ?></small></span></<?= $canAdmin?'label':'div' ?>><span class="nwm-sharing-state" data-sharing-state><?= $sharing[$key]?'Permitido':'No compartido' ?></span></div>
<?php endforeach; ?></div>
<?php if($canAdmin): ?><?= HTMLHelper::_('form.token') ?><div class="nwm-sharing-save"><button class="nwm-button nwm-button--primary" type="submit">Guardar permisos de consulta</button><p role="status" data-nwm-sharing-status>Los cambios se aplican al guardar.</p></div></form><?php else: ?><p class="nwm-field-help">Solo un administrador de este componente puede cambiar estos permisos.</p><?php endif; ?>
</details><p class="nwm-sharing-note">Denegar una categoría impide que se lea y se envíe en las consultas siguientes. Central conserva su última observación hasta recibir otra; esta opción no borra datos que ya haya recibido. La identidad de vinculación y la fecha de consulta siguen siendo necesarias. Copias y actualizaciones mantienen sus permisos independientes.</p>
</section>
