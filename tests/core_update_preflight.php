<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Private synthetic ZIPs; no Joomla writes. */
require __DIR__.'/../tools/CoreUpdatePreflight.php';
use Nicode\Maintenance\CoreUpdatePreflight;
if (count($argv) !== 2 || !is_dir($argv[1])) { exit(2); }
$root = realpath($argv[1]); $checks = [];
function check(bool $ok, string $name): void {
    global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name;
}
function fixture(array $changes = [], ?array $link = null): string {
    global $root;
    $entries = ['administrator/manifests/files/joomla.xml' => '<extension type="file"><name>files_joomla</name><version>6.1.5</version><scriptfile>administrator/components/com_admin/script.php</scriptfile></extension>',
        'libraries/src/Version.php' => '<?php const MAJOR_VERSION=6; const MINOR_VERSION=1; const PATCH_VERSION=5;',
        'includes/framework.php' => '<?php', 'cli/joomla.php' => '<?php', 'administrator/components/com_admin/script.php' => '<?php'];
    foreach ($changes as $name => $value) { if ($value === null) { unset($entries[$name]); } else { $entries[$name] = $value; } }
    $path = $root.'/'.bin2hex(random_bytes(8)).'.zip';
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::EXCL);
    foreach ($entries as $name => $bytes) { $z->addFromString($name, $bytes); }
    if ($link) { $z->setExternalAttributesName($link[0], ZipArchive::OPSYS_UNIX, $link[1] << 16); }
    $z->close(); return $path;
}
function rejected(array $changes, string $label, ?array $link = null): void {
    $p = fixture($changes, $link);
    try { CoreUpdatePreflight::package($p, hash_file('sha256', $p)); }
    catch (RuntimeException $e) { check(true, $label); return; }
    throw new RuntimeException('Accepted: '.$label);
}
$p = fixture(); $package = CoreUpdatePreflight::package($p, hash_file('sha256', $p));
check($package['version'] === '6.1.5', 'valid synthetic core package inspected without executing PHP');
try { CoreUpdatePreflight::package($p, str_repeat('0', 64)); throw new LogicException('digest accepted'); }
catch (RuntimeException $e) { check(true, 'digest mismatch denied'); }
foreach (['../escape', '/absolute', 'C:/drive', 'back\\slash', 'double//slash', 'dot/./file', 'trailing./file', 'nul.txt', 'configuration.php', '.env', 'LIBRARIES/src/Version.php'] as $name) {
    rejected([$name => 'x'], 'unsafe entry denied: '.$name);
}
rejected(['libraries' => 'x'], 'file-directory collision denied');
rejected(['link' => '/outside'], 'symlink denied', ['link', 0120777]);
rejected(['pipe' => ''], 'FIFO denied', ['pipe', 0010644]);
rejected(['administrator/manifests/files/joomla.xml' => '<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///secret">]><extension/>'], 'XML external entity denied');
rejected(['administrator/manifests/files/joomla.xml' => '<extension'], 'malformed XML denied');
rejected(['libraries/src/Version.php' => '<?php const MAJOR_VERSION=6; const MINOR_VERSION=1; const PATCH_VERSION=4;'], 'manifest and PHP versions must agree');
rejected(['cli/joomla.php' => null], 'missing core file denied');
rejected(['installation/index.php' => '<?php'], 'full installation package denied before any effects');
$installed = ['runtime_version'=>'6.1.4', 'manifest_version'=>'6.1.4', 'database_version'=>'6.1.4', 'schema_errors'=>0, 'offline'=>true];
$r = CoreUpdatePreflight::assess($installed, $package);
check($r['status'] === 'preflight_passed' && $r['execution_authorized'] === false, 'passing preflight never authorizes execution');
foreach ([['runtime_version','5.4.0','unsupported_installed_version'], ['database_version','6.1.3','installed_versions_disagree'],
    ['schema_errors',1,'database_schema_not_clean'], ['schema_errors',null,'database_schema_not_clean'], ['offline',false,'site_not_offline']] as [$field,$value,$blocker]) {
    $bad = $installed; $bad[$field] = $value;
    check(in_array($blocker, CoreUpdatePreflight::assess($bad, $package)['blockers'], true), $blocker.' rejected');
}
foreach (['6.1.4', '6.1.3'] as $version) {
    $same = $package; $same['version'] = $version;
    check(in_array('target_not_newer', CoreUpdatePreflight::assess($installed, $same)['blockers'], true), 'same version/downgrade blocked '.$version);
}
echo json_encode(['passed'=>count($checks),'checks'=>$checks], JSON_PRETTY_PRINT)."\n";
