<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Archive checks without Joomla or installation. */
require __DIR__.'/../extensions/plg_system_nicodewebmonitor/src/Maintenance/ExtensionIdentity.php';
foreach (['PackagePreflight','PackageState','PackageOperation'] as $class) require __DIR__.'/../tools/'.$class.'.php';
use Nicode\Maintenance\PackagePreflight;
use Nicode\Maintenance\PackageOperation;
$root=sys_get_temp_dir().'/nwm-preflight-tests-'.bin2hex(random_bytes(8)); mkdir($root,0700);
$checks=[];
function check(bool $ok,string $name):void { global $checks; if(!$ok)throw new RuntimeException($name);$checks[]=$name; }
function zipbytes(array $files):string {
    global $root; $p=$root.'/'.bin2hex(random_bytes(6)).'.zip';$z=new ZipArchive();$z->open($p,ZipArchive::CREATE|ZipArchive::EXCL);
    foreach($files as $name=>$bytes)$z->addFromString($name,$bytes);$z->close();return $p;
}
function fixture(string $mutation=''):string {
    $m='<extension type="library"><name>sample</name><libraryname>sample</libraryname><version>1.1.0</version><files><filename>index.php</filename></files></extension>';
    $files=['sample.xml'=>$m,'index.php'=>'<?php throw new RuntimeException("must never execute");'];
    if($mutation==='child-version')$files['sample.xml']=str_replace('<version>1.1.0</version>','',$m);
    if($mutation==='nested')$files['sample.xml']=str_replace('type="library"','type="package"',$m);
    if($mutation==='entity')$files['sample.xml']='<!DOCTYPE extension [<!ENTITY x SYSTEM "file:///not-read">]>'.$m;
    if($mutation==='ambiguous')$files['second.xml']=$m;
    foreach(['traversal'=>'../escape','absolute'=>'/escape','backslash'=>'a\\b','reserved'=>'NUL.txt','trailing'=>'bad.','collision'=>'dir/file','case'=>'SAMPLE.xml'] as $case=>$path) {
        if($mutation===$case){$files[$path]='x';if($case==='collision')$files['dir']='x';}
    }
    $child=zipbytes($files);
    $decl='<file type="library" id="sample">child.zip</file>';
    if($mutation==='identity')$decl=str_replace('id="sample"','id="other"',$decl);
    if($mutation==='client')$decl=str_replace('id="sample"','id="sample" client="administrator"',$decl);
    if($mutation==='group')$decl=str_replace('id="sample"','id="sample" group="system"',$decl);
    if($mutation==='duplicate-path')$decl.=$decl;
    if($mutation==='duplicate-identity')$decl.=str_replace('child.zip','second.zip',$decl);
    $parent='<extension type="package"><packagename>sample</packagename><version>1.1.0</version><files>'.$decl.'</files></extension>';
    if($mutation==='parent-version')$parent=str_replace('<version>1.1.0</version>','<version>invalid</version>',$parent);
    $outer=['pkg_sample.xml'=>$parent,'child.zip'=>file_get_contents($child)];
    if($mutation==='missing')unset($outer['child.zip']);
    if($mutation==='undeclared' || $mutation==='duplicate-identity')$outer['second.zip']=file_get_contents($child);
    if($mutation==='outer-traversal')$outer['../bad']='x';
    return zipbytes($outer);
}
try {
    $p=fixture();$report=PackagePreflight::inspect($p,hash_file('sha256',$p));
    check($report['element']==='pkg_sample' && count($report['targets'])===1,'parent and complete child version read');
    check($report['targets'][0]['version']==='1.1.0' && $report['children'][0]['sha256']!=='','child content digest retained');
    foreach(['child-version','nested','entity','ambiguous','traversal','absolute','backslash','reserved','trailing','collision','case','identity','client','group','duplicate-path','duplicate-identity','parent-version','missing','undeclared','outer-traversal'] as $case) {
        $bad=fixture($case);$rejected=false;
        try{PackagePreflight::inspect($bad,hash_file('sha256',$bad));}catch(RuntimeException){$rejected=true;}
        check($rejected,'reject '.$case);
    }
    try{PackagePreflight::inspect($p,str_repeat('0',64));throw new LogicException('accepted digest');}catch(RuntimeException){check(true,'digest mismatch rejected');}
    $before=['parents'=>[['id'=>1,'identity'=>['type'=>'package','element'=>'pkg_sample','folder'=>'','client_id'=>0],'version'=>'1.0.0','package_id'=>0,'protected'=>false]],
        'children'=>[['id'=>2,'identity'=>$report['targets'][0]['identity'],'version'=>'1.0.0','package_id'=>1,'protected'=>false]]];
    $plan=PackagePreflight::plan($report,$before);
    check($plan['package_sha256']===hash_file('sha256',$p),'plan bound to inspected bytes');
    foreach(['orphan','extra','missing','downgrade','wrong-parent','protected'] as $case){
        $b=$before;
        if($case==='orphan')$b['children'][0]['package_id']=0;
        if($case==='extra')$b['children'][]=$b['children'][0];
        if($case==='missing')$b['children']=[];
        if($case==='downgrade')$b['children'][0]['version']='9.0.0';
        if($case==='wrong-parent')$b['parents'][0]['identity']['element']='pkg_other';
        if($case==='protected')$b['children'][0]['protected']=true;
        $rejected=false;try{PackagePreflight::plan($report,$b);}catch(RuntimeException){$rejected=true;}check($rejected,'baseline reject '.$case);
    }
    mkdir($root.'/journal');$state=$before;$calls=0;
    $inspect=function()use(&$state){return $state;};
    $install=function($hash)use(&$state,&$calls,$plan){$calls++;check($hash===$plan['package_sha256'],'installer receives verified hash');$state['parents'][0]['version']='1.1.0';$state['children'][0]['version']='1.1.0';$state['children'][0]['id']=3;return true;};
    $op=new PackageOperation($root.'/journal');$receipt=$op->run('preflight-success',$plan,true,true,$inspect,$install);
    check($receipt['status']==='updated' && $receipt['observed']['children'][0]['id']===3,'inspected plan consumed by journal with replacement ID');
    check($op->run('preflight-success',$plan,true,true,$inspect,$install)===$receipt && $calls===1,'inspected plan replay never reinstalls');
    echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($root);
}
