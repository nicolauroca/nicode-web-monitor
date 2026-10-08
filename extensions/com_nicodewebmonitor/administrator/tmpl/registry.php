<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
$escape = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<h1>Nicode Web Monitor</h1>
<p>Development site registry. Sites are not yet checked here; monitoring and maintenance are pending.</p>
<div class="table-responsive"><table class="table"><caption>Enrolled sites — availability unknown</caption>
<thead><tr><th scope="col">Site</th><th scope="col">URL</th><th scope="col">Pinned IP</th><th scope="col">Identity</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($sites as $site): ?>
<tr><td><?= $escape($site['label']) ?></td><td><?= $escape($site['base_url']) ?></td><td><?= $escape($site['address']) ?></td><td><?= $escape($site['site_id']) ?></td><td>
<?php if ($user->authorise('core.delete', 'com_nicodewebmonitor')): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor"><input type="hidden" name="task" value="remove"><input type="hidden" name="id" value="<?= (int) $site['id'] ?>"><?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-danger" type="submit">Remove <?= $escape($site['label']) ?></button></form>
<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if ($user->authorise('core.create', 'com_nicodewebmonitor')): ?>
<h2>Enroll a site</h2>
<p>Use its canonical HTTPS URL, public IP, connector UUID and read-only credential. Enrollment saves configuration; it does not verify connectivity. Removing a site does not revoke its remote credential.</p>
<form method="post" action="index.php?option=com_nicodewebmonitor" autocomplete="off">
<?php foreach (['label'=>'Site name', 'base_url'=>'HTTPS base URL', 'address'=>'Public IP', 'site_id'=>'Connector UUID', 'credential'=>'Read-only credential'] as $field=>$title): ?>
<div class="mb-3"><label for="nwm-<?= $field ?>"><?= $title ?></label><input class="form-control" id="nwm-<?= $field ?>" name="<?= $field ?>" type="<?= $field === 'credential' ? 'password' : 'text' ?>" maxlength="<?= $field === 'base_url' ? 2048 : 120 ?>" required autocomplete="<?= $field === 'credential' ? 'new-password' : 'off' ?>"></div>
<?php endforeach; ?>
<input type="hidden" name="task" value="add"><?= HTMLHelper::_('form.token') ?><button type="submit" class="btn btn-primary">Enroll site</button>
</form>
<?php endif; ?>
