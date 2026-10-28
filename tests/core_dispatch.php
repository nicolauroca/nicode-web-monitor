<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Disposable subprocess contract fixture. */
foreach (['MaintenanceGate','CoreOperation','CoreDispatch'] as $name) { require __DIR__.'/../tools/'.$name.'.php'; }
use Nicode\Maintenance\MaintenanceGate;
use Nicode\Maintenance\CoreDispatch;
$base=$argv[1]; $mode=$argv[2]; $id='dispatch-test-259';
$gate=new MaintenanceGate($base.'/gate',$base.'/site');
$d=new CoreDispatch($base.'/updates',$gate,$base.'/site');
$inspect=fn()=>json_decode(file_get_contents($base.'/state.json'),true,16,JSON_THROW_ON_ERROR);
try {
    $r=match($mode) {
        'discovery'=>$gate->currentOwner()===null ? ['status'=>'idle'] :
            ($gate->currentOwner()==='core:'.$id ? $d->handle($id,false,false) : ['status'=>'blocked']),
        'queue'=>$d->handle($id,true,true),
        'online'=>$d->handle($id,true,false),
        'status'=>$d->handle($id,false,false),
        'execute','crash'=>$d->execute($id,$inspect,function($archive,$hash,$snapshot)use($base,$inspect,$mode) {
            file_put_contents($base.'/calls','1',FILE_APPEND);
            $s=$inspect(); foreach(['runtime_version','manifest_version','database_version'] as $k){$s[$k]='6.1.4';}
            file_put_contents($base.'/state.json',json_encode($s));
            if($mode==='crash'){exit(73);} return true;
        }),
        'reconcile'=>$d->reconcile($id,$inspect),
        'backup'=>$gate->run('backup_site:backup-test-259',fn()=>['status'=>'files']),
        'backup-complete'=>$gate->run('backup_site:backup-test-259',fn()=>['status'=>'complete']),
        'hold'=>$gate->run('backup_site:backup-test-259',function()use($base){
            file_put_contents($base.'/locked','1');fgets(STDIN);return ['status'=>'complete'];
        }),
        'extension'=>$gate->run('extension:plugin-test-259',fn()=>['status'=>'updated']),
        default=>throw new RuntimeException('Unknown mode'),
    };
    echo json_encode($r,JSON_THROW_ON_ERROR);
} catch (Throwable) { echo '{"blocked":true}';exit(4); }
