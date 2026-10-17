<?php
declare(strict_types=1);
if (($argv[3] ?? '') !== '--allow-disposable-writes') { exit(2); }
foreach (['FileSnapshot', 'DatabaseSnapshot', 'SiteSnapshot', 'BackupOperations'] as $class) {
    require dirname(__DIR__) . '/tools/' . $class . '.php';
}
require $argv[1] . '/configuration.php';
$config = new JConfig(); $root = $argv[2];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$parts = explode(':', $config->host);
$db = new mysqli($parts[0], $config->user, $config->password, $config->db, (int) ($parts[1] ?? 3306));
$db->set_charset('utf8mb4');
foreach (['store', 'source', 'journal'] as $dir) { if (!mkdir($root . '/' . $dir, 0700)) { throw new RuntimeException('Fresh directory required'); } }
file_put_contents($root . '/source/data.bin', "synthetic\x00\xff");
mkdir($root . '/source/empty');
$checks = [];
function check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; }
function rejects(callable $fn, string $name): void {
    try { $fn(); } catch (Throwable) { check(true, $name); return; }
    check(false, $name);
}
$provider = new Nicode\Maintenance\SiteSnapshot($root . '/store');
rejects(fn() => $provider->capture($db, $root . '/source', 'no-consent', false), 'quiescence required');
rejects(fn() => $provider->capture($db, $root . '/source', '../escape', true), 'invalid identity rejected');
rejects(fn() => $provider->capture($db, $root, 'nested-one', true), 'storage inside source rejected');
check(count(scandir($root . '/store')) === 2, 'precondition failures leave storage untouched');
$site = '12345678-1234-4123-8123-123456789abc'; $token = hash('sha256', 'synthetic-site-backup');
$endpoint = new Nicode\Maintenance\BackupOperations($root . '/journal', $site, hash('sha256', $token));
$server = ['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
$request = ['site_id' => $site, 'action' => 'capture', 'operation' => 'combined-one', 'confirmed' => true];
$calls = 0;
$capture = function ($id) use ($provider, $db, $root, &$calls) { $calls++; return $provider->capture($db, $root . '/source', $id, true); };
$result = $endpoint->handle($server, $request, $capture);
check($result[0] === 200 && $result[1]['status'] === 'captured', 'combined provider integrates with operation core');
$base = $root . '/store/combined-one';
$manifest = json_decode(file_get_contents($base . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
check($result[1]['manifest_sha256'] === hash_file('sha256', $base . '/manifest.json'), 'receipt binds combined manifest');
check($manifest['database']['sha256'] === hash_file('sha256', $base . '/database/combined-one/database.jsonl'), 'manifest binds actual database bytes');
check($manifest['files']['manifest_sha256'] === hash_file('sha256', $base . '/files/combined-one/manifest.json'), 'manifest binds file manifest');
check($manifest['database']['tables'] > 50 && $manifest['database']['rows'] > 100, 'real Joomla database captured');
check(file_get_contents($base . '/files/combined-one/files/data.bin') === "synthetic\x00\xff", 'binary file captured');
check(is_dir($base . '/files/combined-one/files/empty'), 'empty directory captured');
check(!$manifest['restoration_verified'] && $manifest['writer_quiescence'] === 'caller_acknowledged', 'no automatic quiescence or restoration claim');
check($endpoint->handle($server, $request, $capture) === $result && $calls === 1, 'duplicate returns same combined receipt without capture');
rejects(fn() => $capture('combined-one'), 'provider itself rejects replay');
$small = new Nicode\Maintenance\SiteSnapshot($root . '/store', 1024, 10, 100);
$request['operation'] = 'partial-one'; $failures = 0;
$fail = function ($id) use ($small, $db, $root, &$failures) { $failures++; return $small->capture($db, $root . '/source', $id, true); };
check($endpoint->handle($server, $request, $fail)[0] === 202, 'database failure surfaces uncertainty');
$partial = $root . '/store/partial-one';
check(is_file($partial . '/files/partial-one/completed.json'), 'completed file half retained');
check(is_file($partial . '/database/partial-one/started.json') && !is_file($partial . '/database/partial-one/completed.json'), 'incomplete database retained');
check(!is_file($partial . '/completed.json') && !is_file($partial . '/manifest.json'), 'partial pair cannot claim site completion');
check($endpoint->handle($server, $request, $fail)[0] === 202 && $failures === 1, 'partial pair never recaptured automatically');
$request['action'] = 'status'; unset($request['confirmed']); $server['REQUEST_METHOD'] = 'GET';
check($endpoint->handle($server, $request, $capture)[1]['status'] === 'uncertain', 'partial status survives lookup');
echo json_encode(['passed' => count($checks), 'checks' => $checks, 'tables' => $manifest['database']['tables'], 'rows' => $manifest['database']['rows']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
