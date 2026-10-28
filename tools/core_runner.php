<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Opt-in, bounded host invocation; no daemon or scheduler. */
if (PHP_SAPI!=='cli' || count($argv)!==3 || $argv[2]!=='--run-queued') {
    fwrite(STDERR,"Usage: php core_runner.php JOOMLA_ROOT --run-queued\n");exit(2);
}
// The parent never loads Joomla: discovery, installation and verification each
// start a fresh compatible PHP process. Array commands bypass the shell.
function child(string $script, array $args): array {
    $process=proc_open([PHP_BINARY,__DIR__.'/'.$script,...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a']],
        $pipes,null,null,['bypass_shell'=>true,'create_no_window'=>true]);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start CLI'); }
    fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);fclose($pipes[1]);$code=proc_close($process);
    // A failed installation child may have made effects. Never repeat it here.
    if (!in_array($code,[0,3],true)) { return ['status'=>'unavailable']; }
    $data=json_decode($output,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_string($data['status']??null)) { throw new RuntimeException('Invalid CLI result'); }
    return $data;
}
try {
    $state=child('core_runner_state.php',[$argv[1]]);
    if (in_array($state['status'],['queued','uncertain','verified'],true)) {
        $id=$state['operation']??'';
        if (!is_string($id) || !preg_match('/^[a-z0-9-]{8,64}$/D',$id)) { throw new RuntimeException('Invalid operation'); }
        if ($state['status']==='queued') {
            child('core_update_execute.php',[$argv[1],$id,'--execute-queued']);
        }
        // Also after an abnormal child exit: inspect effects, never reinstall.
        $state=child('core_update_execute.php',[$argv[1],$id,'--reconcile']);
    }
    echo json_encode($state,JSON_THROW_ON_ERROR)."\n";
    exit(in_array($state['status'],['idle','verified'],true)?0:3);
} catch (Throwable) { fwrite(STDERR,"Runner unavailable or uncertain; inspect receipts. No automatic reinstall.\n");exit(2); }
