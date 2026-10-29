<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor
 * Local CLI only. No download, installation, credential handling or automatic recovery claim.
 */
if (PHP_SAPI !== 'cli' || count($argv)!==6 || $argv[5]!=='--writers-stopped-and-recovery-verified') {
    fwrite(STDERR,"Usage: php prepare_extension_update.php JOOMLA_ROOT PRIVATE_UPDATE_DIR TRUSTED_EXTENSION_ZIP OPERATION --writers-stopped-and-recovery-verified\n");exit(2);
}
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ExtensionIdentity;
require __DIR__.'/../extensions/plg_system_nicodewebmonitor/src/Maintenance/ExtensionIdentity.php';
$storage=realpath($argv[2]);$package=realpath($argv[3]);$operation=$argv[4];
function normalized(string $p):string{return strtolower(str_replace('\\','/',rtrim($p,'/\\')));}
if (!$storage || !$package || !is_dir($storage) || is_link($argv[2]) || !is_file($package) || is_link($argv[3])
    || filesize($package)>67108864 || !preg_match('/^[a-z0-9-]{8,64}$/D',$operation)) {throw new RuntimeException('Invalid local inputs');}
$s=normalized($storage);$w=normalized(JPATH_BASE);
if($s===$w || str_starts_with($s,$w.'/') || str_starts_with($w,$s.'/'))throw new RuntimeException('Storage overlaps web root');
if (!Factory::getConfig()->get('offline'))throw new RuntimeException('Site must already be offline; stop all other writers separately');
$zip=new ZipArchive();if($zip->open($package)!==true)throw new RuntimeException('Invalid ZIP');
try {
    $found=[];
    for($i=0;$i<$zip->numFiles;$i++){
        $name=$zip->getNameIndex($i);
        if(!preg_match('/^[a-z0-9_]+\.xml$/D',$name))continue;
        $xml=$zip->getFromIndex($i);
        if(!is_string($xml)||strlen($xml)>65536||stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)continue;
        $m=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET);
        if($m && $m->getName()==='extension' && in_array((string)$m['type'],['plugin','module','component'],true))$found[]=[$name,$m];
    }
    if(count($found)!==1)throw new RuntimeException('Exactly one supported root manifest required');
    [$name,$m]=$found[0];
    $identity=ExtensionIdentity::read($m,$name);$to=(string)$m->version;
} finally {$zip->close();}
$db=Factory::getContainer()->get(DatabaseInterface::class);
$row=$db->setQuery("SELECT extension_id,manifest_cache,protected FROM #__extensions WHERE type=".$db->quote($identity['type']).' AND client_id='.$identity['client_id'].' AND element='.$db->quote($identity['element']).' AND folder='.$db->quote($identity['folder']))->loadAssoc();
if(!$row||(int)$row['protected']!==0)throw new RuntimeException('Installed non-core extension required');
$from=json_decode($row['manifest_cache'],true,16,JSON_THROW_ON_ERROR)['version'];
if(strlen($to)>64||strlen($from)>64||!version_compare($to,$from,'>'))throw new RuntimeException('Newer version required');
$plan=['extension_id'=>(int)$row['extension_id'],'from'=>$from,'to'=>$to,'package_sha256'=>hash_file('sha256',$package)];
foreach(['journal','prepared'] as $child){
    if(!is_dir($storage.'/'.$child)&&!mkdir($storage.'/'.$child,0700))throw new RuntimeException('Cannot create private directory');
    if(is_link($storage.'/'.$child))throw new RuntimeException('Linked private directory denied');
}
if(file_exists($storage.'/journal/'.$operation))throw new RuntimeException('Operation already used');
$dir=$storage.'/prepared/'.$operation;
if(!mkdir($dir,0700))throw new RuntimeException('Operation already prepared or unavailable');
if(!copy($package,$dir.'/package.zip')||!hash_equals($plan['package_sha256'],hash_file('sha256',$dir.'/package.zip')))throw new RuntimeException('Copy verification failed');
$permit=['operation'=>$operation,'writers_stopped'=>true,'recovery_verified'=>true,'expires_at'=>time()+900,
    'identity'=>$identity,'plan'=>$plan];
$bytes=json_encode($permit,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
if(file_put_contents($dir.'/permit.json',$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Permit write failed');
echo json_encode(['operation'=>$operation,'plan'=>$plan,'expires_at'=>$permit['expires_at'],
    'installed'=>false,'recovery'=>'operator_attestation_only'],JSON_THROW_ON_ERROR)."\n";
