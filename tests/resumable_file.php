<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tools/ResumableFile.php';
use Nicode\Maintenance\ResumableFile;
$root = $argv[1];
if (($argv[2] ?? '') === 'step') {
    $manifest = json_decode(file_get_contents($root . '/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
    echo json_encode((new ResumableFile($root . '/store'))->step($root . '/source', 'resume-one', $manifest, true));
    exit;
}
mkdir($root . '/store', 0700);
$data = str_repeat('A', ResumableFile::BLOCK) . str_repeat('B', ResumableFile::BLOCK) . 'last';
file_put_contents($root . '/source', $data);
$manifest = ['bytes' => strlen($data), 'chunks' => array_map(fn($s) => hash('sha256', $s), str_split($data, ResumableFile::BLOCK))];
file_put_contents($root . '/manifest.json', json_encode($manifest));
$checks = []; $times = [];
function check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; }
function denied(Closure $fn, string $name): void {
    $failed = false; try { $fn(); } catch (Throwable) { $failed = true; } check($failed, $name);
}
function separateStep(): array {
    global $root, $times;
    $start = microtime(true);
    $p = proc_open([PHP_BINARY, __FILE__, $root, 'step'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($p);
    if ($code !== 0) { throw new RuntimeException($err); }
    $times[] = microtime(true) - $start;
    return json_decode($out, true, 16, JSON_THROW_ON_ERROR);
}
$provider = new ResumableFile($root . '/store');
$run = fn($id, $m = null, $q = true) => $provider->step($root . '/source', $id, $m ?? $manifest, $q);
denied(fn() => $run('../escape'), 'invalid identity denied');
denied(fn() => $run('unarmed-one', null, false), 'quiescence required on every call');
$first = separateStep();
check($first['status'] === 'copy' && $first['cursor'] === 1, 'first process commits one block only');
check(!file_exists($root . '/store/resume-one/chunk-1'), 'later blocks not copied early');
$lock = fopen($root . '/store/resume-one/lock', 'c+b'); flock($lock, LOCK_EX);
denied(fn() => $run('resume-one'), 'concurrent operation refused');
flock($lock, LOCK_UN); fclose($lock);
// Emulate an interrupted, uncommitted write. Existing committed block must survive.
$firstHash = hash_file('sha256', $root . '/store/resume-one/chunk-0');
file_put_contents($root . '/store/resume-one/chunk-1.next', 'interrupted partial block');
$second = separateStep();
check($second['cursor'] === 2 && hash_file('sha256', $root . '/store/resume-one/chunk-0') === $firstHash, 'new process resumes without rewriting committed block');
check(hash_file('sha256', $root . '/store/resume-one/chunk-1') === $manifest['chunks'][1], 'partial uncommitted block replaced correctly');
$changed = $manifest; $changed['chunks'][0] = str_repeat('0', 64);
denied(fn() => $run('resume-one', $changed), 'identity cannot be rebound to new manifest');
check(separateStep()['status'] === 'verify', 'copy completion starts bounded verification');
// Same-size modification, no reliance on timestamp precision.
file_put_contents($root . '/source', 'Z' . substr($data, 1));
denied(fn() => $run('resume-one'), 'same-size source mutation refused during verification');
file_put_contents($root . '/source', $data);
file_put_contents($root . '/store/resume-one/chunk-0', str_repeat('Z', ResumableFile::BLOCK));
denied(fn() => $run('resume-one'), 'captured corruption refused');
file_put_contents($root . '/store/resume-one/chunk-0', substr($data, 0, ResumableFile::BLOCK));
separateStep(); separateStep(); $done = separateStep();
check($done['status'] === 'complete' && !$done['restoration_verified'], 'completion after all blocks verified without restore claim');
check(separateStep() === $done, 'completed operation replays stable receipt');
$joined = ''; for ($i = 0; $i < 3; $i++) { $joined .= file_get_contents($root . '/store/resume-one/chunk-' . $i); }
check(hash('sha256', $joined) === hash('sha256', $data), 'all captured bytes reconstruct original');
mkdir($root . '/store/crashed-init'); file_put_contents($root . '/store/crashed-init/bound', '');
denied(fn() => $run('crashed-init'), 'interrupted initialization fails closed');
file_put_contents($root . '/store/resume-one/state.json', '{');
denied(fn() => $run('resume-one'), 'corrupt checkpoint never restarts capture');
file_put_contents($root . '/source', '');
$empty = ['bytes' => 0, 'chunks' => []];
check($run('empty-one', $empty)['status'] === 'verify' && $run('empty-one', $empty)['status'] === 'complete', 'empty file handled');
check(max($times) < 5, 'each measured subprocess completes within five seconds');
echo json_encode(['passed' => count($checks), 'checks' => $checks, 'step_seconds' => $times,
    'max_step_seconds' => max($times), 'scope' => 'local chunk primitive; no HTTP/database/tree integration'], JSON_PRETTY_PRINT);
