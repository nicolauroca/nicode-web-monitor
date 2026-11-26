<?php
/** Refresh the public release facts shipped in the next development package. No Joomla or site data. */
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$service=$root.'/extensions/com_nicodewebmonitor/administrator/src/Service/';
require $root.'/tools/connection/PublicAddress.php';
class_alias(\Nicode\Connection\PublicAddress::class,'Nicode\\Component\\NicodeWebMonitor\\Administrator\\Connection\\PublicAddress');
require $service.'ReleaseReference.php';require $service.'ReferenceHttp.php';
$catalog=[];$now=time();
foreach(['php','joomla'] as $product){
    $body=(new \Nicode\Component\NicodeWebMonitor\Administrator\Service\ReferenceHttp())->fetch($product);
    $catalog[$product]=\Nicode\Component\NicodeWebMonitor\Administrator\Service\ReleaseReference::parse($product,$body,$now);
}
file_put_contents($service.'release-catalog.json',json_encode($catalog,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo json_encode(['refreshed'=>array_keys($catalog),'checked_at'=>gmdate('c',$now)])."\n";
