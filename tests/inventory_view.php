<?php
/** Untrusted inventory must remain plain text; unavailable values stay explicit. */
define('_JEXEC', 1);
$user=new class { public function authorise($action, $component) { return false; } };
$sites=[]; $inspectedId=1;
$inspection=['ok'=>true, 'received_at'=>'2026-10-10T14:00:01Z', 'inventory'=>[
    'status'=>'partial', 'collected_at'=>'2026-10-10T14:00:00Z', 'sections'=>[
        'joomla'=>['status'=>'available', 'data'=>['version'=>'6.1.4']],
        'runtime'=>['status'=>'available', 'data'=>['php_version'=>['bad'], 'sapi'=>'cgi-fcgi']],
        'database'=>['status'=>'error', 'data'=>null],
        'hosting'=>['status'=>'unavailable', 'data'=>null],
        'extensions'=>['status'=>'available', 'data'=>[
            ['name'=>'<script>alert(1)</script>', 'type'=>'plugin', 'version'=>['status'=>'unknown','value'=>null], 'enabled'=>false],
            ['name'=>'Connector', 'type'=>'plugin', 'version'=>['status'=>'available','value'=>'0.2.0-dev'], 'enabled'=>true],
            ['name'=>[], 'type'=>[], 'version'=>'invalid'],
        ]],
    ],
]];
function renderInventory(): string {
    global $user, $sites, $inspectedId, $inspection;
    ob_start(); require dirname(__DIR__).'/extensions/com_nicodewebmonitor/administrator/tmpl/registry.php'; return ob_get_clean();
}
set_error_handler(static function($severity,$message) { throw new ErrorException($message,0,$severity); });
$html=renderInventory();
$checks=[
    'software_version'=>str_contains($html,'6.1.4'),
    'escaped_remote_name'=>!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),
    'unknown_values'=>str_contains($html,'Unknown / unavailable') && !str_contains($html,'Array'),
    'extension_version'=>str_contains($html,'0.2.0-dev'),
    'disabled_extension'=>str_contains($html,'<td>No</td>'),
];
$inspection['inventory']['sections']['extensions']=['status'=>'error','data'=>null];
$checks['unavailable_extensions']=str_contains(renderInventory(),'Extension inventory unavailable.');
$inspection=['ok'=>false,'error'=>'access_denied'];
$html=renderInventory();
$checks['failure_without_stale_inventory']=str_contains($html,'access_denied') && !str_contains($html,'Installed extensions');
if (in_array(false,$checks,true)) { throw new RuntimeException('View checks failed'); }
echo json_encode(['passed'=>count($checks),'checks'=>array_keys($checks)],JSON_PRETTY_PRINT).PHP_EOL;
