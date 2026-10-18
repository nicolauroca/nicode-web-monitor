<?php
declare(strict_types=1);
// Arguments: extracted connector ZIP and fresh private test directory.
$package = $argv[1]; $root = $argv[2];
require $package . '/src/Maintenance/BackupOperations.php';
require $package . '/src/Transport/BackupEndpoint.php';
use Nicode\Plugin\System\NicodeWebMonitor\Transport\BackupEndpoint;
foreach (['web', 'store', 'store/operations'] as $dir) { mkdir($root . '/' . $dir, 0700); }
$site = '12345678-1234-4123-8123-123456789abc';
$token = bin2hex(random_bytes(32)); $digest = hash('sha256', $token);
$endpoint = new BackupEndpoint($site, $digest, '', $root . '/store', $root . '/web');
$server = ['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
$query = ['option' => 'com_nicodewebmonitor', 'task' => 'backup'];
$request = ['site_id' => $site, 'operation' => 'adapter-test', 'action' => 'capture', 'confirmed' => true];
$body = json_encode($request); $checks = []; $calls = 0;
$provider = function ($id) use (&$calls) { $calls++; return ['status' => 'captured', 'operation' => $id, 'manifest_sha256' => str_repeat('a', 64)]; };
function check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; }
foreach ([
    [array_replace($server, ['CONTENT_TYPE' => 'text/plain']), $query, $body, 415, 'content_type'],
    [$server, $query + ['path' => '/arbitrary'], $body, 400, 'post_query_rejected'],
    [$server, $query, 'null', 400, 'null_json'],
    [$server, $query, '[]', 400, 'array_json'],
    [$server, $query, str_repeat('x', 4097), 413, 'input_bound'],
    [$server, $query, json_encode($request + ['path' => '/arbitrary']), 400, 'unknown_fields'],
    [array_replace($server, ['REQUEST_METHOD' => 'GET']), $query + $request, '', 405, 'get_cannot_capture'],
    [array_replace($server, ['REQUEST_METHOD' => 'DELETE']), $query, '', 405, 'delete_rejected'],
] as [$headers, $params, $raw, $status, $name]) {
    check($endpoint->handle($headers, $params, $raw, $provider)[0] === $status, $name);
}
check($calls === 0 && count(scandir($root . '/store/operations')) === 2, 'rejected_requests_no_effects');
$unsafe = new BackupEndpoint($site, $digest, '', $root . '/web', $root . '/web');
check($unsafe->handle($server, $query, $body, $provider)[0] === 503, 'public_storage_rejected');
$first = $endpoint->handle($server, $query, $body, $provider);
check($first[0] === 200 && $calls === 1, 'adapter_delegates_capture');
check($endpoint->handle($server, $query, $body, $provider) === $first && $calls === 1, 'adapter_deduplicates');
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
