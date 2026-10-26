<?php
/** Package: Nicode.joomla.webmonitor. Disposable Joomla only; synthetic disabled plugin. */
if (($argv[3] ?? '') !== '--allow-disposable-writes') { exit(2); }
define('_JEXEC', 1); define('JPATH_BASE', realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
require __DIR__.'/../tools/ExtensionUpdate.php';
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\Database\DatabaseInterface;
use Nicode\Maintenance\ExtensionUpdate;
$c=Factory::getContainer();
$c->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$c->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application->createExtensionNamespaceMap();
$db=$c->get(DatabaseInterface::class);
$root=realpath($argv[2]);
if (!$root || file_exists($root.'/journal')) { throw new RuntimeException('Fresh private output required'); }
mkdir($root.'/journal',0700);
$checks=[];
function check($ok,$name) { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[]=$name; }
$element='nwmupdateqa';
$query="SELECT extension_id,manifest_cache,enabled FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nwmupdateqa'";
check(!$db->setQuery($query)->loadAssoc(),'fixture absent before install');
function package($root,$version) {
    $path=$root.'/fixture-'.$version.'.zip'; $zip=new ZipArchive();
    if ($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true) { throw new RuntimeException('ZIP failed'); }
    $zip->addFromString('nwmupdateqa.xml','<?xml version="1.0"?><extension type="plugin" group="system" method="upgrade"><name>NWM Update QA</name><version>'.$version.'</version><description>Disposable fixture</description><files><filename plugin="nwmupdateqa">nwmupdateqa.php</filename><filename>version.txt</filename></files></extension>');
    $zip->addFromString('nwmupdateqa.php',"<?php\ndefined('_JEXEC') or die;\n");
    $zip->addFromString('version.txt',$version); $zip->close(); return $path;
}
function nativeInstall($path,$digest) {
    global $db;
    if (!hash_equals($digest,hash_file('sha256',$path))) { throw new RuntimeException('Package changed'); }
    $p=InstallerHelper::unpack($path,true);
    if (!$p || $p['type']===false) { throw new RuntimeException('Unpack failed'); }
    try { $i=new Installer(); $i->setDatabase($db); return $i->install($p['extractdir']); }
    finally { InstallerHelper::cleanupInstall(null,$p['extractdir']); }
}
$id=null;
try {
    $v1=package($root,'1.0.0'); $v2=package($root,'1.1.0'); $v3=package($root,'1.2.0');
    check(nativeInstall($v1,hash_file('sha256',$v1)),'native initial install');
    $row=$db->setQuery($query)->loadAssoc(); $id=(int)$row['extension_id'];
    check((int)$row['enabled']===0,'fixture stays disabled');
    $inspect=function()use($db,$query){$r=$db->setQuery($query)->loadAssoc();return ['extension_id'=>(int)$r['extension_id'],'version'=>json_decode($r['manifest_cache'],true)['version']];};
    $plan=['extension_id'=>$id,'from'=>'1.0.0','to'=>'1.1.0','package_sha256'=>hash_file('sha256',$v2)];
    $core=new ExtensionUpdate($root.'/journal'); $calls=0;
    $install=function($hash)use($v2,&$calls){$calls++;return nativeInstall($v2,$hash);};
    foreach ([[false,true],[true,false]] as [$auth,$confirm]) {
        try {$core->run('update-0001',$plan,$auth,$confirm,$inspect,$install);throw new LogicException('guard did not reject');}
        catch(RuntimeException $e){check($e->getMessage()==='Permission and confirmation required','permission/confirmation denied');}
    }
    check($calls===0 && !is_dir($root.'/journal/update-0001'),'denials have no installer effects');
    $bad=$plan;$bad['from']='0.9.0';
    try {$core->run('update-stale',$bad,true,true,$inspect,$install);throw new LogicException('stale baseline accepted');}
    catch(RuntimeException $e){check($e->getMessage()==='Installed baseline changed','stale baseline rejected');}
    $r=$core->run('update-0001',$plan,true,true,$inspect,$install);
    check($r['status']==='updated' && $calls===1,'native update success recorded');
    check(trim(file_get_contents(JPATH_BASE.'/plugins/system/nwmupdateqa/version.txt'))==='1.1.0','installed file updated');
    check($inspect()===['extension_id'=>$id,'version'=>'1.1.0'],'extension id preserved and manifest version updated');
    check($core->run('update-0001',$plan,true,true,$inspect,$install)===$r && $calls===1,'duplicate returns receipt without reinstall');
    $conflict=$plan;$conflict['to']='1.2.0';
    try {$core->run('update-0001',$conflict,true,true,$inspect,$install);throw new LogicException('conflict accepted');}
    catch(RuntimeException $e){check($e->getMessage()==='Operation conflict','id reuse conflict denied');}
    $next=['extension_id'=>$id,'from'=>'1.1.0','to'=>'1.2.0','package_sha256'=>hash_file('sha256',$v3)];
    $lost=function($hash)use($v3,&$calls){$calls++;nativeInstall($v3,$hash);throw new RuntimeException('Simulated lost completion');};
    try {$core->run('update-0002',$next,true,true,$inspect,$lost);}catch(RuntimeException $e){check($e->getMessage()==='Simulated lost completion','loss injected after native side effect');}
    $core=new ExtensionUpdate($root.'/journal');
    check($core->status('update-0002')['status']==='uncertain','uncertain receipt survives restart');
    check($inspect()['version']==='1.2.0','lost completion really installed new version');
    check($core->run('update-0002',$next,true,true,$inspect,$lost)['status']==='uncertain' && $calls===2,'uncertain operation never retried');
    file_put_contents($root.'/result.json',json_encode(['passed'=>count($checks),'checks'=>$checks,'native_installer'=>true,'http_acl'=>false,'joomla_core_update'=>false],JSON_PRETTY_PRINT));
    echo json_encode(['passed'=>count($checks),'native_update'=>true])."\n";
} finally {
    if ($id!==null) { $i=new Installer();$i->setDatabase($db);check($i->uninstall('plugin',$id),'native fixture uninstall'); }
    check(!$db->setQuery($query)->loadAssoc(),'fixture extension row removed');
    check(!is_dir(JPATH_BASE.'/plugins/system/nwmupdateqa'),'fixture files removed');
    file_put_contents($root.'/cleanup.json',json_encode(['removed'=>true,'checks'=>array_slice($checks,-3)],JSON_PRETTY_PRINT));
}
