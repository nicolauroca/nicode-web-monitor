<?php
declare(strict_types=1);
/** Disposable lab only. Keep stdout free of configuration/credentials. */
if (($argv[5] ?? '') !== '--allow-disposable-writes') { exit(2); }
require dirname(__DIR__) . '/tools/DatabaseSnapshot.php';
require dirname(__DIR__) . '/tools/FileSnapshot.php';
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
$parts = explode(':', $config->host);
$source = new mysqli($parts[0], $config->user, $config->password, $config->db, (int) ($parts[1] ?? 3306));
$source->set_charset('utf8mb4');
$files = new Nicode\Maintenance\FileSnapshot($store);
$database = new Nicode\Maintenance\DatabaseSnapshot($store);
$fc = $files->capture($argv[1], 'site-files-capture', true);
$dc = $database->capture($source, 'site-database-capture', true);
$admin->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->select_db($name); $admin->set_charset('utf8mb4');
$dr = $database->restore($admin, 'site-database-capture', 'site-database-restore', true);
$fr = $files->restore('site-files-capture', 'site-files-restore', $clone, true);
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
    'database_restore' => $dr, 'clone_relocated' => true, 'application_verified' => false], JSON_PRETTY_PRINT);
