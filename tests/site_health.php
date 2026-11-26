<?php
/** Deterministic upstream facts and synthetic shared inventory; no network or Joomla writes. */
require __DIR__.'/inventory_fixture.php';
require_once __DIR__.'/../extensions/com_nicodewebmonitor/administrator/src/Service/InventoryStore.php';
use Nicode\Component\NicodeWebMonitor\Administrator\Service\ReleaseReference;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteHealth;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventoryStore;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventorySchema;
$checks=[];function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;}
function rejects($fn,$label){try{$fn();}catch(Throwable){check(true,$label);return;}throw new RuntimeException($label);}
$now=strtotime('2026-11-26 12:00:00 UTC');
$body=json_encode([
 ['branch'=>'8.5','latest'=>'8.5.13','state'=>'stable','initial_release'=>'2025-11-20T00:00:00+00:00','active_support_end'=>'2027-12-31T00:00:00+00:00','security_support_end'=>'2029-12-31T00:00:00+00:00'],
 ['branch'=>'8.6','latest'=>'8.6.0','state'=>'stable','initial_release'=>'2026-11-19T00:00:00+00:00','active_support_end'=>'2028-12-31T00:00:00+00:00','security_support_end'=>'2030-12-31T00:00:00+00:00'],
 ['branch'=>'8.3','latest'=>'8.3.37','state'=>'security','initial_release'=>'2023-11-23T00:00:00+00:00','active_support_end'=>'2025-12-31T00:00:00+00:00','security_support_end'=>'2027-12-31T00:00:00+00:00'],
 ['branch'=>'8.1','latest'=>'8.1.34','state'=>'eol','initial_release'=>'2021-11-25T00:00:00+00:00','active_support_end'=>'2023-11-25T00:00:00+00:00','security_support_end'=>'2025-12-31T00:00:00+00:00'],
 ['branch'=>'8.7','latest'=>'8.7.0','state'=>'stable','initial_release'=>'2027-11-19T00:00:00+00:00','active_support_end'=>'2029-12-31T00:00:00+00:00','security_support_end'=>'2031-12-31T00:00:00+00:00']]);
