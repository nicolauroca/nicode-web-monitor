<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor */
require dirname(__DIR__) . '/tools/ResumableTree.php';
require dirname(__DIR__) . '/tools/TreeRestore.php';
use Nicode\Maintenance\ResumableTree;
use Nicode\Maintenance\TreeRestore;
// Read-only source; all writers must remain stopped. Private, new storage/target.
[$script, $source, $store, $target] = $argv;
$start = microtime(true); $times = []; $phases = [];
for ($i = 0; $i < 10000; $i++) {
    $t = microtime(true);
    $r = (new ResumableTree($store))->step($source, 'capacity-tree', true, 64);
    $times[] = microtime(true) - $t;
    $phases[$r['status']] = ($phases[$r['status']] ?? 0) + 1;
    if ($r['status'] === 'complete') { break; }
}
if ($r['status'] !== 'complete') { throw new RuntimeException('Capture incomplete'); }
if ((new ResumableTree($store))->step($source, 'capacity-tree', true, 64) !== $r) {
    throw new RuntimeException('Completed replay changed');
}
$restored = (new TreeRestore())->restore(realpath($store . '/capacity-tree'), realpath($target), true);
$m = json_decode(file_get_contents($store . '/capacity-tree/manifests/initial-tree/state.json'), true, 32, JSON_THROW_ON_ERROR);
$checked = 0;
foreach ($m['entries'] as $key => $entry) {
    if ($entry['type'] === 'file') {
        if (hash_file('sha256', $source . '/' . $key) !== hash_file('sha256', $target . '/' . $key)) {
            throw new RuntimeException('Restored bytes differ');
        }
        $checked++;
    } elseif (!is_dir($target . '/' . $key)) { throw new RuntimeException('Missing directory'); }
}
if (count($m['entries']) <= 2048 || max($times) >= 5) { throw new RuntimeException('Capacity or latency gate failed'); }
echo json_encode(['entries'=>count($m['entries']), 'files_compared'=>$checked,
    'steps'=>count($times), 'phases'=>$phases, 'max_step_seconds'=>max($times),
    'elapsed_seconds'=>microtime(true)-$start, 'checkpoint_bytes'=>filesize($store . '/capacity-tree/manifests/initial-tree/state.json'),
    'restored'=>$restored, 'application_verified'=>false], JSON_PRETTY_PRINT);
