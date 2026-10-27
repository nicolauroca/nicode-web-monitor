<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Subprocess fault injection, private disposable journal. */
require __DIR__.'/../tools/CoreOperation.php';
use Nicode\Maintenance\CoreOperation;
$store = $argv[1]; $mode = $argv[2];
$journal = new CoreOperation($store);
$plan = ['extension_id'=>247,'from'=>'6.1.3','to'=>'6.1.4','package_sha256'=>str_repeat('a',64)];
$inspect = function () use ($store) { return json_decode(file_get_contents($store.'/state.json'), true, 32, JSON_THROW_ON_ERROR); };
if ($mode === 'reconcile') { echo json_encode($journal->reconcile('core-test-256', $inspect)); exit; }
$install = function () use ($store, $inspect, $mode) {
    file_put_contents($store.'/calls', '1', FILE_APPEND);
    $state = $inspect();
    foreach (['runtime_version','manifest_version','database_version'] as $k) { $state[$k] = '6.1.4'; }
    file_put_contents($store.'/state.json', json_encode($state));
    if ($mode === 'crash') { exit(73); } // Effects occurred, callback never returned.
    return true;
};
try {
    $id = $mode === 'new-id' ? 'core-other-256' : 'core-test-256';
    if ($mode === 'conflict') { $plan['to'] = '6.1.5'; }
    $r = $journal->execute($id, $plan, $mode !== 'denied', $mode !== 'unconfirmed', $inspect, $install);
    if ($mode === 'same-process') { $journal->reconcile($id, $inspect); }
    echo json_encode($r);
} catch (Throwable $e) { echo json_encode(['blocked'=>true]); exit(4); }
