<?php
/** Test harness: ephemeral credential on stdin only. */
$services = dirname(__DIR__) . '/extensions/com_nicodewebmonitor/administrator/src/Service/';
require $services . 'InventoryClient.php';
require $services . 'BackupClient.php';
$d = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$client = new \Nicode\Component\NicodeWebMonitor\Administrator\Service\BackupClient(
    $d['ca'], $d['allow_private'] ?? ['127.0.0.1'], $d['timeout'] ?? 2000, $d['max_bytes'] ?? 8192);
echo json_encode($client->request($d['url'], $d['address'], $d['site_id'], $d['token'],
    $d['operation'], $d['sequence'] ?? null, $d['confirmed'] ?? false), JSON_THROW_ON_ERROR);
