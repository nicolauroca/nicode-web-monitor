<?php
declare(strict_types=1);
/** Disposable database only; credentials consumed from configuration/stdin, never printed. */
if (($argv[3] ?? '') !== '--allow-disposable-writes') { exit(2); }
$operation = $argv[4] ?? 'native-joint';
if (!preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $operation)) { exit(2); }
$baseline = isset($argv[5]) ? json_decode(file_get_contents($argv[5]), true, 512, JSON_THROW_ON_ERROR) : null;
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
$provider = new Nicode\Maintenance\ResumableDatabase($argv[2] . '/site-databases');
$checks = [];
function check(bool $ok, string $name): void {
    global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name;
}
function digest(mysqli $db, string $table): array {
    $name = '`' . str_replace('`', '``', $table) . '`'; $rows = [];
    foreach ($db->query('SELECT * FROM ' . $name)->fetch_all(MYSQLI_NUM) as $row) {
        $rows[] = hash('sha256', serialize(array_map(static fn($v) => $v === null ? null : (string) $v, $row)));
    }
    sort($rows); return [$db->query('SHOW CREATE TABLE ' . $name)->fetch_row()[1], $rows];
}
try {
    $export = $provider->export($operation, 'restore-input');
    $admin->query('CREATE DATABASE `' . $targetName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $admin->select_db($targetName);
    $restored = (new Nicode\Maintenance\DatabaseSnapshot($argv[2] . '/site-databases'))->restore($admin, 'restore-input', 'restore-result', true);
    $compared = 0;
    foreach ($source->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $row) {
        if ($baseline !== null && !isset($baseline[$row[0]])) { throw new RuntimeException('Missing baseline table'); }
        check(($baseline === null ? digest($source, $row[0]) : $baseline[$row[0]]) === digest($admin, $row[0]), 'schema and row multiset match table ' . ++$compared);
    }
    if ($baseline !== null) { check(count($baseline) === $compared, 'baseline table set matches'); }
    check($compared === $export['tables'] && $restored['rows'] === $export['rows'], 'all tables and rows restored');
    $dbResult = ['tables'=>$compared, 'rows'=>$restored['rows'], 'passed'=>count($checks), 'application_verified'=>false];
    // Persist each verified phase before starting the next potentially long phase.
    if (file_put_contents($argv[2] . '/database-verification.json', json_encode($dbResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException('Cannot persist database verification');
    }
} finally {
    $admin->query('DROP DATABASE IF EXISTS `' . $targetName . '`');
    $source->close(); $admin->close();
}

require dirname(__DIR__) . '/tools/TreeRestore.php';
$target = $argv[2] . '/restored-files'; mkdir($target, 0700);
$files = (new Nicode\Maintenance\TreeRestore())->restore(realpath($argv[2] . '/site-trees/' . $operation), realpath($target), true);
$m = json_decode(file_get_contents($argv[2] . '/site-trees/' . $operation . '/manifests/initial-tree/state.json'), true, 32, JSON_THROW_ON_ERROR);
$compared = 0;
foreach ($m['entries'] as $key=>$entry) {
    if ($entry['type'] === 'file') {
        if (hash_file('sha256', $argv[1] . '/' . $key) !== hash_file('sha256', $target . '/' . $key)) { throw new RuntimeException('Restored file differs'); }
        $compared++;
    }
}
$result = ['database'=>$dbResult, 'files_compared'=>$compared, 'files'=>$files, 'application_verified'=>false];
if (file_put_contents($argv[2] . '/restore-verification.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) {
    throw new RuntimeException('Cannot persist restoration verification');
}
echo json_encode($result);
