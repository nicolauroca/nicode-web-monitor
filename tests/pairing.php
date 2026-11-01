<?php
/** Package: Nicode.joomla.webmonitor. Disposable database integration; no network. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
require __DIR__.'/../extensions/com_nicodewebmonitor/administrator/src/Service/SiteRegistry.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
$c=Factory::getContainer(); $c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class); Factory::$application=$app;
$app->createExtensionNamespaceMap(); $app->bootComponent('com_nicodewebmonitor');
$db=$c->get(DatabaseInterface::class); $checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function rejects($fn,$name){try{$fn();}catch(Throwable){check(true,$name);return;}check(false,$name);}
$admin=new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
$vault=new CredentialVault($app->get('secret'));
$token=bin2hex(random_bytes(32)); $uuid='c3c6d3e1-f65b-4000-8000-'.bin2hex(random_bytes(6));
$calls=0; $result=['ok'=>false,'error'=>'identity_mismatch'];
$factory=function($timeout)use(&$calls,&$result){
    check($timeout===5000,'pairing bounded to five seconds');
    return new class($calls,$result){
        public function __construct(private mixed &$calls,private mixed &$result){}
        public function fetch(...$args):array{$this->calls++;return $this->result;}
    };
};
$registry=new SiteRegistry($db,$vault,$admin,$factory);
$args=['Pairing fixture','https://example.invalid','93.184.216.34',$uuid,$token];
$db->transactionStart();
try {
    $before=(int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult();
    rejects(fn()=>$registry->pair(...$args),'identity failure rejected');
    check($calls===1,'one transport attempt on failure');
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===$before,'failure leaves no registration');
    $reader=new class extends User{public function authorise($action,$assetname=null){return $action==='core.manage';}};
    rejects(fn()=>(new SiteRegistry($db,$vault,$reader,$factory))->pair(...$args),'reader pairing denied');
    rejects(fn()=>$registry->pair('Bad','http://example.invalid','127.0.0.1',$uuid,$token),'invalid endpoint rejected');
    check($calls===1,'denied and invalid requests never connect');
    $result=['ok'=>true,'inventory'=>['status'=>'partial','collected_at'=>gmdate('Y-m-d\TH:i:s\Z')]];
    $paired=$registry->pair(...$args);
    check($paired['status']==='partial' && $paired['id']>0,'partial inventory explicitly paired');
    $row=$db->setQuery('SELECT * FROM #__nwm_sites WHERE id='.$paired['id'])->loadAssoc();
    check($row['credential']!==$token && $vault->open($uuid,$row['credential'])===$token,'pairing encrypts credential');
    check($db->setQuery('SELECT detail FROM #__nwm_checks WHERE site='.$paired['id'])->loadResult()==='partial','first observation saved');
    check(!str_contains(json_encode($paired),$token),'pairing result excludes credential');
    rejects(fn()=>$registry->pair(...$args),'duplicate identity rejected atomically');
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_checks WHERE site='.$paired['id'])->loadResult()===1,'duplicate creates no extra observation');
    $args[3]='c3c6d3e1-f65b-4000-8000-'.bin2hex(random_bytes(6));
    $result['inventory']['status']='complete';
    check($registry->pair(...$args)['status']==='complete','complete inventory paired');
}finally{$db->transactionRollback();}
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
