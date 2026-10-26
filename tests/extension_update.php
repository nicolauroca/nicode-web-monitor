<?php
/** Package: Nicode.joomla.webmonitor. Journal failure and concurrency checks. */
require __DIR__.'/../tools/ExtensionUpdate.php';
use Nicode\Maintenance\ExtensionUpdate;
$root=sys_get_temp_dir().'/nwm-update-'.bin2hex(random_bytes(8));mkdir($root,0700);
$n=0;
function check($ok){global $n;if(!$ok){throw new RuntimeException('Check failed');}$n++;}
$plan=['extension_id'=>1,'from'=>'1.0.0','to'=>'1.1.0','package_sha256'=>str_repeat('a',64)];
$inspect=fn()=>['extension_id'=>1,'version'=>'1.0.0'];$calls=0;
$install=function()use(&$calls){$calls++;return false;};$core=new ExtensionUpdate($root);
try {
    $lock=fopen($root.'/update.lock','c');flock($lock,LOCK_EX);
    try{$core->run('update-lock',$plan,true,true,$inspect,$install);check(false);}catch(RuntimeException $e){check($e->getMessage()==='Another update is running');}
    flock($lock,LOCK_UN);fclose($lock);check($calls===0);
    check($core->run('update-false',$plan,true,true,$inspect,$install)['status']==='uncertain');
    check($core->run('update-false',$plan,true,true,$inspect,$install)['status']==='uncertain' && $calls===1);
    // Provider says success but installed metadata did not change: never claim success.
    check($core->run('update-wrong',$plan,true,true,$inspect,fn()=>true)['status']==='uncertain');
    $bad=$plan;$bad['to']='0.9.0';
    try{$core->run('update-down',$bad,true,true,$inspect,$install);check(false);}catch(RuntimeException $e){check($e->getMessage()==='Invalid update plan');}
    mkdir($root.'/update-empty');file_put_contents($root.'/update-empty/started.json','{');
    try{$core->run('update-empty',$plan,true,true,$inspect,$install);check(false);}catch(JsonException $e){check($calls===1);}
    // A truncated terminal receipt must fail closed, never call provider again.
    file_put_contents($root.'/update-false/completed.json','{');
    try{$core->run('update-false',$plan,true,true,$inspect,$install);check(false);}catch(JsonException $e){check($calls===1);}
    echo json_encode(['passed'=>$n])."\n";
} finally {
    foreach(glob($root.'/*') as $p){if(is_dir($p)){foreach(glob($p.'/*') as $f){unlink($f);}rmdir($p);}else{unlink($p);}}
    rmdir($root);
}
