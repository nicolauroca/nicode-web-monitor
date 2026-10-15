<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tools/FileSnapshot.php';
$root = $argv[1]; // Existing private disposable directory outside Git and web roots.
$source = $root . '/source'; $store = $root . '/store';
mkdir($source); mkdir($store); mkdir($source . '/empty'); mkdir($source . '/nested');
file_put_contents($source . '/nested/data.bin', "\x00\xffhello");
file_put_contents($source . '/configuration.php', '<?php // synthetic fixture');
$p = new Nicode\Maintenance\FileSnapshot($store); $checks = [];
function check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; }
function rejects(callable $fn, string $name): void {
    try { $fn(); } catch (Throwable $e) { check(true, $name); return; } check(false, $name);
}
rejects(fn() => $p->capture($source, 'no-consent', false), 'quiescence required');
rejects(fn() => $p->capture($source, '../escape', true), 'invalid operation rejected');
$nested = new Nicode\Maintenance\FileSnapshot($source);
rejects(fn() => $nested->capture($source, 'recursive', true), 'recursive storage rejected');
$small = new Nicode\Maintenance\FileSnapshot($store, 1);
rejects(fn() => $small->capture($source, 'too-large', true), 'size limit enforced');
$limited = new Nicode\Maintenance\FileSnapshot($store, 10000, 1);
rejects(fn() => $limited->capture($source, 'too-many', true), 'entry limit enforced');
$capture = $p->capture($source, 'capture-one', true);
rejects(fn() => $p->capture($source, 'capture-one', true), 'capture cannot repeat');
rejects(fn() => $p->restore('capture-one', 'restore-no', $root . '/unconfirmed', false), 'isolated-target confirmation required');
$restore = $p->restore('capture-one', 'restore-one', $root . '/restored', true);
check(file_get_contents($root . '/restored/nested/data.bin') === "\x00\xffhello", 'binary bytes recovered');
check(is_dir($root . '/restored/empty'), 'empty directory recovered');
check(file_get_contents($root . '/restored/configuration.php') === file_get_contents($source . '/configuration.php'), 'configuration recovered');
rejects(fn() => $p->restore('capture-one', 'overwrite-no', $root . '/restored', true), 'existing target protected');
rejects(fn() => $p->restore('capture-one', 'restore-one', $root . '/retry', true), 'restore operation cannot repeat');
file_put_contents($store . '/capture-one/files/nested/data.bin', 'corrupted');
rejects(fn() => $p->restore('capture-one', 'corrupt-no', $root . '/corrupt', true), 'corrupt payload rejected');
check(!file_exists($root . '/corrupt'), 'corruption rejected before target creation');
file_put_contents($store . '/capture-one/files/nested/data.bin', "\x00\xffhello");
file_put_contents($store . '/capture-one/files/extra.txt', 'unexpected');
rejects(fn() => $p->restore('capture-one', 'extra-file-no', $root . '/extra', true), 'unlisted payload rejected');
unlink($store . '/capture-one/files/extra.txt');
$manifest = $store . '/capture-one/manifest.json';
$original = file_get_contents($manifest);
file_put_contents($manifest, '{}');
rejects(fn() => $p->restore('capture-one', 'manifest-no', $root . '/manifest', true), 'manifest corruption rejected');
file_put_contents($manifest, $original);
$forged = json_decode($original, true); $forged['../escape'] = $forged['configuration.php'];
file_put_contents($manifest, json_encode($forged));
$completed = $store . '/capture-one/completed.json';
$state = json_decode(file_get_contents($completed), true); $state['manifest_sha256'] = hash_file('sha256', $manifest);
file_put_contents($completed, json_encode($state));
rejects(fn() => $p->restore('capture-one', 'traversal-no', $root . '/traversal', true), 'forged path rejected despite matching manifest hash');
check(!file_exists($root . '/traversal'), 'forged path causes no target writes');
check(!$capture['restoration_verified'] && !$restore['application_verified'], 'no application recovery claim');
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT);
