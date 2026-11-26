<?php
/** Synthetic current-contract inventory; never copies a site's payload. */
require_once __DIR__.'/../extensions/plg_console_nicodewebmonitor/src/Inventory/InventorySchema.php';
if(!class_exists(\Nicode\Component\NicodeWebMonitor\Administrator\Service\InventorySchema::class))
    class_alias(\Nicode\Plugin\Console\NicodeWebMonitor\Inventory\InventorySchema::class,\Nicode\Component\NicodeWebMonitor\Administrator\Service\InventorySchema::class);
function nwmInventoryFixture(string $status='complete'): array
{
    $sections=[];
    foreach(\Nicode\Plugin\Console\NicodeWebMonitor\Inventory\InventorySchema::categories() as $key=>$definition)$sections[$key]=['status'=>'denied','reason'=>'sharing_disabled','data'=>null];
    $sections['joomla']=['status'=>'available','data'=>['version'=>'6.1.4']];
    if($status==='partial')$sections['database']=['status'=>'error','data'=>null];
    return ['schema_version'=>2,'collector_version'=>'test-fixture','collected_at'=>gmdate('Y-m-d\TH:i:s\Z'),'status'=>$status,
        'execution_context'=>'remote_https','capabilities'=>['remote_inventory'=>true,'remote_updates'=>false,'remote_backups'=>false],'sections'=>$sections];
}
