<?php
require dirname(__DIR__).'/extensions/com_nicodewebmonitor/administrator/src/Service/CheckHistory.php';
require dirname(__DIR__).'/extensions/com_nicodewebmonitor/administrator/src/Service/CheckAlerts.php';
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CheckAlerts;
$checks=[];
function check($ok, $name) { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[]=$name; }
function observation($at, $detail='complete', $outcome='received') {
    return ['checked_at'=>$at,'collected_at'=>$outcome==='received'?$at:null,'outcome'=>$outcome,'detail'=>$detail];
}
$t=1700000000;
$good=observation($t);
$failed=observation($t+1,'access_denied','failed');
$a=CheckAlerts::summarize([],$t);
check(!$a['active'] && $a['transitions']===[],'unobserved is not healthy or an alert');
$a=CheckAlerts::summarize([$good],$t);
check(!$a['active'] && $a['transitions'][0]['kind']==='baseline','first success not recovery');
$a=CheckAlerts::summarize([$failed,$good],$t+1);
check($a['active'] && $a['transitions'][0]['kind']==='opened','revoked credential opens alert');
$failedAgain=observation($t+2,'connection_failed','failed');
$a=CheckAlerts::summarize([$failedAgain,$failed,$good],$t+2);
check(count($a['transitions'])===2,'repeated failures do not duplicate transitions');
$partial=observation($t+3,'partial');
$a=CheckAlerts::summarize([$partial,$failedAgain,$failed,$good],$t+3);
check($a['active'] && $a['transitions'][0]['kind']==='changed' && $a['state']==='recent_partial','partial does not clear failure into healthy');
$recovered=observation($t+4);
$a=CheckAlerts::summarize([$recovered,$partial,$failed,$good],$t+4);
check(!$a['active'] && $a['transitions'][0]['kind']==='recovered','complete observation recovers');
check(CheckAlerts::summarize([$good],$t+86400)['active']===false,'freshness inclusive boundary');
$a=CheckAlerts::summarize([$good],$t+86401);
check($a['active'] && $a['transitions'][0]['at']===$t+86401,'expiry opens alert without a check');
check($a===CheckAlerts::summarize([$good],$t+90000),'repeated stale reads keep same transition timestamp');
$a=CheckAlerts::summarize([observation($t+90000),$good],$t+90000);
check($a['transitions'][0]['kind']==='recovered' && $a['transitions'][1]['to']==='stale','gap expiry and recovery reconstructed');
$old=$good; $old['collected_at']=$t-86401;
$a=CheckAlerts::summarize([$old],$t);
check($a['active'] && $a['state']==='stale','stale remote inventory remains alert');
$unknown=$good; $unknown['collected_at']=null;
$a=CheckAlerts::summarize([$unknown],$t);
check($a['active'] && $a['state']==='clock_uncertain','missing collection time alerts');
$future=$good; $future['collected_at']=$t+301;
check(CheckAlerts::summarize([$future],$t)['state']==='clock_uncertain','remote clock uncertainty alerts');
check(CheckAlerts::summarize([observation($t+301)],$t)['state']==='clock_uncertain','central future clock alerts');
$rows=[];
for($i=0;$i<105;$i++){array_unshift($rows,observation($t+$i,$i%2?'partial':'complete'));}
check(count(CheckAlerts::summarize($rows,$t+105)['transitions'])===10,'transition display bounded');
$databaseRows=array_map(static fn($row)=>array_map(static fn($v)=>$v===null?null:(string)$v,$row),[$recovered,$failed]);
check(CheckAlerts::summarize([$recovered,$failed],$t+4)===CheckAlerts::summarize($databaseRows,$t+4),'database string-safe replay');
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
