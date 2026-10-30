<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Local preparation only; remote compound updates remain disabled. */
if (PHP_SAPI !== 'cli' || count($argv)!==6 || $argv[5]!=='--writers-stopped-and-recovery-verified') {
    fwrite(STDERR,"Usage: php prepare_package_update.php JOOMLA_ROOT PRIVATE_DIR TRUSTED_ZIP OPERATION --writers-stopped-and-recovery-verified\n");exit(2);
}
$web=realpath($argv[1]);$storage=realpath($argv[2]);$package=realpath($argv[3]);$id=$argv[4];
if (!$web || !$storage || !$package || !is_dir($storage) || is_link($argv[2]) || is_link($argv[3])
    || !preg_match('/^[a-z0-9-]{8,64}$/D',$id)) { throw new RuntimeException('Invalid local inputs'); }
$normalize=fn($p)=>strtolower(str_replace('\\','/',rtrim($p,'/\\')));
$s=$normalize($storage);$w=$normalize($web);
if($s===$w || str_starts_with($s,$w.'/') || str_starts_with($w,$s.'/'))throw new RuntimeException('Private directory overlaps web root');
define('_JEXEC',1);define('JPATH_BASE',$web);
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
require __DIR__.'/../extensions/plg_system_nicodewebmonitor/src/Maintenance/ExtensionIdentity.php';
require __DIR__.'/PackagePreflight.php';require __DIR__.'/PackageState.php';
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Nicode\Maintenance\PackagePreflight;
use Nicode\Maintenance\PackageState;
if(!Factory::getConfig()->get('offline'))throw new RuntimeException('Offline site required; stop other writers separately');
$report=PackagePreflight::inspect($package,hash_file('sha256',$package));
$state=new PackageState(Factory::getContainer()->get(DatabaseInterface::class));
$identities=array_column($report['targets'],'identity');
$before=$state->inspect($report['element'],$identities);$plan=PackagePreflight::plan($report,$before);
foreach(['journal','prepared'] as $child){
    $dir=$storage.'/'.$child;
    if(is_link($dir) || (file_exists($dir)&&!is_dir($dir)))throw new RuntimeException('Invalid private child directory');
    if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('Cannot create private directory');
}
if(file_exists($storage.'/journal/'.$id))throw new RuntimeException('Operation already used');
$dir=$storage.'/prepared/'.$id;
if(file_exists($dir)||!mkdir($dir,0700))throw new RuntimeException('Operation already prepared');
// A failed preparation leaves the reserved directory without a permit: never reuse the ID.
if(!copy($package,$dir.'/package.zip'))throw new RuntimeException('Cannot copy package');
$copied=PackagePreflight::inspect($dir.'/package.zip',$report['package_sha256']);
if($copied!==$report || $state->inspect($report['element'],$identities)!==$before)throw new RuntimeException('Preparation baseline changed');
$permit=['kind'=>'compound-package-v1','operation'=>$id,'writers_stopped'=>true,'recovery_verified'=>true,
    'expires_at'=>time()+900,'element'=>$report['element'],'children'=>$report['children'],'plan'=>$plan];
$bytes=json_encode($permit,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);$f=fopen($dir.'/permit.json','x');
if(!$f)throw new RuntimeException('Cannot reserve permit');
try{if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Cannot persist permit');}finally{fclose($f);}
echo json_encode(['operation'=>$id,'plan'=>$plan,'expires_at'=>$permit['expires_at'],'installed'=>false,
    'remote_execution_enabled'=>false,'recovery'=>'operator_attestation_only'],JSON_THROW_ON_ERROR)."\n";