$php=ReleaseReference::parse('php',$body,$now);
check(!isset($php['branches']['8.7']),'future releases are not offered as stable');
check(SiteHealth::software('php','8.5.13',$php,$now)['tone']==='success','supported PHP branch stays green when a newer branch exists');
check(SiteHealth::software('php','8.5.8',$php,$now)['label']==='Actualizar a 8.5.13','same-branch patch update is actionable');
check(SiteHealth::software('php','8.3.37',$php,$now)['label']==='Solo seguridad','security-only support is distinct from patch currency');
check(SiteHealth::software('php','8.1.34',$php,$now)['tone']==='danger','unsupported PHP is a priority');
foreach(['8.5.14','8.5.13-rc1','8.5.13-ubuntu','unexpected',null] as $version)check(SiteHealth::software('php',$version,$php,$now)['tone']==='neutral','ahead or custom version remains unevaluated');
check(SiteHealth::software('php','8.5.13',$php,$now+604801)['tone']==='neutral','expired reference never certifies currency');
$future=$php;$future['checked_at']=$now+301;check(SiteHealth::software('php','8.5.13',$future,$now)['tone']==='neutral','future reference timestamp is rejected');
$boundary=$php;$boundary['branches']['8.5']['active_until']='2026-11-26';$boundary['branches']['8.5']['security_until']='2026-11-26';
check(SiteHealth::software('php','8.5.13',$boundary,$now)['tone']==='success','support includes the whole final calendar day');
$boundary['checked_at']=$now+86400;check(SiteHealth::software('php','8.5.13',$boundary,$now+86400)['tone']==='danger','end of support changes the following day');
$html='<table><tbody><tr><th>Current Release</th><td>6.2.1</td></tr><tr><th>Release Date of Series</th><td>14 October 2025</td></tr><tr><th>End of regular bugfix Support for 6.x</th><td>17 October 2028</td></tr><tr><th>End of security fix only Support for 6.x</th><td>16 October 2029</td></tr></tbody></table>';
$joomla=ReleaseReference::parse('joomla',$html,$now);
check(SiteHealth::software('joomla','6.2.1',$joomla,$now)['tone']==='success','current Joomla is recognized');
check(SiteHealth::software('joomla','6.1.4',$joomla,$now)['tone']==='warning','older minor Joomla is behind its supported major');
rejects(fn()=>ReleaseReference::parse('joomla',str_replace('17 October 2028','31 February 2028',$html),$now),'broken upstream dates fail closed');
rejects(fn()=>ReleaseReference::parse('php','<html>unavailable</html>',$now),'HTML cannot be accepted as PHP JSON');
rejects(fn()=>ReleaseReference::parse('joomla','<table><td>7.0.0-rc1</td></table>',$now),'incomplete upstream markup is not a healthy reference');
$inventory=nwmInventoryFixture();$inventory['sections']['runtime']=['status'=>'available','data'=>['php_version'=>'8.5.13']];
$row=static fn($id,$current,$offered,$type='plugin',$element='fixture')=>['extension_id'=>$id,'type'=>$type,'element'=>$element,'name'=>'Fixture','current_version'=>$current,'version'=>$offered];
$inventory['sections']['updates']=['status'=>'available','data'=>['total'=>6,'items'=>[$row(1,'1.0.0','1.1.0'),$row(1,'1.0.0','1.2.0'),$row(2,'2.0.0','1.0.0'),$row(3,'1.0.0','1.0.0'),$row(4,'6.1.4','6.2.1','file','joomla'),$row(5,null,'1.0.0')],'oldest_checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'last_checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now)]];
$clean=InventorySchema::normalise($inventory,['remote_https','local_cli']);$summary=InventoryStore::summarise($clean);
check($summary['updates']['pending_extensions']===1&&$summary['updates']['pending_core']===1,'duplicate versions and already installed versions do not inflate pending count');
check($summary['updates']['unknown_versions']===1,'unknown installed versions stay explicit');
$site=['inventory_summary'=>$summary,'check_state'=>'recent_complete'];$catalog=['php'=>$php,'joomla'=>$joomla];$health=SiteHealth::assess($site,$catalog,$now);
check($health['indicators']['security']['tone']==='neutral'&&$health['indicators']['storage']['label']==='No compartido','denied areas are not green');
check(SiteHealth::matches($health,'updates')&&SiteHealth::matches($health,'unknown'),'priority filters combine actual findings and missing data');
$site['check_state']='stale';$stale=SiteHealth::assess($site,$catalog,$now);check($stale['indicators']['runtime']['tone']==='neutral','old inventory does not retain a green version indicator');
$summary['coverage']['storage']='available';$summary['storage']=['free_bytes'=>10485760,'total_bytes'=>1073741824];$site=['inventory_summary'=>$summary,'check_state'=>'recent_complete'];
check(SiteHealth::assess($site,$catalog,$now)['indicators']['storage']['tone']==='danger','low filesystem space is prioritized');
$summary['coverage']['updates']='denied';$summary['updates']=[];$site['inventory_summary']=$summary;
check(!SiteHealth::matches(SiteHealth::assess($site,$catalog,$now),'updates'),'denial removes pending-update findings');
$summary['coverage']['scheduler']='available';$summary['scheduler']=['locked'=>0,'failed_enabled'=>0,'unobserved_enabled'=>0];$site['inventory_summary']=$summary;
check(SiteHealth::assess($site,$catalog,$now)['indicators']['scheduler']['tone']==='success','historical failure totals do not imply a current scheduler error');
$summary['scheduler']['unobserved_enabled']=1;$site['inventory_summary']=$summary;
check(SiteHealth::assess($site,$catalog,$now)['indicators']['scheduler']['tone']==='neutral','an enabled task without a result is not green');
$inventory['sections']['scheduler']=['status'=>'available','data'=>['locked'=>0,'items'=>[
 ['state'=>1,'last_execution'=>null,'last_exit_code'=>null],
 ['state'=>0,'last_execution'=>gmdate('Y-m-d\TH:i:s\Z',$now),'last_exit_code'=>5],
 ['state'=>1,'last_execution'=>gmdate('Y-m-d\TH:i:s\Z',$now),'last_exit_code'=>0]
]]];
$projected=InventoryStore::summarise(InventorySchema::normalise($inventory,['remote_https']));
check($projected['scheduler']['unobserved_enabled']===1&&$projected['scheduler']['failed_enabled']===0,'projection keeps never-run enabled tasks unknown and ignores paused historical errors');
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
