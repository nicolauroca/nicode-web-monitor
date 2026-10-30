<?php
/** @package Nicode.joomla.webmonitor. Journal crash and false-completion contracts. */
require __DIR__.'/../tools/PackageState.php';
require __DIR__.'/../tools/PackageOperation.php';
use Nicode\Maintenance\PackageOperation;
$identity=['type'=>'library','element'=>'fixture','folder'=>'','client_id'=>0];
$before=['parents'=>[['id'=>10,'identity'=>['type'=>'package','element'=>'pkg_fixture','folder'=>'','client_id'=>0],'package_id'=>0,'protected'=>false,'version'=>'1.0.0']],
    'children'=>[['id'=>11,'identity'=>$identity,'package_id'=>10,'protected'=>false,'version'=>'1.0.0']]];
$plan=['before'=>$before,'to'=>'1.1.0','targets'=>[['identity'=>$identity,'version'=>'1.1.0']],'package_sha256'=>str_repeat('a',64)];
if(($argv[1]??'')==='--crash-child'){
    (new PackageOperation($argv[2]))->run('crash-child',$plan,true,true,fn()=>$before,function($digest)use($argv){file_put_contents($argv[2].'/effect.txt','one installation');exit(73);});
    exit(99);
}
$root=sys_get_temp_dir().'/nwm-package-'.bin2hex(random_bytes(8));mkdir($root,0700);
$checks=[];
function check($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks[]=$label;}
$journal=new PackageOperation($root);
try{
    foreach([[false,true],[true,false]] as [$auth,$confirm]){
        try{$journal->run('denied-operation',$plan,$auth,$confirm,fn()=>$before,fn()=>true);throw new LogicException('denial missing');}
        catch(RuntimeException $e){check($e->getMessage()==='Permission and confirmation required','authorization enforced');}
    }
    check(!is_dir($root.'/denied-operation'),'no denied reservation');
    foreach(['unchanged-child','orphan','missing-child','extra-child','exception','read-failure'] as $mode){
        $current=$before;$calls=0;
        $inspect=function()use(&$current){if($current===null)throw new RuntimeException('private SQL not for receipt');return $current;};
        $install=function()use(&$current,&$calls,$mode){
            $calls++;$current['parents'][0]['version']='1.1.0';
            if($mode!=='unchanged-child')$current['children'][0]['version']='1.1.0';
            if($mode==='orphan')$current['children'][0]['package_id']=0;
            if($mode==='missing-child')$current['children']=[];
            if($mode==='extra-child')$current['children'][]=$current['children'][0];
            if($mode==='read-failure')$current=null;
            if($mode==='exception')throw new RuntimeException('private installer detail');
            return true;
        };
        $id='case-'.$mode;$r=$journal->run($id,$plan,true,true,$inspect,$install);
        check($r['status']==='uncertain','false completion rejected '.$mode);
        check($journal->run($id,$plan,true,true,$inspect,$install)===$r && $calls===1,'no retry '.$mode);
        check(!str_contains(json_encode($r),'private'),'exception detail omitted '.$mode);
    }
    $p=proc_open([PHP_BINARY,__FILE__,'--crash-child',$root],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    foreach($pipes as $pipe){stream_get_contents($pipe);fclose($pipe);}
    check(proc_close($p)===73,'process stopped after effects');
    check(file_get_contents($root.'/effect.txt')==='one installation','effect exists before lost completion');
    $journal=new PackageOperation($root);$calls=0;
    $r=$journal->run('crash-child',$plan,true,true,fn()=>$before,function()use(&$calls){$calls++;return true;});
    check($r['status']==='uncertain' && $calls===0,'restart does not reinstall');
    $bad=$plan;$bad['to']='1.2.0';
    try{$journal->run('crash-child',$bad,true,true,fn()=>$before,fn()=>true);throw new LogicException('conflict accepted');}
    catch(RuntimeException $e){check($e->getMessage()==='Operation conflict','reused ID conflict rejected');}
    foreach(['missing','duplicate','downgrade'] as $mode){
        $bad=$plan;
        if($mode==='missing')$bad['before']['children']=[];
        if($mode==='duplicate')$bad['targets'][]=$bad['targets'][0];
        if($mode==='downgrade')$bad['targets'][0]['version']='0.9.0';
        try{$journal->run('bad-'.$mode,$bad,true,true,fn()=>$bad['before'],fn()=>true);throw new LogicException('bad plan accepted');}
        catch(RuntimeException $e){check(!is_dir($root.'/bad-'.$mode),'bad baseline rejected '.$mode);}
    }
    echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($root);
}
