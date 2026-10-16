<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tools/BackupOperations.php';
require dirname(__DIR__) . '/tools/FileSnapshot.php';
use Nicode\Maintenance\BackupOperations;
use Nicode\Maintenance\FileSnapshot;

$root = $argv[1]; // Fresh private test directory, outside Git and web roots.
$site = '12345678-1234-4123-8123-123456789abc';
$token = hash('sha256', 'synthetic-backup-test-only');
$server = ['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
$request = ['site_id' => $site, 'operation' => 'capture-one', 'action' => 'capture', 'confirmed' => true];
if (($argv[2] ?? '') === 'crash') {
    $endpoint = new BackupOperations($root . '/operations', $site, hash('sha256', $token));
    $request['operation'] = 'crashed-one';
    $endpoint->handle($server, $request, function ($id) use ($root) {
        file_put_contents($root . '/effect.txt', $id);
        exit(17); // Death after side effect, before returning/persisting completion.
    });
    exit(99);
}
foreach (['operations', 'snapshots', 'source'] as $dir) { if (!mkdir($root . '/' . $dir, 0700)) { throw new RuntimeException('Fresh directory required'); } }
file_put_contents($root . '/source/data.txt', 'synthetic payload');
$endpoint = new BackupOperations($root . '/operations', $site, hash('sha256', $token));
$calls = 0; $checks = [];
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks[] = $label; }
$provider = function ($id) use ($root, &$calls) {
    $calls++;
    return (new FileSnapshot($root . '/snapshots'))->capture($root . '/source', $id, true);
};
$bad = $server; unset($bad['HTTPS']); $bad['HTTP_X_FORWARDED_PROTO'] = 'https';
check($endpoint->handle($bad, $request, $provider)[0] === 403, 'untrusted proxy cannot enable TLS');
$bad = $server; $bad['HTTP_AUTHORIZATION'] = 'Bearer ' . hash('sha256', 'inventory-only');
check($endpoint->handle($bad, $request, $provider)[0] === 401, 'inventory credential cannot capture');
check((new BackupOperations($root . '/operations', $site, ''))->handle($server, $request, $provider)[0] === 503, 'backup disabled by default');
$bad = $request; $bad['site_id'] = '87654321-1234-4123-8123-123456789abc';
check($endpoint->handle($server, $bad, $provider)[0] === 409, 'wrong site rejected');
$bad = $request; $bad['operation'] = '../escape';
check($endpoint->handle($server, $bad, $provider)[0] === 400, 'path traversal rejected');
$bad = $request; $bad['confirmed'] = 'true';
check($endpoint->handle($server, $bad, $provider)[0] === 400, 'literal confirmation required');
$bad = $request; $bad['path'] = '/arbitrary';
check($endpoint->handle($server, $bad, $provider)[0] === 400, 'caller cannot choose paths');
$get = $server; $get['REQUEST_METHOD'] = 'GET';
check($endpoint->handle($get, $request, $provider)[0] === 405, 'GET cannot capture');
$status = $request; $status['action'] = 'status'; unset($status['confirmed']);
check($endpoint->handle($get, $status, $provider)[0] === 404, 'unknown status explicit');
check($calls === 0 && count(scandir($root . '/operations')) === 2, 'rejected requests have no provider or journal effects');
$first = $endpoint->handle($server, $request, $provider);
check($first[0] === 200 && $first[1]['status'] === 'captured', 'real file snapshot captured');
check(file_get_contents($root . '/snapshots/capture-one/files/data.txt') === 'synthetic payload', 'captured bytes verified');
check($endpoint->handle($server, $request, $provider) === $first && $calls === 1, 'duplicate replays receipt without repeating effect');
$endpoint = new BackupOperations($root . '/operations', $site, hash('sha256', $token));
check($endpoint->handle($get, $status, $provider) === $first && $calls === 1, 'new instance reconciles stored completion');
$revoked = new BackupOperations($root . '/operations', $site, hash('sha256', 'rotated-test-credential'));
check($revoked->handle($get, $status, $provider)[0] === 401, 'revocation also protects status');
check($first[1]['restoration_verified'] === false, 'capture does not claim restoration');
$bad = $request; $bad['operation'] = 'failed-one';
$failures = 0;
$fail = function ($id) use (&$failures) { $failures++; throw new RuntimeException('private provider diagnostic'); };
check($endpoint->handle($server, $bad, $fail)[0] === 202, 'provider exception leaves uncertainty');
check($endpoint->handle($server, $bad, $fail)[0] === 202 && $failures === 1, 'uncertain operation never automatically reruns');
$bad['operation'] = 'overlap-one';
$overlap = null;
$result = $endpoint->handle($server, $bad, function ($id) use ($endpoint, $server, $bad, $provider, &$overlap) {
    $overlap = $endpoint->handle($server, $bad, $provider);
    return $provider($id);
});
check($overlap[0] === 202 && $result[0] === 200 && $calls === 2, 'overlapping call sees reservation without duplicate execution');
$process = proc_open([PHP_BINARY, __FILE__, $root, 'crash'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
foreach ($pipes as $pipe) { fclose($pipe); }
check(proc_close($process) === 17 && file_get_contents($root . '/effect.txt') === 'crashed-one', 'separate process exits after actual side effect');
$bad['operation'] = 'crashed-one';
check($endpoint->handle($server, $bad, $provider)[0] === 202 && $calls === 2, 'process-death reservation survives restart');
$bad['action'] = 'status';
check($endpoint->handle($get, $bad, $provider)[1]['status'] === 'uncertain', 'process-death status remains uncertain');
file_put_contents($root . '/operations/capture-one/completed.json', '{broken');
check($endpoint->handle($server, $request, $provider)[0] === 202 && $calls === 2, 'corrupt completion never triggers rerun');
$other = '87654321-1234-4123-8123-123456789abc';
$otherEndpoint = new BackupOperations($root . '/operations', $other, hash('sha256', $token));
$bad = $request; $bad['site_id'] = $other;
check($otherEndpoint->handle($server, $bad, $provider)[0] === 409, 'cloned identity cannot reuse old operation');
mkdir($root . '/operations/empty-one'); $bad = $request; $bad['operation'] = 'empty-one';
check($endpoint->handle($server, $bad, $provider)[0] === 202 && $calls === 2, 'death before started record still prevents execution');
$bad['operation'] = 'invalid-receipt';
check($endpoint->handle($server, $bad, fn($id) => ['status' => 'captured', 'operation' => 'wrong'])[0] === 202, 'invalid provider receipt never claims capture');
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
