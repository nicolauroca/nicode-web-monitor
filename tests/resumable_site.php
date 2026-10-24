<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor */
require dirname(__DIR__) . '/tools/ResumableSite.php';
require dirname(__DIR__) . '/tools/ResumableTree.php';
require dirname(__DIR__) . '/tools/TreeOperations.php';
use Nicode\Maintenance\ResumableSite;
use Nicode\Maintenance\ResumableTree;
use Nicode\Maintenance\TreeManifest;
use Nicode\Maintenance\TreeOperations;
$root = $argv[1]; $checks = []; $calls = 0;
function check(bool $ok, string $name): void {
    global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name;
}
foreach (['source','sites','trees','verify','journal'] as $name) { mkdir($root . '/' . $name); }
file_put_contents($root . '/source/data', str_repeat('a', 1048576) . 'tail');
$files = fn ($id) => (new ResumableTree($root . '/trees'))->step($root . '/source', $id, true, 64);
$verify = fn ($id) => (new TreeManifest($root . '/verify'))->step($root . '/source', $id, true, 64);
$database = function ($id) use (&$calls) {
    $calls++; return ['operation'=>$id, 'status'=>'complete', 'manifest_sha256'=>hash('sha256','database-fixture')];
};
$site = '12345678-1234-4123-8123-123456789abc';
$journal = new TreeOperations($root . '/journal', $site, 'files_and_database');
$post = ['REQUEST_METHOD'=>'POST'];
$request = ['site_id'=>$site, 'operation'=>'joint-test', 'action'=>'step', 'sequence'=>0, 'confirmed'=>true];
$provider = fn ($id) => (new ResumableSite($root . '/sites'))->step($id, $files, $database, $verify);
$phases = [];
for ($seq=0; $seq<200; $seq++) {
    $request['sequence']=$seq;
    $r=$journal->handle($post,$request,$provider);
    check($r[0]===200, 'step_'.$seq);
    $phases[$r[1]['phase']]=true;
    $count=$calls;
    check($journal->handle($post,$request,$provider)===$r && $calls===$count, 'duplicate_'.$seq);
    if ($r[1]['status']==='complete') { break; }
}
check($r[1]['status']==='complete' && $calls===1, 'joint_complete_once');
check(isset($phases['database'],$phases['verify']) && !$r[1]['restoration_verified'], 'phases_and_no_false_restore');
$state=json_decode(file_get_contents($root.'/sites/joint-test/state.json'),true);
check($r[1]['manifest_sha256']===hash('sha256',json_encode(['format'=>'nwm-site-chunks-1','files'=>$state['files_sha256'],'database'=>$state['database_sha256']],JSON_THROW_ON_ERROR)), 'receipt_binds_both_manifests');
$count=$calls;
check($provider('joint-test')['status']==='complete' && $calls===$count, 'completed_provider_no_recapture');
// File mutation during the database phase must prevent joint completion.
$mutate=function ($id) use ($database,$root) { file_put_contents($root.'/source/data','changed'); return $database($id); };
$bad=fn ($id) => (new ResumableSite($root.'/sites'))->step($id,$files,$mutate,$verify);
$request['operation']='mutated-site';
for ($seq=0;$seq<200;$seq++) {
    $request['sequence']=$seq; $r=$journal->handle($post,$request,$bad);
    if ($r[0]===202) { break; }
}
check($r[0]===202 && $r[1]['status']==='uncertain', 'changed_files_never_complete');
$count=$calls;
check($journal->handle($post,$request,$provider)===$r && $calls===$count, 'uncertainty_never_replayed');
// Bad child identities/digests cannot bind a completed joint receipt.
$invalid=fn ($id) => ['operation'=>'other-site','status'=>'complete','manifest_sha256'=>str_repeat('a',64)];
$request['operation']='bad-receipt'; $request['sequence']=0;
check($journal->handle($post,$request,fn ($id)=>(new ResumableSite($root.'/sites'))->step($id,$invalid,$database,$verify))[0]===202, 'wrong_child_identity_refused');
$invalid=fn ($id) => ['operation'=>$id,'status'=>'complete','manifest_sha256'=>'invalid'];
$request['operation']='bad-digest';
check($journal->handle($post,$request,fn ($id)=>(new ResumableSite($root.'/sites'))->step($id,$invalid,$database,$verify))[0]===202, 'bad_digest_refused');
mkdir($root.'/sites/orphan-site'); touch($root.'/sites/orphan-site/bound');
$request['operation']='orphan-site';
check($journal->handle($post,$request,$provider)[0]===202, 'orphan_checkpoint_not_reinitialized');
// Corrupt a checkpoint after a real database-phase transition.
$request['operation']='corrupt-site';
for ($seq=0;$seq<100;$seq++) {
    $request['sequence']=$seq; $r=$journal->handle($post,$request,$provider);
    if (($r[1]['phase']??null)==='database') { break; }
}
file_put_contents($root.'/sites/corrupt-site/state.json','{');
$request['sequence']++; $count=$calls;
check($journal->handle($post,$request,$provider)[0]===202 && $calls===$count,'corrupt_checkpoint_no_database_effect');
// Simulate the process dying after a child checkpoint but before its HTTP receipt.
$request['operation']='lost-receipt'; $request['sequence']=0;
$crash=function ($id) use ($provider) { $provider($id); throw new RuntimeException('Receipt lost'); };
check($journal->handle($post,$request,$crash)[0]===202, 'lost_receipt_uncertain');
$before=file_get_contents($root.'/sites/lost-receipt/state.json');
check($journal->handle($post,$request,$provider)[0]===202
    && file_get_contents($root.'/sites/lost-receipt/state.json')===$before, 'lost_receipt_no_second_effect');
$get=['site_id'=>$site,'operation'=>'lost-receipt','action'=>'status'];
check($journal->handle(['REQUEST_METHOD'=>'GET'],$get,$provider)[1]['status']==='uncertain', 'get_preserves_uncertainty');
$wrongScope=new TreeOperations($root.'/journal',$site,'files_only');
check($wrongScope->handle($post,$request,$provider)[0]===409, 'joint_journal_scope_bound');
$request['operation']='unarmed-site';
$unarmed=function ($id) { throw new RuntimeException('Expired permission'); };
check($journal->handle($post,$request,$unarmed)[0]===202
    && !is_dir($root.'/sites/unarmed-site'), 'unarmed_has_no_child_effect');
check($journal->handle($post,$request,$provider)[0]===202
    && !is_dir($root.'/sites/unarmed-site'), 'later_permission_cannot_replay_uncertainty');
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT);
