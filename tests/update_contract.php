<?php
/** @package Nicode.joomla.webmonitor. Fail-closed transport contract and no implicit effects. */
require __DIR__.'/../extensions/plg_system_nicodewebmonitor/src/Transport/UpdateEndpoint.php';
require __DIR__.'/../extensions/com_nicodewebmonitor/administrator/src/Service/InventoryClient.php';
require __DIR__.'/../extensions/com_nicodewebmonitor/administrator/src/Service/UpdateClient.php';
use Nicode\Plugin\System\NicodeWebMonitor\Transport\UpdateEndpoint;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\UpdateClient;
$checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
$token=str_repeat('1',64);$site='12345678-1234-4123-8123-123456789012';
$endpoint=new UpdateEndpoint($site,hash('sha256',$token),[]);
$server=['HTTPS'=>'on','REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token];
$r=['site_id'=>$site,'operation'=>'contract-update','action'=>'update','confirmed'=>true];$calls=0;
$provider=function()use(&$calls){$calls++;throw new RuntimeException('private internal detail');};
foreach([
    [array_replace($server,['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https']),[],json_encode($r),403,'untrusted proxy cannot enable TLS'],
    [array_replace($server,['REQUEST_METHOD'=>'DELETE']),[],json_encode($r),405,'unsupported method denied'],
    [array_replace($server,['CONTENT_TYPE'=>'text/plain']),[],json_encode($r),415,'JSON content type required'],
    [$server,['operation'=>'other'],json_encode($r),400,'query and body ambiguity denied'],
    [$server,[],json_encode(array_replace($r,['operation'=>'../outside'])),400,'operation path denied'],
    [$server,[],json_encode(array_replace($r,['confirmed'=>'true'])),400,'confirmation must be boolean'],
    [array_replace($server,['REQUEST_METHOD'=>'GET']),$r,'x',400,'GET body denied'],
] as [$s,$q,$body,$expected,$name])check($endpoint->handle($s,$q,$body,$provider)[0]===$expected,$name);
check($calls===0,'invalid requests never invoke provider');
$response=$endpoint->handle($server,[],json_encode($r),$provider);
check($response===[409,['error'=>'update_not_confirmed']] && $calls===1,'provider exception is sanitized');
$client=new UpdateClient();
$result=$client->request('https://example.invalid','127.0.0.1',$site,$token,'contract-update',true,true);
check($result['error']==='invalid_enrollment' && !$result['reconcile_required'],'production client refuses private address before sending');
$receipt=new ReflectionMethod(UpdateClient::class,'receipt');
$valid=['site_id'=>$site,'operation'=>'contract-update','scope'=>'extension_update','status'=>'updated',
    'plan'=>['extension_id'=>7,'from'=>'1.0','to'=>'1.1','package_sha256'=>str_repeat('a',64)]];
check($receipt->invoke($client,$valid,$site,'contract-update',true,200)===$valid,'valid receipt projected');
foreach([
    array_replace($valid,['site_id'=>'wrong']),array_replace($valid,['operation'=>'different']),
    array_replace($valid,['scope'=>'files_and_database']),array_replace($valid,['status'=>'complete']),
    array_replace($valid,['plan'=>array_replace($valid['plan'],['to'=>'0.9'])]),
    array_replace($valid,['plan'=>array_replace($valid['plan'],['package_sha256'=>'invalid'])]),
] as $bad)check($receipt->invoke($client,$bad,$site,'contract-update',true,200)===null,'inconsistent receipt denied');
check($receipt->invoke($client,$valid,$site,'contract-update',true,202)===null,'HTTP status must match receipt');
echo json_encode(['passed'=>count($checks),'checks'=>$checks])."\n";
