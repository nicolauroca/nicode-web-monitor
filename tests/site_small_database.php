<?php
declare(strict_types=1);
/** Disposable joint fixture. @package Nicode.joomla.webmonitor */
require dirname(__DIR__).'/tools/ResumableSite.php';
require dirname(__DIR__).'/tools/ResumableTree.php';
require dirname(__DIR__).'/tools/ResumableDatabase.php';
if (($argv[2]??'')!=='--allow-disposable-writes') exit(2);
$a=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$db=new mysqli($a['host'],$a['user'],$a['password'],'',$a['port']); unset($a);
$name='nwm_joint_'.bin2hex(random_bytes(5)); $root=$argv[1];
foreach (['source','trees','databases','sites'] as $d) mkdir($root.'/'.$d);
file_put_contents($root.'/source/data','joint fixture');
$db->query('CREATE DATABASE `'.$name.'`');
try {
 $db->select_db($name); $db->query('CREATE TABLE fixture (id INT PRIMARY KEY, value VARCHAR(30))');
 $db->query("INSERT INTO fixture VALUES (1,'preserved')");
 $files=fn($id)=>(new Nicode\Maintenance\ResumableTree($root.'/trees'))->step($root.'/source',$id,true,64,true);
 $database=function($id) use($root,$db) {
  if (is_file($root.'/trees/'.$id.'/manifests/final-tree/state.json')) throw new RuntimeException('Premature verification');
  return (new Nicode\Maintenance\ResumableDatabase($root.'/databases'))->step($db,$id,true);
 };
 $verify=fn($id)=>(new Nicode\Maintenance\ResumableTree($root.'/trees'))->step($root.'/source',$id,true,64);
 $provider=new Nicode\Maintenance\ResumableSite($root.'/sites');
 for($i=0;$i<100;$i++) { $r=$provider->step('real-joint',$files,$database,$verify); if($r['status']==='complete') break; }
 if($r['status']!=='complete') throw new RuntimeException('No completion');
 $tree=json_decode(file_get_contents($root.'/trees/real-joint/state.json'),true);
 if($tree['phase']!=='complete') throw new RuntimeException('Unverified tree');
 echo json_encode(['complete'=>true,'steps'=>$i+1,'database_before_final_scan'=>true,'tree_complete'=>true,'restoration_verified'=>false]);
} finally { $db->query('DROP DATABASE `'.$name.'`'); }
