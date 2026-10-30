<?php
/** @package Nicode.joomla.webmonitor. Synthetic package in disposable offline Joomla. */
if (($argv[3]??'') !== '--allow-disposable-writes') { exit(2); }
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
foreach (['PackageState','PackageOperation','MaintenanceGate'] as $class) require __DIR__.'/../tools/'.$class.'.php';
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\Database\DatabaseInterface;
use Nicode\Maintenance\PackageState;
use Nicode\Maintenance\PackageOperation;
use Nicode\Maintenance\MaintenanceGate;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$c->get(\Joomla\CMS\Application\ConsoleApplication::class);Factory::$application->createExtensionNamespaceMap();
$db=$c->get(DatabaseInterface::class);$root=realpath($argv[2]);
if (!$root || file_exists($root.'/journal') || !Factory::getConfig()->get('offline')) throw new RuntimeException('Fresh output and offline site required');
mkdir($root.'/journal',0700);mkdir($root.'/gate',0700);
$checks=[];
function check($v,$name){global $checks;if(!$v)throw new RuntimeException($name);$checks[]=$name;}
function fixture($version,$fail=false){
    global $root;
    foreach (['library','module'] as $type) {
        $element=$type==='library'?'nwmpackageqa':'mod_nwmpackageqa';
        $z=new ZipArchive();$z->open($root.'/'.$type.'-'.$version.'.zip',ZipArchive::CREATE|ZipArchive::EXCL);
        $files=$type==='library'?'<libraryname>'.$element.'</libraryname><files><filename>index.php</filename><filename>version.txt</filename></files>':'<files><filename module="'.$element.'">'.$element.'.php</filename><filename>version.txt</filename></files>';
        if($fail && $type==='module') {
            $files.='<scriptfile>script.php</scriptfile>';
            $z->addFromString('script.php','<?php class mod_nwmpackageqaInstallerScript { public function preflight($type,$parent) { return false; } }');
        }
        $z->addFromString($element.'.xml','<extension type="'.$type.'" client="site" method="upgrade"><name>'.$element.'</name><version>'.$version.'</version>'.$files.'</extension>');
        $z->addFromString($type==='library'?'index.php':$element.'.php',"<?php defined('_JEXEC') or die;\n");
        $z->addFromString('version.txt',$version);$z->close();
    }
    $p=$root.'/package-'.$version.'.zip';$z=new ZipArchive();$z->open($p,ZipArchive::CREATE|ZipArchive::EXCL);
    $z->addFromString('pkg_nwmpackageqa.xml','<extension type="package" method="upgrade"><name>NWM Package QA</name><packagename>nwmpackageqa</packagename><version>'.$version.'</version><files><file type="library" id="nwmpackageqa">library.zip</file><file type="module" id="mod_nwmpackageqa" client="site">module.zip</file></files></extension>');
    foreach(['library','module'] as $t)$z->addFile($root.'/'.$t.'-'.$version.'.zip',$t.'.zip');
    $z->close();return $p;
}
function install($path,$digest){
    global $db;
    if(!hash_equals($digest,hash_file('sha256',$path)))throw new RuntimeException('Package changed');
    $p=InstallerHelper::unpack($path,true);if(!$p)throw new RuntimeException('Unpack failed');
    try{$i=new Installer();$i->setDatabase($db);return $i->install($p['extractdir']);}
    finally{\Joomla\Filesystem\Folder::delete($p['extractdir']);}
}
$identities=[['type'=>'library','element'=>'nwmpackageqa','folder'=>'','client_id'=>0],['type'=>'module','element'=>'mod_nwmpackageqa','folder'=>'','client_id'=>0]];
$state=new PackageState($db);$inspect=fn()=>$state->inspect('pkg_nwmpackageqa',$identities);
$gate=new MaintenanceGate($root.'/gate',JPATH_BASE);$journal=new PackageOperation($root.'/journal');
$initial=$inspect();check(!$initial['parents']&&!$initial['children'],'fixtures absent');
try{
    $p=fixture('1.0.0');check(install($p,hash_file('sha256',$p)),'native initial package installed');
    $before=$inspect();
    $targets=fn($v)=>array_map(fn($i)=>['identity'=>$i,'version'=>$v],$identities);
    check(PackageState::matches($before,'1.0.0',$targets('1.0.0')),'native parent and both child ownerships verified');
    $calls=0;
    foreach([['1.1.0',false],['1.2.0',true]] as [$version,$fail]){
        $p=fixture($version,$fail);$id=$fail?'compound-partial':'compound-success';
        $plan=['before'=>$before,'to'=>$version,'targets'=>$targets($version),'package_sha256'=>hash_file('sha256',$p)];
        $installer=function($digest)use($p,&$calls){$calls++;return install($p,$digest);};
        $run=fn()=>$gate->run('extension:'.$id,fn()=>$journal->run($id,$plan,true,true,$inspect,$installer));
        $receipt=$run();$after=$inspect();
        check($receipt['observed']===$after,'persisted exact observed package state '.$version);
        if(!$fail){
            check($receipt['status']==='updated','all children updated');
            $libraryBefore=array_values(array_filter($before['children'],fn($r)=>$r['identity']['type']==='library'))[0];
            $libraryAfter=array_values(array_filter($after['children'],fn($r)=>$r['identity']['type']==='library'))[0];
            check($libraryBefore['id']!==$libraryAfter['id'],'library replacement recorded');
            check(file_get_contents(JPATH_BASE.'/libraries/nwmpackageqa/version.txt')===$version,'library bytes updated');
            check(file_get_contents(JPATH_BASE.'/modules/mod_nwmpackageqa/version.txt')===$version,'module bytes updated');
            check($gate->currentOwner()===null,'success releases gate');
            $forged=$after;$forged['children'][0]['version']='0.0.0';
            check(!PackageState::matches($forged,$version,$targets($version)),'parent-only success rejected');
            $forged=$after;$forged['children'][0]['package_id']=0;
            check(!PackageState::matches($forged,$version,$targets($version)),'orphan rejected');
            $forged=$after;$forged['children'][]=$after['children'][0];
            check(!PackageState::matches($forged,$version,$targets($version)),'unexpected child rejected');
        }else{
            check($receipt['status']==='uncertain','failed second child stays uncertain');
            check($after!==$before,'native failure has real partial effects');
            $library=array_values(array_filter($after['children'],fn($r)=>$r['identity']['type']==='library'))[0];
            $module=array_values(array_filter($after['children'],fn($r)=>$r['identity']['type']==='module'))[0];
            check($library['version']==='1.2.0' && $library['package_id']===0,'updated orphan library observed by identity');
            check($module['version']==='1.1.0' && $after['parents'][0]['version']==='1.1.0','parent and second child retain old version');
            check($gate->currentOwner()==='extension:'.$id,'partial failure retains shared gate');
            try{$gate->run('backup:blocked-operation',fn()=>['status'=>'complete']);throw new LogicException('gate bypassed');}
            catch(RuntimeException $e){check($e->getMessage()==='Maintenance reserved','another maintenance operation denied');}
        }
        $n=$calls;check($run()===$receipt && $calls===$n,'same operation never reinstalls '.$version);
        check((new PackageOperation($root.'/journal'))->status($id)===$receipt,'receipt survives new journal instance '.$version);
        $before=$after;
    }
    file_put_contents($root.'/observed-partial.json',json_encode($before,JSON_PRETTY_PRINT));
}finally{
    // Only synthetic names installed above, even if native rollback orphaned a child.
    foreach([['package','pkg_nwmpackageqa'],['module','mod_nwmpackageqa'],['library','nwmpackageqa']] as [$type,$element]){
        $ids=$db->setQuery('SELECT extension_id FROM #__extensions WHERE type='.$db->quote($type).' AND element='.$db->quote($element))->loadColumn();
        foreach($ids as $id){$i=new Installer();$i->setDatabase($db);check($i->uninstall($type,(int)$id),'fixture removed '.$type);}
    }
    check($inspect()===['parents'=>[],'children'=>[]],'all synthetic rows removed');
    check(!is_dir(JPATH_BASE.'/libraries/nwmpackageqa')&&!is_dir(JPATH_BASE.'/modules/mod_nwmpackageqa'),'synthetic directories removed');
    file_put_contents($root.'/checks.json',json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT));
}
echo json_encode(['passed'=>count($checks),'cleanup'=>true])."\n";
