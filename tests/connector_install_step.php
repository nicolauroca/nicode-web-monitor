<?php
/** Native install probe for an explicitly disposable Joomla. Never run on a user's site. */
if(($argv[2]??'')!=='--allow-disposable-writes')exit(2);
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;use Joomla\CMS\Installer\Installer;use Joomla\CMS\Installer\InstallerHelper;use Joomla\Database\DatabaseInterface;
$request=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
if(isset($request['language'])){
    if(!in_array($request['language'],['en-GB','es-ES'],true))throw new RuntimeException('Invalid fixture language');
    // A stock English-only CLI installation has no Spanish metadata. This fixture
    // supplies only a tag for the native Language object, then removes it at exit.
    $metadata=JPATH_BASE.'/language/es-ES/langmetadata.xml';
    if($request['language']==='es-ES'&&!is_file($metadata)){
        $createdDirectory=!is_dir(dirname($metadata));if($createdDirectory)mkdir(dirname($metadata));
        file_put_contents($metadata,str_replace(['en-GB','en_GB'],['es-ES','es_ES'],file_get_contents(JPATH_BASE.'/language/en-GB/langmetadata.xml')));
        register_shutdown_function(static function()use($metadata,$createdDirectory){unlink($metadata);if($createdDirectory)rmdir(dirname($metadata));});
    }
    $c->get('config')->set('language',$request['language']);
}
Factory::$application=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application->createExtensionNamespaceMap();
$db=$c->get(DatabaseInterface::class);$ok=true;
$where="(type='package' AND element='pkg_nicodeconnector') OR (type='component' AND element='com_nicodeconnector') OR (type='plugin' AND folder='system' AND element='nicodewebmonitor')";
if($request['action']==='install'){
    $package=InstallerHelper::unpack($request['zip'],true);if(!$package)throw new RuntimeException('Cannot unpack fixture');
    try{$installer=new Installer();$installer->setDatabase($db);$ok=$installer->install($package['extractdir']);}
    finally{Joomla\Filesystem\Folder::delete($package['extractdir']);}
}elseif($request['action']==='uninstall'){
    $row=$db->setQuery('SELECT extension_id FROM #__extensions WHERE type=\'package\' AND element=\'pkg_nicodeconnector\'')->loadResult();
    if(!$row)throw new RuntimeException('Expected disposable connector package');
    $installer=new Installer();$installer->setDatabase($db);$ok=$installer->uninstall('package',(int)$row);
}elseif($request['action']==='seed'){
    $params=json_encode(['inventory_sharing'=>['joomla'=>true,'runtime'=>false],'fixture'=>'preserved']);
    $db->setQuery("UPDATE #__extensions SET params=".$db->quote($params)." WHERE type='component' AND element='com_nicodeconnector'")->execute();
}elseif($request['action']!=='inspect'){throw new RuntimeException('Unknown fixture action');}
$rows=$db->setQuery('SELECT extension_id,type,element,folder,package_id,params,manifest_cache FROM #__extensions WHERE '.$where.' ORDER BY extension_id')->loadAssocList();
foreach($rows as &$row){$row['params_hash']=hash('sha256',$row['params']);unset($row['params']);$row['version']=json_decode($row['manifest_cache'],true)['version']??null;unset($row['manifest_cache']);}unset($row);
echo "\nNWM_RESULT ".json_encode(['ok'=>(bool)$ok,'joomla'=>JVERSION,'php'=>PHP_VERSION,'sodium'=>extension_loaded('sodium'),'curl'=>extension_loaded('curl'),
    'central_installed'=>(bool)$db->setQuery("SELECT extension_id FROM #__extensions WHERE type='component' AND element='com_nicodewebmonitor'")->loadResult(),
    'extensions'=>$rows,'messages'=>Factory::$application->getMessageQueue()],JSON_THROW_ON_ERROR)."\n";
