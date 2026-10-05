<?php
/** Pure security boundary tests; random test credentials exist only in memory. */
require __DIR__ . '/../extensions/plg_system_nicodewebmonitor/src/Transport/Endpoint.php';
use Nicode\Plugin\System\NicodeWebMonitor\Transport\Endpoint;
$checks = [];
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks[] = $label;
}
$token = bin2hex(random_bytes(32));
$site = '93f16d45-8ed7-4f93-989b-28216a0d4328';
$endpoint = new Endpoint($site, hash('sha256', $token));
$server = ['HTTPS'=>'on', 'REQUEST_METHOD'=>'GET', 'HTTP_AUTHORIZATION'=>'Bearer '.$token];
$calls = 0;
$collect = function () use (&$calls) { $calls++; return ['capabilities'=>['remote_inventory'=>false,'remote_updates'=>false,'remote_backups'=>false]]; };
foreach ([
    'plain_http'=>[array_replace($server,['HTTPS'=>'off']), 'inventory',403],
    'spoofed_proxy'=>[array_replace($server,['HTTPS'=>'','HTTP_X_FORWARDED_PROTO'=>'https']), 'inventory',403],
    'missing_token'=>[array_replace($server,['HTTP_AUTHORIZATION'=>'']), 'inventory',401],
    'wrong_token'=>[array_replace($server,['HTTP_AUTHORIZATION'=>'Bearer '.bin2hex(random_bytes(32))]), 'inventory',401],
    'appended_header'=>[array_replace($server,['HTTP_AUTHORIZATION'=>$server['HTTP_AUTHORIZATION']."\n"]), 'inventory',401],
    'post'=>[array_replace($server,['REQUEST_METHOD'=>'POST']), 'inventory',405],
    'update_not_supported'=>[$server, 'update',404],
    'backup_not_supported'=>[$server, 'backup',404],
] as $name => [$input,$task,$expected]) {
    [$status,$body]=$endpoint->handle($input,$task,$collect);
    check($status===$expected && $calls===0 && !isset($body['inventory']),$name);
}
[$status,$body]=$endpoint->handle($server,'inventory',$collect);
check($status===200 && $calls===1 && $body['site_id']===$site,'authorized_identity');
check($body['inventory']['capabilities']===['remote_inventory'=>true,'remote_updates'=>false,'remote_backups'=>false],'read_only_capability');
check(!str_contains(json_encode($body),$token),'credential_not_returned');
foreach (['', 'invalid', hash('sha256',bin2hex(random_bytes(32)))] as $hash) {
    [$status,$body]=(new Endpoint($site,$hash))->handle($server,'inventory',$collect);
    check(in_array($status,[401,503],true) && $calls===1,'revoked_or_rotated_'.count($checks));
}
check((new Endpoint('invalid',hash('sha256',$token)))->handle($server,'inventory',$collect)[0]===503,'invalid_identity_closed');
[$status,$body]=$endpoint->handle($server,'inventory',fn()=>throw new RuntimeException('private details'));
check($status===503 && !str_contains(json_encode($body),'private details'),'exception_not_disclosed');
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT).PHP_EOL;
