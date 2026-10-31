<?php
/** @package Nicode.joomla.webmonitor. Compound receipts reject false completion and preserve partial effects. */
require __DIR__.'/../tools/PackageState.php';
require __DIR__.'/../tools/PackageReceipt.php';
require __DIR__.'/../extensions/com_nicodewebmonitor/administrator/src/Service/UpdateClient.php';
class_alias(Nicode\Maintenance\PackageReceipt::class,'Nicode\Component\NicodeWebMonitor\Administrator\Service\PackageReceipt');
use Nicode\Maintenance\PackageReceipt;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\UpdateClient;
$checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function row($id,$type,$element,$package,$version){return ['id'=>$id,'identity'=>['type'=>$type,'element'=>$element,'folder'=>'','client_id'=>0],'package_id'=>$package,'protected'=>false,'version'=>$version];}
$before=['parents'=>[row(10,'package','pkg_qa',0,'1.0')],'children'=>[row(11,'library','qa',10,'1.0')]];
$after=['parents'=>[row(10,'package','pkg_qa',0,'1.1')],'children'=>[row(12,'library','qa',10,'1.1')]];
$raw=['status'=>'updated','plan'=>['before'=>$before,'targets'=>[['identity'=>$before['children'][0]['identity'],'version'=>'1.1']],
    'to'=>'1.1','package_sha256'=>str_repeat('a',64)],'observed'=>$after,'private_path'=>'never return'];
$valid=PackageReceipt::project($raw);
check(!isset($valid['private_path']) && $valid['compound']['before']===$before && $valid['compound']['observed']===$after,'exact identities and replacement IDs without internal fields');
$client=new UpdateClient();$method=new ReflectionMethod($client,'receipt');
$site='12345678-1234-4123-8123-123456789012';$op='package-contract';
$wire=['site_id'=>$site,'operation'=>$op,'scope'=>'extension_update']+$valid;
check($method->invoke($client,$wire,$site,$op,false,200)===$wire,'central client accepts complete compound receipt');
foreach (['wrong-parent','orphan','old-version','duplicate','missing-child','summary','oversize','nested','html'] as $case) {
    $bad=$wire;
    switch($case){
        case 'wrong-parent':$bad['compound']['observed']['parents'][0]['identity']['element']='pkg_other';break;
        case 'orphan':$bad['compound']['observed']['children'][0]['package_id']=0;break;
        case 'old-version':$bad['compound']['observed']['children'][0]['version']='1.0';break;
        case 'duplicate':$bad['compound']['observed']['children'][]=$after['children'][0];break;
        case 'missing-child':$bad['compound']['observed']['children']=[];break;
        case 'summary':$bad['plan']['extension_id']=99;break;
        case 'oversize':$bad['compound']['targets']=array_fill(0,33,$raw['plan']['targets'][0]);break;
        case 'nested':$bad['compound']['targets'][0]['identity']['type']='package';break;
        case 'html':$bad['compound']['before']['children'][0]['identity']['element']='<script>';break;
    }
    check($method->invoke($client,$bad,$site,$op,false,200)===null,'rejects '.$case);
}
$partial=$raw;$partial['status']='uncertain';$partial['observed']['children'][0]['package_id']=0;
$projected=PackageReceipt::project($partial);
check($projected['status']==='uncertain' && $projected['compound']['observed']['children'][0]['package_id']===0,'partial orphan observations preserved without claiming completion');
unset($partial['observed']);check(PackageReceipt::project($partial)['compound']['observed']===null,'interruption before observation remains explicit');
$long=$raw;$version='1.1+'.str_repeat('a',76);$long['plan']['to']=$version;
$long['plan']['targets'][0]['version']=$version;
$long['observed']['parents'][0]['version']=$version;$long['observed']['children'][0]['version']=$version;
$longWire=['site_id'=>$site,'operation'=>$op,'scope'=>'extension_update']+PackageReceipt::project($long);
check($method->invoke($client,$longWire,$site,$op,false,200)===$longWire,'80-character prepared versions survive transport');
echo json_encode(['passed'=>count($checks),'checks'=>$checks]);
