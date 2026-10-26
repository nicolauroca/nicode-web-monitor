<?php
/** Private hashes/schema for disposable native restore comparison. No row values exported.
 * @package Nicode.joomla.webmonitor
 */
if (($argv[3] ?? '') !== '--allow-disposable-writes') { exit(2); }
require $argv[1].'/configuration.php';
$c=new JConfig();$parts=explode(':',$c->host);
$db=new mysqli($parts[0],$c->user,$c->password,$c->db,(int)($parts[1]??3306));$db->set_charset('utf8mb4');
$baseline=[];
foreach($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $row){
 $table='`'.str_replace('`','``',$row[0]).'`';$rows=[];
 foreach($db->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_NUM) as $r){$rows[]=hash('sha256',serialize(array_map(static fn($v)=>$v===null?null:(string)$v,$r)));}
 sort($rows);$baseline[$row[0]]=[$db->query('SHOW CREATE TABLE '.$table)->fetch_row()[1],$rows];
}
file_put_contents($argv[2],json_encode($baseline,JSON_THROW_ON_ERROR));echo count($baseline)." baseline tables\n";
