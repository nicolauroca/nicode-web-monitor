<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tools/ResumableTree.php';
require dirname(__DIR__) . '/tools/TreeRestore.php';
use Nicode\Maintenance\ResumableTree;
use Nicode\Maintenance\TreeRestore;
$root = $argv[1];
foreach (['source','source/empty','source/sub','store','target','reject'] as $p) { mkdir($root . '/' . $p); }
$data = random_bytes(1048576) . "\0tail";
file_put_contents($root . '/source/sub/blob', $data);
file_put_contents($root . '/source/123', '');
$capture = new ResumableTree($root . '/store');
for ($i=0; $i<100; $i++) {
    $r = $capture->step($root . '/source', 'restore-test', true);
    if ($r['status'] === 'complete') { break; }
}
$checks = [];
function check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; }
function denied(Closure $fn, string $name): void { $ok = false; try { $fn(); } catch (Throwable) { $ok = true; } check($ok, $name); }
check($r['status'] === 'complete', 'real capture completes');
$path = $root . '/store/restore-test'; $restore = new TreeRestore;
denied(fn() => $restore->restore($path, $root . '/target', false), 'explicit confirmation required');
denied(fn() => $restore->restore($path, $path, true), 'overlap refused');
$result = $restore->restore($path, $root . '/target', true);
check($result['status'] === 'files_restored' && !$result['application_verified'], 'receipt distinguishes file and application recovery');
check(file_get_contents($root . '/target/sub/blob') === $data, 'multi-block binary file restored exactly');
check(is_file($root . '/target/123') && filesize($root . '/target/123') === 0, 'empty numeric filename restored');
check(is_dir($root . '/target/empty'), 'empty directory restored');
denied(fn() => $restore->restore($path, $root . '/target', true), 'repeat cannot overwrite existing target');
$manifestPath = $path . '/manifests/initial-tree/state.json';
$originalManifest = file_get_contents($manifestPath); $originalState = file_get_contents($path . '/state.json');
$m = json_decode($originalManifest, true); $s = json_decode($originalState, true);
$keys = array_keys($m['entries']); $index = array_search('sub/blob', $keys, true);
$chunk = $path . '/files/' . sprintf('file-%04d', $index) . '/chunk-1';
$originalChunk = file_get_contents($chunk); file_put_contents($chunk, 'wrong');
denied(fn() => $restore->restore($path, $root . '/reject', true), 'corrupted late chunk refused');
check(scandir($root . '/reject') === ['.', '..'], 'preflight corruption leaves target empty');
file_put_contents($chunk, $originalChunk);
$s['phase'] = 'capture'; file_put_contents($path . '/state.json', json_encode($s));
denied(fn() => $restore->restore($path, $root . '/reject', true), 'partial capture refused');
file_put_contents($path . '/state.json', $originalState);
foreach (['../escape', 'sub/../../escape', 'C:escape', 'CON.txt', 'trailing.', 'sub\\escape', '/absolute'] as $bad) {
    $modified = $m; $modified['entries'][$bad] = ['type'=>'file','bytes'=>0,'chunks'=>[]];
    $state = json_decode($originalState, true);
    $state['manifest_sha256'] = hash('sha256', json_encode($modified['entries'], JSON_THROW_ON_ERROR));
    file_put_contents($manifestPath, json_encode($modified)); file_put_contents($path . '/state.json', json_encode($state));
    denied(fn() => $restore->restore($path, $root . '/reject', true), 'unsafe path refused: ' . $bad);
}
check(scandir($root . '/reject') === ['.', '..'] && !file_exists($root . '/escape'), 'invalid manifests have no target effects');
file_put_contents($manifestPath, $originalManifest); file_put_contents($path . '/state.json', $originalState);
$result = $restore->restore($path, $root . '/reject', true);
check($result['files'] === 2 && $result['bytes'] === strlen($data), 'valid capture still usable after refused attempts');
echo json_encode(['passed'=>count($checks), 'checks'=>$checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
