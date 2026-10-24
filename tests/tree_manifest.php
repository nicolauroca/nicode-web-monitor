<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tools/TreeManifest.php';
require dirname(__DIR__) . '/tools/ResumableFile.php';
use Nicode\Maintenance\TreeManifest;
use Nicode\Maintenance\ResumableFile;
$root = $argv[1];
if (($argv[2] ?? '') === 'step') {
    echo json_encode((new TreeManifest($root . '/store'))->step($root . '/source', $argv[3], true));
    exit;
}
mkdir($root . '/store'); mkdir($root . '/source'); mkdir($root . '/source/sub');
$data = str_repeat('a', TreeManifest::BLOCK) . 'tail';
file_put_contents($root . '/source/sub/data', $data);
file_put_contents($root . '/source/123', '');
$checks = []; $times = [];
function check(bool $ok, string $name): void {
    global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name;
}
function denied(Closure $fn, string $name): void {
    $caught = false; try { $fn(); } catch (Throwable) { $caught = true; } check($caught, $name);
}
function step(string $id): array {
    global $root, $times;
    $start = microtime(true);
    $p = proc_open([PHP_BINARY, __FILE__, $root, 'step', $id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($p);
    $times[] = microtime(true) - $start;
    if ($code !== 0) { throw new RuntimeException($err); }
    return json_decode($out, true, 32, JSON_THROW_ON_ERROR);
}
function state(string $id): array {
    global $root; return json_decode(file_get_contents($root . '/store/' . $id . '/state.json'), true, 32, JSON_THROW_ON_ERROR);
}
function untilVerify(string $id): void {
    for ($i = 0; $i < 30; $i++) { if (step($id)['status'] === 'verify') { return; } }
    throw new RuntimeException('No progress');
}
$builder = new TreeManifest($root . '/store');
denied(fn() => $builder->step($root . '/source', 'no-freeze', false), 'requires stopped writers');
denied(fn() => $builder->step($root . '/source', '../escape', true), 'rejects invalid identity');
denied(fn() => (new TreeManifest($root . '/source/sub'))->step($root . '/source', 'nested-one', true), 'rejects nested storage');
check(step('tree-one')['entries'] === 1, 'first step discovers only root');
$lock = fopen($root . '/store/tree-one/lock', 'c+b'); flock($lock, LOCK_EX);
denied(fn() => step('tree-one'), 'concurrent process refused');
flock($lock, LOCK_UN); fclose($lock);
mkdir($root . '/other');
denied(fn() => $builder->step($root . '/other', 'tree-one', true), 'identity binds canonical source');
for ($i = 0; $i < 4; $i++) { step('tree-one'); }
check(count(state('tree-one')['entries']['sub/data']['chunks']) === 1, 'hashing limited to one block');
file_put_contents($root . '/store/tree-one/state.json.next', 'partial checkpoint');
untilVerify('tree-one');
check(count(state('tree-one')['entries']['sub/data']['chunks']) === 2, 'new process resumes and replaces uncommitted checkpoint');
$done = null;
for ($i = 0; $i < 10; $i++) { $done = step('tree-one'); if ($done['status'] === 'complete') { break; } }
check($done['status'] === 'complete' && $done['entries'] === 4, 'nested tree and numeric empty filename verified');
check(step('tree-one') === $done, 'completed receipt stable');
$entries = state('tree-one')['entries'];
check($entries['sub/data']['chunks'] === [hash('sha256', substr($data, 0, TreeManifest::BLOCK)), hash('sha256', 'tail')], 'persisted hashes match source bytes');
mkdir($root . '/chunks');
$capture = new ResumableFile($root . '/chunks');
$manifest = ['bytes' => $entries['sub/data']['bytes'], 'chunks' => $entries['sub/data']['chunks']];
for ($i = 0; $i < 6; $i++) {
    $r = $capture->step($root . '/source/sub/data', 'capture-one', $manifest, true);
    if ($r['status'] === 'complete') { break; }
}
check($r['status'] === 'complete'
    && file_get_contents($root . '/chunks/capture-one/chunk-0') . file_get_contents($root . '/chunks/capture-one/chunk-1') === $data,
    'frozen manifest drives real resumable capture with matching reconstructed bytes');
untilVerify('changed-tree');
file_put_contents($root . '/source/added', 'new');
denied(fn() => step('changed-tree'), 'directory addition rejected in verification');
unlink($root . '/source/added');
untilVerify('changed-file');
file_put_contents($root . '/source/sub/data', 'z' . substr($data, 1));
denied(function () { for ($i = 0; $i < 10; $i++) { step('changed-file'); } }, 'same-size content change rejected');
file_put_contents($root . '/source/sub/data', $data);
mkdir($root . '/store/orphan-one'); file_put_contents($root . '/store/orphan-one/bound', '');
denied(fn() => step('orphan-one'), 'interrupted initialization fails closed');
file_put_contents($root . '/store/tree-one/state.json', '{');
denied(fn() => step('tree-one'), 'corrupt checkpoint fails closed');
mkdir($root . '/wide');
for ($i = 0; $i <= TreeManifest::MAX_CHILDREN; $i++) { file_put_contents($root . '/wide/f' . $i, ''); }
denied(fn() => $builder->step($root . '/wide', 'wide-tree', true), 'oversized directory rejected within entry cap');
check(max($times) < 5, 'every measured subprocess below five seconds locally');
echo json_encode(['passed' => count($checks), 'checks' => $checks, 'process_steps' => count($times),
    'max_step_seconds' => max($times), 'scope' => 'local manifest and file capture; no HTTP or database'], JSON_PRETTY_PRINT);
