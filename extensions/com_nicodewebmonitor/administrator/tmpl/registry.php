<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
$stateLabels = ['never_checked'=>'Never checked', 'recent_complete'=>'Recent complete inventory', 'recent_partial'=>'Recent partial inventory', 'stale'=>'Observation older than 24 hours', 'check_failed'=>'Latest check failed', 'clock_uncertain'=>'Observation time uncertain'];
$escape = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<style>
.nwm-sites td { overflow-wrap: anywhere; vertical-align: top; }
.nwm-sites .btn { margin-bottom: .5rem; }
@media (max-width: 991.98px) {
    .nwm-sites, .nwm-sites tbody, .nwm-sites tr, .nwm-sites td { display: block; width: 100%; }
    .nwm-sites thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    .nwm-sites tr { margin-bottom: 1rem; border: 1px solid var(--border-color, #adb5bd); }
    .nwm-sites td { border: 0; padding: .75rem; }
    .nwm-sites td::before { content: attr(data-label); display: block; font-weight: bold; margin-bottom: .25rem; }
    .nwm-sites caption { display: block; }
    .nwm-sites .btn { width: 100%; }
}
</style>
<h1>Nicode Web Monitor</h1>
<p>Development monitor. Check a site to read its inventory now. Check summaries are retained (last 100 per site). Recent means checked and collected within 24 hours. Backups require a separately configured connector; updates remain pending.</p>
<?php if (isset($inspection)): ?>
<section aria-label="Inventory result">
<?php if (($inspection['history_saved'] ?? true) === false): ?><p role="alert">This check could not be saved to history. No retry was made.</p><?php endif; ?>
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
<div class="table-responsive"><table class="table nwm-sites"><caption>Enrolled sites — connector observations</caption>
<thead><tr><th scope="col">Site</th><th scope="col">URL</th><th scope="col">Pinned IP</th><th scope="col">Identity</th><th scope="col">Check history (UTC)</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($sites as $site): ?>
<tr><td data-label="Site"><?= $escape($site['label']) ?></td><td data-label="URL"><?= $escape($site['base_url']) ?></td><td data-label="Pinned IP"><?= $escape($site['address']) ?></td><td data-label="Identity"><?= $escape($site['site_id']) ?></td><td data-label="Check history (UTC)">
<p><?= $escape($stateLabels[$site['check_state'] ?? 'never_checked'] ?? 'Unknown') ?></p>
<p>Connector observations do not establish website availability.</p>
<?php if (isset($site['alerts'])): $alerts = $site['alerts']; ?>
<p><strong><?= $alerts['active'] ? 'Active inventory alert' : ($alerts['state'] === 'never_checked' ? 'Awaiting first check' : 'No active inventory alert') ?></strong></p>
<?php if ($alerts['transitions']): ?><details><summary>Alert transitions (retained history)</summary><ol>
<?php foreach ($alerts['transitions'] as $event): ?>
<li><?= $escape(gmdate('Y-m-d H:i:s', (int)$event['at'])) ?> UTC: <?= $escape($event['kind']) ?> — <?= $escape($stateLabels[$event['from']] ?? 'Unknown') ?> → <?= $escape($stateLabels[$event['to']] ?? 'Unknown') ?></li>
<?php endforeach; ?></ol></details><?php endif; ?>
<?php endif; ?>
<?php if (!empty($site['checks'])): ?><details><summary>Recent checks</summary><ol>
<?php foreach ($site['checks'] as $check): ?>
<li><?= $escape(gmdate('Y-m-d H:i:s', (int)$check['checked_at'])) ?> — <?= $escape($check['outcome']) ?>: <?= $escape($check['detail']) ?></li>
<?php endforeach; ?></ol></details><?php endif; ?></td><td data-label="Actions">
<form method="post" action="index.php?option=com_nicodewebmonitor"><input type="hidden" name="task" value="inspect"><input type="hidden" name="id" value="<?= (int) $site['id'] ?>"><?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary" type="submit">Check <?= $escape($site['label']) ?></button></form>
<?php if ($user->authorise('core.delete', 'com_nicodewebmonitor')): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor"><input type="hidden" name="task" value="remove"><input type="hidden" name="id" value="<?= (int) $site['id'] ?>"><?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-danger" type="submit">Remove <?= $escape($site['label']) ?></button></form>
<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if ($canBackup ?? false): ?>
<section aria-label="Remote backups">
<h2>Remote backups</h2>
<p>Files and database capture requires the connector backup provider and a separate credential. Stop all writers before advancing; confirmation here does not stop them. Create an operation once, then advance one bounded step at a time. An uncertain result requires a status query, never a new operation as a retry. Completion does not establish a tested restoration.</p>
<?php foreach ($sites as $site): ?>
<details><summary>Backup: <?= $escape($site['label']) ?></summary>
<?php if ($user->authorise('core.admin', 'com_nicodewebmonitor')): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor" autocomplete="off">
<input type="hidden" name="task" value="backupEnroll"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label for="backup-secret-<?= (int)$site['id'] ?>">Separate backup credential</label>
<input class="form-control" id="backup-secret-<?= (int)$site['id'] ?>" name="backup_credential" type="password" required pattern="[a-f0-9]{64}" maxlength="64" autocomplete="new-password">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary" type="submit">Save backup credential</button></form>
<?php endif; ?>
<form method="post" action="index.php?option=com_nicodewebmonitor">
<input type="hidden" name="task" value="backupBegin"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
<label><input type="checkbox" name="confirmed" value="1" required> Create a new operation for <?= $escape($site['label']) ?> (no remote capture yet).</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Create backup operation</button></form>
</details>
<?php endforeach; ?>
<h3>Latest 100 operations</h3>
<p>This journal shows current state, not an immutable event history.</p>
<?php foreach ($backupOperations as $operation): ?>
<article class="nwm-backup" style="overflow-wrap:anywhere">
<h4><?= $escape($operation['operation']) ?></h4>
<p>Site #<?= (int)$operation['site'] ?>; state: <?= $escape($operation['status']) ?>; next sequence: <?= (int)$operation['next_sequence'] ?>; updated <?= $escape(gmdate('Y-m-d H:i:s', (int)$operation['updated_at'])) ?> UTC.</p>
<form method="post" action="index.php?option=com_nicodewebmonitor">
<input type="hidden" name="task" value="backupStatus"><input type="hidden" name="operation" value="<?= $escape($operation['operation']) ?>">
<?= HTMLHelper::_('form.token') ?><button class="btn btn-outline-primary" type="submit">Query backup status</button></form>
<?php if (in_array($operation['status'], ['ready','progress'], true)): ?>
<form method="post" action="index.php?option=com_nicodewebmonitor">
<input type="hidden" name="task" value="backupAdvance"><input type="hidden" name="operation" value="<?= $escape($operation['operation']) ?>">
<label><input type="checkbox" name="confirmed" value="1" required> All writers are stopped; advance this operation by one step.</label>
<?= HTMLHelper::_('form.token') ?><button class="btn btn-primary" type="submit">Advance backup step</button></form>
<?php endif; ?></article>
<?php endforeach; ?></section>
<?php endif; ?>
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
