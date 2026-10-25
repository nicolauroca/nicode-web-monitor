<?php
/** Real packaged connector endpoint with a deterministic provider, isolated from Joomla. */
$d = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
foreach (['Maintenance/TreeOperations', 'Transport/BackupEndpoint'] as $file) { require $d['package'] . '/src/' . $file . '.php'; }
$endpoint = new \Nicode\Plugin\System\NicodeWebMonitor\Transport\BackupEndpoint(
    $d['site_id'], $d['digest'], '', $d['storage'], $d['web'], joint: true);
$capture = function ($id) use ($d) {
    $path = $d['storage'] . '/provider-count';
    $count = is_file($path) ? (int)file_get_contents($path) : 0;
    file_put_contents($path, (string)++$count);
    return ['operation'=>$id, 'status'=>$count >= 2 ? 'complete' : 'files', 'cursor'=>$count,
        'manifest_sha256'=>$count >= 2 ? str_repeat('a', 64) : null];
};
echo json_encode($endpoint->handle($d['server'], $d['query'], $d['body'], $capture), JSON_THROW_ON_ERROR);
