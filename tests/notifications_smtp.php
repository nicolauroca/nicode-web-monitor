<?php
/** @package Nicode.joomla.webmonitor. Real Joomla mailer to a disposable loopback TLS sink. */
if (($argv[2]??'')!=='--allow-disposable-writes')exit(2);
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!is_int($input['port']??null)||$input['port']<1024||$input['port']>65535)exit(2);
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailerInterface;
use Joomla\Registry\Registry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\Notifications;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;
$app->createExtensionNamespaceMap();$app->bootComponent('com_nicodewebmonitor');$app->set('mailonline',1);
$factory=new class($input) implements MailerFactoryInterface {
    public function __construct(private array $input){}
    public function createMailer(?Registry $settings=null):MailerInterface {
        $mailer=new Joomla\CMS\Mail\Mail(true);
        $mailer->isSMTP();$mailer->Host='127.0.0.1';$mailer->Port=$this->input['port'];$mailer->SMTPSecure='ssl';
        $mailer->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'cafile'=>$this->input['ca']]];
        $mailer->setFrom('sender@example.invalid','Disposable NWM');
        return $mailer;
    }
};
$db=$c->get(DatabaseInterface::class);
if((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()!==0)throw new RuntimeException('Disposable registry must be empty');
$admin=new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
$registry=new SiteRegistry($db,new CredentialVault($app->get('secret')),$admin);$id=0;
try {
    $registry->add('SMTP fixture','https://example.invalid','93.184.216.34','c3c6d3e1-f65b-4000-8000-'.bin2hex(random_bytes(6)),bin2hex(random_bytes(32)));
    $id=(int)$db->insertid();
    $notices=new Notifications($db,$admin,mailerFactory:$factory);
    $sent=$notices->run(true,'recipient@example.invalid');
    if($sent['accepted']!==1)throw new RuntimeException('Local TLS SMTP acceptance failed');
    $repeat=$notices->run(true,'recipient@example.invalid');
    if($repeat['accepted']!==0)throw new RuntimeException('Duplicate send');
    echo json_encode(['accepted'=>1,'duplicate_attempts'=>0,'tls_peer_verified'=>true,'external_delivery'=>false]),"\n";
}finally{if($id)$registry->remove($id);}
