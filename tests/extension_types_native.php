<?php
/** @package Nicode.joomla.webmonitor. Disposable offline Joomla only. */
if (($argv[3] ?? '') !== '--allow-disposable-writes') { exit(2); }
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\Database\DatabaseInterface;
use Nicode\Plugin\System\NicodeWebMonitor\Maintenance\NativeExtensionUpdate;
$c=Factory::getContainer();
$c->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$c->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application->createExtensionNamespaceMap();
$db=$c->get(DatabaseInterface::class); $root=realpath($argv[2]);
if (!$root || file_exists($root.'/journal') || !Factory::getConfig()->get('offline')) { throw new RuntimeException('Fresh output and offline site required'); }
foreach (['journal','prepared','gate'] as $child) mkdir($root.'/'.$child,0700);
$checks=[];$ids=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function installZip($file){global $db;$p=InstallerHelper::unpack($file,true);if(!$p)throw new RuntimeException('Unpack failed');try{$i=new Installer();$i->setDatabase($db);check($i->install($p['extractdir']),'native install');}finally{\Joomla\Filesystem\Folder::delete($p['extractdir']);}}
function fixturePack($type,$client,$version){
    global $root;
    $element=($type==='module'?'mod_':($type==='component'?'com_':'')).'nwmtypesqa';
    $path=$root.'/'.$type.'-'.$client.'-'.$version.'.zip';$z=new ZipArchive();$z->open($path,ZipArchive::CREATE|ZipArchive::EXCL);
    $files=$type==='module'?'<files><filename module="'.$element.'">'.$element.'.php</filename><filename>version.txt</filename></files>':'<administration><menu>NWM types QA</menu><files folder="admin"><filename>nwmtypesqa.php</filename><filename>version.txt</filename></files></administration>';
    if(in_array($type,['template','library'],true))$files=($type==='library'?'<libraryname>'.$element.'</libraryname>':'').'<files><filename>index.php</filename><filename>version.txt</filename></files>';
    $failure=$type==='library' && $version==='1.2.0';
    if($failure){$files.='<scriptfile>script.php</scriptfile>';$z->addFromString('script.php','<?php class nwmtypesqaInstallerScript { public function postflight($type,$parent) { throw new RuntimeException("Injected native postflight failure"); } }');}
    $z->addFromString($type==='template'?'templateDetails.xml':$element.'.xml','<extension type="'.$type.'" client="'.($client?'administrator':'site').'" method="upgrade"><name>'.$element.'</name><version>'.$version.'</version>'.$files.'</extension>');
    $z->addFromString($type==='module'?$element.'.php':($type==='component'?'admin/nwmtypesqa.php':'index.php'),"<?php defined('_JEXEC') or die;\n");
    $z->addFromString($type==='component'?'admin/version.txt':'version.txt',$version);$z->close();return $path;
}
function prepare($zip,$operation){
    global $root;
    $p=proc_open([PHP_BINARY,__DIR__.'/../tools/prepare_extension_update.php',JPATH_BASE,$root,$zip,$operation,'--writers-stopped-and-recovery-verified'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0)throw new RuntimeException('Preparation failed: '.$err.$out);
    return json_decode($out,true,32,JSON_THROW_ON_ERROR);
}
try {
    foreach ((($argv[4]??'')==='--library'?[['library',0]]:(($argv[4]??'')==='--new-types'?[['template',0],['template',1]]:[['module',0],['module',1],['component',1]])) as [$type,$client]) {
        $element=($type==='module'?'mod_':($type==='component'?'com_':'')).'nwmtypesqa';
        $where='type='.$db->quote($type).' AND element='.$db->quote($element).' AND client_id='.$client;
        check(!$db->setQuery('SELECT extension_id FROM #__extensions WHERE '.$where)->loadResult(),'fixture absent '.$type.$client);
        installZip(fixturePack($type,$client,'1.0.0'));
        $id=(int)$db->setQuery('SELECT extension_id FROM #__extensions WHERE '.$where)->loadResult();$ids[]=[$type,$id,$where,$element,$client];
        check($id>0,'extension registered '.$type.$client);
        if($type==='template'){$db->setQuery('UPDATE #__template_styles SET title='.$db->quote('Custom style to retain').',params='.$db->quote('{"custom":"retain me"}').' WHERE template='.$db->quote($element).' AND client_id='.$client)->execute();$styles=$db->setQuery('SELECT * FROM #__template_styles WHERE template='.$db->quote($element).' AND client_id='.$client.' ORDER BY id')->loadAssocList();check(count($styles)===1,'template style created');}
        $v2=fixturePack($type,$client,'1.1.0');$op='types-'.$type.'-'.$client;
        $prepared=prepare($v2,$op);check($prepared['plan']['extension_id']===$id,'CLI selects exact type/client');
        $provider=new NativeExtensionUpdate($db,$root,JPATH_BASE,true,$root.'/gate');
        // Tampering with the target client/identity must fail before any reservation.
        $permitPath=$root.'/prepared/'.$op.'/permit.json';$original=file_get_contents($permitPath);$bad=json_decode($original,true);
        $bad['identity']['client_id']=$client===0?1:0;file_put_contents($permitPath,json_encode($bad));
        try{$provider->handle($op,true);throw new LogicException('Wrong client accepted');}catch(RuntimeException $e){check(!is_dir($root.'/journal/'.$op),'wrong client rejected without effects');}
        file_put_contents($permitPath,$original);
        if($type==='library'){
            $db->setQuery('UPDATE #__extensions SET package_id='.$id.' WHERE extension_id='.$id)->execute();
            try{
                try{$provider->handle($op,true);throw new LogicException('Package child accepted');}
                catch(RuntimeException $e){check($e->getMessage()==='Package-owned library unsupported' && !is_dir($root.'/journal/'.$op),'provider rejects package-owned library without effects');}
                try{prepare($v2,'package-child');throw new LogicException('Package child preparation accepted');}
                catch(RuntimeException $e){check(str_contains($e->getMessage(),'Package-owned library unsupported'),'CLI rejects package-owned library');}
            }finally{$db->setQuery('UPDATE #__extensions SET package_id=0 WHERE extension_id='.$id)->execute();}
        }
        $result=$provider->handle($op,true);check($result['status']==='updated','provider update '.$type.$client);
        $row=$db->setQuery('SELECT extension_id,manifest_cache FROM #__extensions WHERE '.$where)->loadAssoc();
        check(($type==='library'?((int)$row['extension_id']!==$id && $result['plan']['extension_id']===$id && $result['installed_extension_id']===(int)$row['extension_id']):(int)$row['extension_id']===$id) && json_decode($row['manifest_cache'],true)['version']==='1.1.0','honest original/current identity and new version');
        $path=JPATH_BASE.($client?'/administrator':'').(['module'=>'/modules/','component'=>'/components/','template'=>'/templates/','library'=>'/libraries/'][$type]).$element;
        check(file_get_contents($path.'/version.txt')==='1.1.0','installed bytes changed');
        if($type==='template')check($db->setQuery('SELECT * FROM #__template_styles WHERE template='.$db->quote($element).' AND client_id='.$client.' ORDER BY id')->loadAssocList()===$styles,'template style preserved');
        check($provider->handle($op,true)===$result,'duplicate returns receipt');
        check(!is_file($root.'/gate/owner.json'),'shared gate released');
        if($type==='library'){
            // Two matching rows must never cause an arbitrary target selection.
            $copy=$db->setQuery('SELECT * FROM #__extensions WHERE '.$where)->loadObject();unset($copy->extension_id);
            $db->insertObject('#__extensions',$copy,'extension_id');$duplicate=(int)$copy->extension_id;
            try{
                try{prepare($v2,'duplicate-library');throw new LogicException('Duplicate accepted');}
                catch(RuntimeException $e){check(str_contains($e->getMessage(),'Exactly one installed identity required'),'duplicate identity rejected before preparation');}
            }finally{$db->setQuery('DELETE FROM #__extensions WHERE extension_id='.$duplicate)->execute();}
            $failed='library-partial';prepare(fixturePack($type,$client,'1.2.0'),$failed);
            check($provider->handle($failed,true)['status']==='uncertain','native postflight failure remains uncertain');
            $observed=$db->setQuery('SELECT extension_id,manifest_cache FROM #__extensions WHERE '.$where)->loadAssoc();
            check(!$observed,'native failure really removed original library without replacement');
            check($provider->handle($failed,false)['status']==='uncertain','partial native effects never certified');
            check($provider->handle($failed,true)['status']==='uncertain','partial operation cannot reinstall');
            check($db->setQuery('SELECT extension_id,manifest_cache FROM #__extensions WHERE '.$where)->loadAssoc()===$observed,'retry preserves partial effects for reconciliation');
            check(is_file($root.'/gate/owner.json'),'uncertain operation retains shared maintenance gate');
        }
    }
} finally {
    foreach (array_reverse($ids) as [$type,$id,$where,$element,$client]) {
        $currentId=(int)$db->setQuery('SELECT extension_id FROM #__extensions WHERE '.$where)->loadResult();
        if($currentId){$i=new Installer();$i->setDatabase($db);check($i->uninstall($type,$currentId),'native uninstall '.$type.$client);}
        check(!$db->setQuery('SELECT extension_id FROM #__extensions WHERE '.$where)->loadResult(),'extension row removed');
        check(!is_dir(JPATH_BASE.($client?'/administrator':'').(['module'=>'/modules/','component'=>'/components/','template'=>'/templates/','library'=>'/libraries/'][$type]).$element),'installed directory removed');
    }
    file_put_contents($root.'/checks.json',json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT));
}
echo json_encode(['passed'=>count($checks),'types'=>($argv[4]??'default'),'cleanup'=>true])."\n";
