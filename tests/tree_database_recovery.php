<?php
declare(strict_types=1);
/** Disposable lab only. Keep stdout free of configuration/credentials.
 * @package Nicode.joomla.webmonitor
 */
if (($argv[5] ?? '') !== '--allow-disposable-writes') { exit(2); }
require dirname(__DIR__) . '/tools/ResumableDatabase.php';

require $argv[1] . '/configuration.php';
$config = new JConfig(); $store = $argv[2]; $clone = $argv[3]; $name = $argv[4];
if (!preg_match('/^nwm_recovery_[a-f0-9]{10}$/D', $name)) { exit(2); }
$auth = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($auth['host'], $auth['user'], $auth['password'], '', $auth['port']); unset($auth);
if (($argv[6] ?? '') === '--cleanup') {
    $admin->query('DROP DATABASE IF EXISTS `' . $name . '`');
    $user = "'" . $admin->real_escape_string($config->user) . "'@'127.0.0.1'";
    $admin->query('REVOKE ALL PRIVILEGES ON `' . $name . '`.* FROM ' . $user);
    echo '{"database_removed":true}'; exit;
}
// The caller retains one stopped-writer window across file and database capture.
$tree=$argv[7] ?? ''; $treeState=json_decode(file_get_contents($tree.'/state.json'),true,32,JSON_THROW_ON_ERROR);
$manifest=json_decode(file_get_contents($tree.'/manifests/initial-tree/state.json'),true,32,JSON_THROW_ON_ERROR);
if (($treeState['phase']??'')!=='complete' || ($manifest['phase']??'')!=='complete'
    || $treeState['root']!==realpath($argv[1])
    || $treeState['manifest_sha256']!==hash('sha256',json_encode($manifest['entries'],JSON_THROW_ON_ERROR))) {
    throw new RuntimeException('Completed source capture required');
}
foreach ($manifest['entries'] as $key=>$entry) {
    if ($entry['type']==='file' && hash_file('sha256',$argv[1].'/'.$key)!==hash_file('sha256',$clone.'/'.$key)) {
        throw new RuntimeException('Clone or source changed before database recovery');
    }
}
$parts = explode(':', $config->host);
$source = new mysqli($parts[0], $config->user, $config->password, $config->db, (int) ($parts[1] ?? 3306));
$source->set_charset('utf8mb4');
$capture = new Nicode\Maintenance\ResumableDatabase($store);
$times=[];
for ($i=0; $i<3000; $i++) {
    $t=microtime(true); $dc=$capture->step($source, 'joint-database', true); $times[]=microtime(true)-$t;
    if ($dc['status']==='complete') { break; }
}
if ($dc['status']!=='complete') { throw new RuntimeException('Incomplete database'); }
$export=$capture->export('joint-database','site-database-capture');
$database = new Nicode\Maintenance\DatabaseSnapshot($store);
$fc=['scope'=>'previously_verified_resumable_tree_in_same_stopped_writer_window'];
$admin->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->select_db($name); $admin->set_charset('utf8mb4');
$dr = $database->restore($admin, 'site-database-capture', 'site-database-restore', true);
$fr=['scope'=>'previously_restored_and_all_file_hashes_compared'];
$compared=0;
foreach ($source->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $row) {
    $table='`'.str_replace('`','``',$row[0]).'`';
    $digest=static function(mysqli $db) use ($table): array {
        $rows=[];
        foreach ($db->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_NUM) as $values) {
            $rows[]=hash('sha256',serialize(array_map(static fn($v)=>$v===null?null:(string)$v,$values)));
        }
        sort($rows); return [$db->query('SHOW CREATE TABLE '.$table)->fetch_row()[1],$rows];
    };
    if ($digest($source)!==$digest($admin)) { throw new RuntimeException('Database differs'); }
    $compared++;
}
// Explicit lab relocation, after byte-for-byte restoration. Never edit source config.
$user = "'" . $admin->real_escape_string($config->user) . "'@'127.0.0.1'";
$admin->query('GRANT ALL ON `' . $name . '`.* TO ' . $user);
$config->db = $name; $config->tmp_path = $clone . '/tmp'; $config->log_path = $clone . '/administrator/logs';
$config->live_site = ''; $config->cookie_domain = ''; $config->cookie_path = ''; $config->force_ssl = 0;
$config->mailonline = false; $config->offline = false;
$text = "<?php\nclass JConfig {\n";
foreach (get_object_vars($config) as $key => $value) { $text .= 'public $' . $key . ' = ' . var_export($value, true) . ";\n"; }
if (file_put_contents($clone . '/configuration.php', $text . "}\n") === false) { throw new RuntimeException('Cannot relocate clone'); }
// Disable cloned connector identity before boot, and scheduler tasks to prevent outgoing work.
$prefix = '`' . str_replace('`', '``', $config->dbprefix);
$admin->query("UPDATE " . $prefix . "extensions` SET enabled=0 WHERE element='nicodewebmonitor' AND folder IN ('system','task')");
$admin->query('UPDATE ' . $prefix . 'scheduler_tasks` SET state=0');
echo json_encode(['files_capture' => $fc, 'database_capture' => $dc, 'files_restore' => $fr,
    'database_restore' => $dr, 'tables_compared'=>$compared, 'db_steps'=>count($times), 'max_db_step_seconds'=>max($times), 'clone_relocated' => true, 'application_verified' => false], JSON_PRETTY_PRINT);
