<?php
/** Test harness: credential only on stdin, never arguments or output. */
require dirname(__DIR__) . '/extensions/com_nicodewebmonitor/administrator/src/Service/InventoryClient.php';
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventoryClient;
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$client = new InventoryClient($input['ca'] ?? null, $input['allow_private'] ?? [], $input['timeout'] ?? 15000, $input['max_bytes'] ?? 2097152);
$result = $client->fetch($input['url'], $input['address'], $input['site_id'], $input['token']);
// Return only a summary. Actual inventory must not be committed or printed in logs.
if ($result['ok']) {
    $result = ['ok' => true, 'received_at' => $result['received_at'],
        'status' => $result['inventory']['status'],
        'joomla' => $result['inventory']['sections']['joomla']['data']['version'] ?? null,
        'extensions' => count($result['inventory']['sections']['extensions']['data'] ?? [])];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
