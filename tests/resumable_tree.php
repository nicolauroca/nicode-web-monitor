<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tools/ResumableTree.php';
use Nicode\Maintenance\ResumableTree;
$root = $argv[1];
if (($argv[2] ?? '') === 'step') {
    echo json_encode((new ResumableTree($root . '/store'))->step($root . '/source', $argv[3], true)); exit;
}
mkdir($root . '/source'); mkdir($root . '/source/sub'); mkdir($root . '/source/empty'); mkdir($root . '/store');
$data = str_repeat('x', 1048576) . 'tail';
file_put_contents($root . '/source/sub/data', $data); file_put_contents($root . '/source/123', '');
$checks = []; $times = [];
function check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; }
function denied(Closure $fn, string $name): void { $ok = false; try { $fn(); } catch (Throwable) { $ok = true; } check($ok, $name); }
function step(string $id): array {
    global $root, $times; $t = microtime(true);
    $p = proc_open([PHP_BINARY, __FILE__, $root, 'step', $id], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($p); $times[] = microtime(true) - $t;
    if ($code !== 0) { throw new RuntimeException($err); } return json_decode($out, true, 32, JSON_THROW_ON_ERROR);
}
function untilPhase(string $id, string $phase): array {
    for ($i=0; $i<100; $i++) { $r = step($id); if ($r['status'] === $phase) { return $r; } }
    throw new RuntimeException('No progress');
}
$c = new ResumableTree($root . '/store');
denied(fn() => $c->step($root . '/source', 'bad-budget', true, 65), 'oversized work budget refused');
denied(fn() => $c->step($root . '/source', 'bad-budget', true, 0), 'zero work budget refused');
denied(fn() => $c->step($root . '/source', 'tree-one', false), 'requires stopped writers');
denied(fn() => $c->step($root . '/source', '../escape', true), 'invalid identity refused');
denied(fn() => (new ResumableTree($root . '/source/empty'))->step($root . '/source', 'tree-one', true), 'nested storage refused');
step('tree-one');
$lock = fopen($root . '/store/tree-one/lock', 'c+b'); flock($lock, LOCK_EX);
denied(fn() => step('tree-one'), 'concurrent process refused'); flock($lock, LOCK_UN); fclose($lock);
mkdir($root . '/other'); denied(fn() => $c->step($root . '/other', 'tree-one', true), 'identity bound to source');
untilPhase('tree-one', 'capture');
file_put_contents($root . '/store/tree-one/state.json.next', 'partial');
$done = untilPhase('tree-one', 'complete');
check(step('tree-one') === $done && !$done['restoration_verified'], 'stable historical receipt without restore claim');
$m = json_decode(file_get_contents($root . '/store/tree-one/manifests/initial-tree/state.json'), true);
$keys = array_keys($m['entries']); $exact = true;
foreach ($keys as $i => $key) {
    $entry = $m['entries'][$key]; if ($entry['type'] !== 'file') { continue; }
    $bytes = ''; foreach ($entry['chunks'] as $j => $hash) { $bytes .= file_get_contents($root . '/store/tree-one/files/' . sprintf('file-%04d', $i) . '/chunk-' . $j); }
    $exact = $exact && $bytes === file_get_contents($root . '/source/' . $key);
}
check($exact && isset($m['entries']['empty']), 'all files reconstruct exactly and empty directory retained');
untilPhase('tree-change', 'final'); file_put_contents($root . '/source/new', 'new');
denied(fn() => untilPhase('tree-change', 'complete'), 'late directory addition refuses completion');
unlink($root . '/source/new');
untilPhase('tree-content', 'capture'); file_put_contents($root . '/source/sub/data', str_repeat('y', 1048576) . 'tail');
denied(fn() => untilPhase('tree-content', 'complete'), 'source content mutation refused during copy');
file_put_contents($root . '/source/sub/data', $data);
mkdir($root . '/store/tree-orphan'); file_put_contents($root . '/store/tree-orphan/bound', '');
denied(fn() => step('tree-orphan'), 'orphan initialization fails closed');
step('tree-corrupt'); file_put_contents($root . '/store/tree-corrupt/state.json', '{');
denied(fn() => step('tree-corrupt'), 'corrupt checkpoint fails closed');
check(max($times) < 5, 'each measured process under five seconds locally');
echo json_encode(['passed'=>count($checks), 'checks'=>$checks, 'steps'=>count($times), 'max_step_seconds'=>max($times)], JSON_PRETTY_PRINT);
