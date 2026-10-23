<?php
declare(strict_types=1);
/** Disposable database only; credentials consumed from configuration/stdin, never printed. */
if (($argv[3] ?? '') !== '--allow-disposable-writes') { exit(2); }
require dirname(__DIR__) . '/tools/ResumableDatabase.php';
require $argv[1] . '/configuration.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$config = new JConfig(); $parts = explode(':', $config->host);
$connect = static function () use ($config, $parts): mysqli {
    $db = new mysqli($parts[0], $config->user, $config->password, $config->db, (int) ($parts[1] ?? 3306));
    $db->set_charset('utf8mb4'); return $db;
};
$source = $connect();
$auth = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$admin = new mysqli($auth['host'], $auth['user'], $auth['password'], '', $auth['port']); unset($auth);
$admin->set_charset('utf8mb4');
$targetName = 'nwm_resume_' . bin2hex(random_bytes(5));
$provider = new Nicode\Maintenance\ResumableDatabase($argv[2]);
$checks = []; $times = [];
function check(bool $ok, string $name): void {
    global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name;
}
function rejects(Closure $fn, string $name): void {
    try { $fn(); } catch (Throwable) { check(true, $name); return; } check(false, $name);
}
function advance(string $id): array {
    global $provider, $connect, $times;
    // Every step uses a NEW database connection: no accidental transaction continuity.
    $db = $connect(); $start = microtime(true);
    try { return $provider->step($db, $id, true); }
    finally { $times[] = microtime(true) - $start; $db->close(); }
}
function runUntil(string $id, string $phase): array {
    for ($i = 0; $i < 10000; $i++) { $r = advance($id); if ($r['status'] === $phase) { return $r; } }
    throw new RuntimeException('No completion within step budget');
}
function digest(mysqli $db, string $table): array {
    $name = '`' . str_replace('`', '``', $table) . '`'; $rows = [];
    foreach ($db->query('SELECT * FROM ' . $name)->fetch_all(MYSQLI_NUM) as $row) {
        $rows[] = hash('sha256', serialize(array_map(static fn($v) => $v === null ? null : (string) $v, $row)));
    }
    sort($rows); return [$db->query('SHOW CREATE TABLE ' . $name)->fetch_row()[1], $rows];
}
$fixture = 'nwm_resume_fixture'; $created = false; $keyless = false;
try {
    $source->query('CREATE TABLE ' . $fixture . ' (id BIGINT UNSIGNED PRIMARY KEY, data LONGBLOB, amount DECIMAL(30,10), txt TEXT) ENGINE=InnoDB');
    $created = true;
    $stmt = $source->prepare('INSERT INTO ' . $fixture . ' VALUES (?,?,?,?)');
    for ($i = 0; $i < 260; $i++) {
        $id = (string) ($i + 1); $binary = $i % 2 ? null : str_repeat("\x00\xff", 8192);
        $amount = '12345678901234567890.1234567890'; $text = $i % 3 ? 'café 🧭' : '';
        $stmt->bind_param('ssss', $id, $binary, $amount, $text); $stmt->execute();
    }
    $id = '18446744073709551615'; $stmt->execute(); $stmt->close();
    $source->query('CREATE TABLE nwm_resume_keyless (txt LONGTEXT NULL, data BLOB NULL) ENGINE=InnoDB'); $keyless = true;
    $stmt = $source->prepare('INSERT INTO nwm_resume_keyless VALUES (?,?)');
    for ($i = 0; $i < 300; $i++) {
        $text = $i % 7 === 0 ? null : str_repeat('prefix', 300) . ['A', 'a', 'a ', ''][($i % 4)];
        $binary = $i % 3 ? "\x00\xff" : null;
        $stmt->bind_param('ss', $text, $binary); $stmt->execute();
    }
    $stmt->close();
    rejects(fn() => $provider->step($source, 'no-consent', false), 'quiescence required on every step');
    rejects(fn() => $provider->step($source, '../escape', true), 'path escape refused');
    $first = advance('full-capture');
    $lock = fopen($argv[2] . '/full-capture/lock', 'c+b'); flock($lock, LOCK_EX);
    rejects(fn() => advance('full-capture'), 'overlapping step refused');
    flock($lock, LOCK_UN); fclose($lock);
    rejects(fn() => $provider->export('full-capture', 'early-export'), 'incomplete export refused');
    file_put_contents($argv[2] . '/full-capture/state.json.next', 'abandoned write');
    $done = runUntil('full-capture', 'complete');
    check($done['tables'] > 50 && $done['rows'] > 260, 'complete real Joomla database and multi-page binary fixture captured');
    check(advance('full-capture') === $done, 'completion receipt immutable');
    $maxChunk = 0;
    foreach (glob($argv[2] . '/full-capture/chunk-*') as $file) { $maxChunk = max($maxChunk, filesize($file)); }
    check($maxChunk <= Nicode\Maintenance\ResumableDatabase::CHUNK_BYTES, 'all chunks within one MiB');
    $export = $provider->export('full-capture', 'restore-input');
    $admin->query('CREATE DATABASE `' . $targetName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $admin->select_db($targetName);
    $restored = (new Nicode\Maintenance\DatabaseSnapshot($argv[2]))->restore($admin, 'restore-input', 'restore-result', true);
    $compared = 0;
    foreach ($source->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $row) {
        check(digest($source, $row[0]) === digest($admin, $row[0]), 'schema and row multiset match table ' . ++$compared);
    }
    check($compared === $done['tables'] && $restored['rows'] === $done['rows'], 'all tables and exact row counts restored');
    check(digest($source, 'nwm_resume_keyless') === digest($admin, 'nwm_resume_keyless'), 'keyless duplicates NULL case and trailing spaces restored across pages');
    check(!$done['restoration_verified'] && !$restored['application_verified'], 'no implicit application recovery claim');
    // Reuse the verified capture checkpoint to exercise independent failure states without recapturing Joomla.
    $statePath = $argv[2] . '/full-capture/state.json'; $saved = file_get_contents($statePath);
    $s = json_decode($saved, true); $s['phase'] = 'verify'; $s['table'] = 0; $s['offset'] = 0; $s['cursor'] = 0;
    file_put_contents($statePath, json_encode($s));
    $source->query('UPDATE ' . $fixture . " SET txt='changed' WHERE id=1");
    rejects(fn() => runUntil('full-capture', 'complete'), 'data changes between capture and verification rejected');
    $source->query('UPDATE ' . $fixture . " SET txt='' WHERE id=1");
    file_put_contents($statePath, $saved);
    $chunk = $argv[2] . '/full-capture/chunk-0'; $original = file_get_contents($chunk);
    file_put_contents($chunk, 'corrupted');
    rejects(fn() => $provider->export('full-capture', 'corrupt-export'), 'corrupted capture refused for restore');
    check(!is_file($argv[2] . '/corrupt-export/completed.json'), 'failed export has no success receipt');
    file_put_contents($chunk, $original);
    // Crash between chunk fsync and checkpoint: exact orphan is adopted, conflicting orphan fails closed.
    $s = json_decode($saved, true); $s['phase'] = 'capture'; $s['table'] = 0; $s['offset'] = 0; $s['cursor'] = 0;
    $s['chunks'] = []; $s['bytes'] = 0; $s['rows'] = 0;
    file_put_contents($statePath, json_encode($s));
    check(advance('full-capture')['chunks'] === 1, 'exact uncommitted chunk adopted after restart');
    file_put_contents($statePath, json_encode($s)); file_put_contents($chunk, 'partial');
    rejects(fn() => advance('full-capture'), 'partial uncommitted chunk never overwritten');
    file_put_contents($chunk, $original); file_put_contents($statePath, $saved);
    $source->query('CREATE VIEW nwm_resume_view AS SELECT id FROM ' . $fixture);
    try { rejects(fn() => advance('with-view'), 'unsupported view refused'); }
    finally { $source->query('DROP VIEW nwm_resume_view'); }
    check(max($times) < 5, 'each measured database step below five seconds locally');
    echo json_encode(['passed' => count($checks), 'checks' => $checks, 'tables' => $done['tables'], 'rows' => $done['rows'],
        'chunks' => $done['chunks'], 'bytes' => $done['bytes'], 'steps' => count($times), 'max_step_seconds' => max($times),
        'max_chunk_bytes' => $maxChunk, 'scope' => 'local database capture and exact restore; no HTTP or Joomla application boot'], JSON_PRETTY_PRINT);
} finally {
    if ($keyless) { $source->query('DROP TABLE nwm_resume_keyless'); }
    if ($created) { $source->query('DROP TABLE ' . $fixture); }
    $admin->query('DROP DATABASE IF EXISTS `' . $targetName . '`');
    $source->close(); $admin->close();
}
