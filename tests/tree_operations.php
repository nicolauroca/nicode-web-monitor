<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tools/TreeOperations.php';
use Nicode\Maintenance\TreeOperations;
$root = $argv[1]; $site = '12345678-1234-4123-8123-123456789abc';
$service = new TreeOperations($root, $site);
$request = ['site_id'=>$site, 'operation'=>'journal-test', 'action'=>'step', 'sequence'=>0, 'confirmed'=>true];
$post = ['REQUEST_METHOD'=>'POST']; $calls = 0; $checks = [];
function check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[]=$name; }
$provider = function ($id) use (&$calls) { $calls++; return ['operation'=>$id,'status'=>'manifest','cursor'=>0,'manifest_sha256'=>null]; };
check($service->handle($post, array_replace($request, ['confirmed'=>false]), $provider)[0]===400 && $calls===0, 'confirmation_before_provider');
check($service->handle($post, array_replace($request, ['site_id'=>'wrong']), $provider)[0]===409 && $calls===0, 'site_binding_before_provider');
$first = $service->handle($post, $request, $provider);
check($first[0]===200 && $calls===1, 'journal_persisted');
$lock = fopen($root.'/journal-test/lock','c+b'); flock($lock, LOCK_EX);
check($service->handle($post, array_replace($request,['sequence'=>1]), $provider)[0]===409 && $calls===1, 'concurrent_call_never_runs_provider');
flock($lock, LOCK_UN); fclose($lock);
$newSite = '12345678-1234-4123-8123-123456789abd';
check((new TreeOperations($root, $newSite))->handle($post,array_replace($request,['site_id'=>$newSite]),$provider)[0]===409 && $calls===1, 'clone_cannot_reuse_journal');
check($service->handle($post,$request,$provider)===$first && $calls===1,'duplicate_receipt');
$second = $service->handle($post,array_replace($request,['sequence'=>1]),$provider);
check($second[0]===200 && $calls===2,'next_sequence_advances_once');
check($service->handle($post,$request,$provider)[0]===409 && $calls===2,'stale_sequence_rejected');
file_put_contents($root.'/journal-test/state.json','{');
check($service->handle($post,array_replace($request,['sequence'=>2]),$provider)[0]===202 && $calls===2,'corrupt_journal_not_replayed');
mkdir($root.'/orphan-init'); file_put_contents($root.'/orphan-init/bound','');
check($service->handle($post,array_replace($request,['operation'=>'orphan-init']),$provider)[0]===202 && $calls===2,'orphan_initialization_uncertain');
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT);
