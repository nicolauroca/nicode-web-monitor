<?php
declare(strict_types=1);
/** Run only in a stopped-writer disposable lab. Admin DB credentials arrive on stdin. */
if (($argv[3] ?? '') !== '--allow-disposable-writes') { exit(2); }
require dirname(__DIR__) . '/tools/DatabaseSnapshot.php';
require $argv[1] . '/configuration.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$config = new JConfig();
$auth = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$admin = new mysqli($auth['host'], $auth['user'], $auth['password'], '', $auth['port']);
unset($auth);
$parts = explode(':', $config->host);
$source = new mysqli($parts[0], $config->user, $config->password, $config->db, (int) ($parts[1] ?? 3306));
$source->set_charset('utf8mb4');
$name = 'nwm_restore_' . bin2hex(random_bytes(5));
$fixture = 'nwm_snapshot_fixture';
$checks = [];
function check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; }
function rejects(callable $fn, string $name): void {
    try { $fn(); } catch (Throwable $e) { check(true, $name); return; }
    check(false, $name);
}
function tableDigest(mysqli $db, string $table): array {
    $name = '`' . str_replace('`', '``', $table) . '`';
    $rows = [];
    foreach ($db->query('SELECT * FROM ' . $name)->fetch_all(MYSQLI_NUM) as $row) {
        $rows[] = hash('sha256', serialize(array_map(static fn($v) => $v === null ? null : (string) $v, $row)));
    }
    sort($rows);
    return [$db->query('SHOW CREATE TABLE ' . $name)->fetch_row()[1], hash('sha256', implode('', $rows)), count($rows)];
}
$provider = new Nicode\Maintenance\DatabaseSnapshot($argv[2]);
$target = null;
$createdFixture = false;
try {
    $admin->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    // Use the same admin connection for the isolated target; the source retains its restricted login.
    $admin->select_db($name);
    $target = $admin;
    $target->set_charset('utf8mb4');
    $source->query('CREATE TABLE `' . $fixture . '` (id BIGINT UNSIGNED PRIMARY KEY, data LONGBLOB NULL, txt TEXT, amount DECIMAL(30,10), empty_value VARCHAR(10)) ENGINE=InnoDB');
    $createdFixture = true;
    $stmt = $source->prepare('INSERT INTO `' . $fixture . '` VALUES (?,?,?,?,?)');
    $id = '18446744073709551615'; $binary = "\x00\xff\x80\r\n\"'\\"; $text = 'Nicolau — café 🧭'; $decimal = '12345678901234567890.1234567890'; $empty = '';
    $stmt->bind_param('sssss', $id, $binary, $text, $decimal, $empty); $stmt->execute();
    $id = '2'; $binary = null; $text = ''; $decimal = '-0.0000000001'; $empty = null; $stmt->execute(); $stmt->close();
    rejects(fn() => $provider->capture($source, 'no-consent', false), 'writer quiescence required');
    rejects(fn() => $provider->capture($source, '../escape', true), 'operation path traversal rejected');
    $source->query('CREATE VIEW nwm_snapshot_view AS SELECT id FROM `' . $fixture . '`');
    try { rejects(fn() => $provider->capture($source, 'with-view', true), 'views fail closed'); }
    finally { $source->query('DROP VIEW nwm_snapshot_view'); }
    $source->query('CREATE TABLE nwm_snapshot_myisam (id INT) ENGINE=MyISAM');
    try { rejects(fn() => $provider->capture($source, 'with-myisam', true), 'unsupported MyISAM tables fail closed'); }
    finally { $source->query('DROP TABLE nwm_snapshot_myisam'); }
    $source->query('CREATE TRIGGER nwm_snapshot_trigger BEFORE INSERT ON `' . $fixture . '` FOR EACH ROW SET NEW.txt = NEW.txt');
    try { rejects(fn() => $provider->capture($source, 'with-trigger', true), 'triggers fail closed'); }
    finally { $source->query('DROP TRIGGER nwm_snapshot_trigger'); }
    $small = new Nicode\Maintenance\DatabaseSnapshot($argv[2], 100);
    rejects(fn() => $small->capture($source, 'size-limit', true), 'size bound interrupts capture');
    check(is_file($argv[2] . '/size-limit/started.json') && !is_file($argv[2] . '/size-limit/completed.json'), 'interrupted capture remains uncertain');
    rejects(fn() => $provider->capture($source, 'size-limit', true), 'uncertain operation cannot be repeated');
    $meta = $provider->capture($source, 'joomla-snapshot', true);
    check($meta['status'] === 'captured' && !$meta['restoration_verified'], 'capture does not claim verified restoration');
    check($meta['tables'] > 50 && $meta['rows'] > 100, 'real Joomla database captured');
    rejects(fn() => $provider->capture($source, 'joomla-snapshot', true), 'completed operation cannot be repeated');
    rejects(fn() => $provider->restore($target, 'joomla-snapshot', 'no-restore-consent', false), 'restore requires isolated-target confirmation');
    $file = $argv[2] . '/joomla-snapshot/database.jsonl';
    $original = file_get_contents($file);
    file_put_contents($file, $original . 'x');
    rejects(fn() => $provider->restore($target, 'joomla-snapshot', 'corrupt-restore', true), 'corruption rejected before target writes');
    check($target->query('SHOW TABLES')->num_rows === 0, 'corruption leaves target empty');
    file_put_contents($file, $original); unset($original);
    $restored = $provider->restore($target, 'joomla-snapshot', 'joomla-restore', true);
    check($restored['tables'] === $meta['tables'] && $restored['rows'] === $meta['rows'], 'restored counts match capture');
    $compared = 0;
    foreach ($source->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $row) {
        if (tableDigest($source, $row[0]) !== tableDigest($target, $row[0])) { throw new RuntimeException('Restored schema or content mismatch'); }
        $compared++;
    }
    check($compared === $meta['tables'], 'all Joomla schemas and row multisets match after restore');
    check(tableDigest($source, $fixture) === tableDigest($target, $fixture), 'binary null Unicode decimal and uint64 round-trip');
    rejects(fn() => $provider->restore($target, 'joomla-snapshot', 'second-restore', true), 'nonempty target cannot be overwritten');
    check($target->query('SELECT @@SESSION.foreign_key_checks')->fetch_row()[0] === '1', 'foreign key setting restored');
    check(!$restored['application_verified'], 'restore does not claim Joomla application verified');
    // Inject a local provider failure AFTER the first CREATE TABLE to exercise implicit DDL commits.
    $admin->query('DROP DATABASE `' . $name . '`');
    $admin->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->select_db($name);
    $failedPath = $argv[2] . '/failure-fixture';
    mkdir($failedPath, 0700);
    $lines = file($file);
    $seen = 0;
    foreach ($lines as &$line) {
        $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (isset($item['table']) && ++$seen === 2) {
            $item['schema'] = base64_encode('INVALID SQL FOR LOCAL FAILURE FIXTURE');
            $line = json_encode($item, JSON_THROW_ON_ERROR) . "\n";
            break;
        }
    }
    unset($line);
    $bad = implode('', $lines); unset($lines);
    file_put_contents($failedPath . '/database.jsonl', $bad);
    $failureMeta = $meta; $failureMeta['sha256'] = hash('sha256', $bad); $failureMeta['bytes'] = strlen($bad);
    file_put_contents($failedPath . '/completed.json', json_encode($failureMeta, JSON_THROW_ON_ERROR));
    rejects(fn() => $provider->restore($target, 'failure-fixture', 'partial-restore', true), 'provider failure during restore surfaced');
    check($target->query('SHOW TABLES')->num_rows === 1, 'partially restored target preserved for inspection');
    check(is_file($argv[2] . '/partial-restore/started.json') && !is_file($argv[2] . '/partial-restore/completed.json'), 'partial restore remains uncertain');
    rejects(fn() => $provider->restore($target, 'failure-fixture', 'partial-restore', true), 'partial restore not retried or overwritten');
    check($target->query('SELECT @@SESSION.foreign_key_checks')->fetch_row()[0] === '1', 'foreign key setting restored after failure');
    $result = ['passed' => count($checks), 'checks' => $checks, 'tables_compared' => $compared,
        'snapshot' => $meta, 'restore' => $restored];
} finally {
    if ($createdFixture) { $source->query('DROP TABLE `' . $fixture . '`'); }
    $admin->query('DROP DATABASE IF EXISTS `' . $name . '`');
}
$result['disposable_target_removed'] = true;
$result['source_fixture_removed'] = true;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
