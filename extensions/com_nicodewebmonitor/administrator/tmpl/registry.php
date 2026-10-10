<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
$escape = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<h1>Nicode Web Monitor</h1>
<p>Development monitor. Check a site to read its inventory now. Results are not retained; automatic monitoring and maintenance are pending.</p>
<?php if (isset($inspection)): ?>
<section aria-label="Inventory result">
<h2>Inventory check for site #<?= (int) $inspectedId ?></h2>
<?php if (!$inspection['ok']): ?>
<p role="alert">Inventory unavailable: <?= $escape($inspection['error']) ?>. This does not establish that the website is offline. No retry was made.</p>
<?php else: $inventory = $inspection['inventory']; ?>
<p>Collection: <?= $escape($inventory['collected_at']) ?>; received: <?= $escape($inspection['received_at']) ?>. Result: <?= $escape($inventory['status']) ?>. These are point-in-time observations, not a guarantee of current health.</p>
<table class="table"><caption>Inventory section availability</caption><thead><tr><th scope="col">Section</th><th scope="col">Status</th></tr></thead><tbody>
<?php foreach (['joomla','runtime','database','extensions','hosting'] as $section): $value=$inventory['sections'][$section]; ?>
<tr><th scope="row"><?= $escape($section) ?></th><td><?= $escape($value['status']) ?></td></tr>
<?php endforeach; ?></tbody></table>
<h3>Software and runtime</h3>
<dl>
<?php foreach (['Joomla'=>['joomla','version'], 'PHP'=>['runtime','php_version'], 'PHP runtime'=>['runtime','sapi'], 'Operating system'=>['runtime','os_family'], 'Database'=>['database','version']] as $label=>$path):
    $section=$inventory['sections'][$path[0]];
    $detail=$section['status']==='available' ? ($section['data'][$path[1]] ?? null) : null;
?>
<dt><?= $escape($label) ?></dt><dd><?= is_scalar($detail) && (string)$detail !== '' ? $escape($detail) : 'Unknown / unavailable' ?></dd>
<?php endforeach; ?></dl>
<?php $extensionSection=$inventory['sections']['extensions']; if ($extensionSection['status']==='available' && is_array($extensionSection['data'])): ?>
<div class="table-responsive"><table class="table"><caption>Installed extensions</caption>
<thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Version</th><th scope="col">Enabled</th></tr></thead><tbody>
<?php foreach ($extensionSection['data'] as $extension): if (!is_array($extension)) { continue; }
    $text = static fn($value) => is_scalar($value) ? $escape($value) : 'Unknown';
    $version=is_array($extension['version'] ?? null) ? $extension['version'] : [];
?>
<tr><th scope="row"><?= $text($extension['name'] ?? null) ?></th><td><?= $text($extension['type'] ?? null) ?></td><td><?= ($version['status'] ?? '')==='available' ? $text($version['value'] ?? null) : 'Unknown' ?></td><td><?= ($extension['enabled'] ?? null)===true ? 'Yes' : (($extension['enabled'] ?? null)===false ? 'No' : 'Unknown') ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php else: ?><p>Extension inventory unavailable.</p><?php endif; ?>
<?php endif; ?></section>
<?php endif; ?>
<div class="table-responsive"><table class="table"><caption>Enrolled sites — availability unknown</caption>
<thead><tr><th scope="col">Site</th><th scope="col">URL</th><th scope="col">Pinned IP</th><th scope="col">Identity</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($sites as $site): ?>
<tr><td><?= $escape($site['label']) ?></td><td><?= $escape($site['base_url']) ?></td><td><?= $escape($site['address']) ?></td><td><?= $escape($site['site_id']) ?></td><td>
<form method="post" action="index.php?option=com_nicodewebmonitor"><input type="hidden" name="task" value="inspect"><input type="hidden" name="id" value="<?= (int) $site['id'] ?>"><?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary" type="submit">Check <?= $escape($site['label']) ?></button></form>
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
