<?php
require_once __DIR__.'/inventory_fixture.php';
/** Untrusted inventory must remain plain text; unavailable values stay explicit. */
define('_JEXEC', 1);
$user=new class { public function authorise($action, $component) { return false; } };
$sites=[['id'=>1,'label'=>'View fixture','base_url'=>'https://example.invalid','check_state'=>'recent_partial']]; $inspectedId=1;
$inspection=['ok'=>true, 'received_at'=>'2026-10-10T14:00:01Z', 'inventory'=>[
    'status'=>'partial', 'collected_at'=>'2026-10-10T14:00:00Z', 'sections'=>[
        'joomla'=>['status'=>'available', 'data'=>['version'=>'6.1.4']],
        'runtime'=>['status'=>'available', 'data'=>['php_version'=>['bad'], 'sapi'=>'cgi-fcgi']],
        'database'=>['status'=>'error', 'data'=>null],
        'storage'=>['status'=>'denied', 'data'=>null],
        'extensions'=>['status'=>'available', 'data'=>['total'=>4,'items'=>[
            ['name'=>'<script>alert(1)</script>', 'type'=>'plugin', 'version'=>['status'=>'unknown','value'=>null], 'enabled'=>false],
            ['id'=>123, 'package_id'=>456, 'name'=>'Connector', 'type'=>'plugin', 'version'=>['status'=>'available','value'=>'0.2.0-dev'], 'enabled'=>true],
            ['id'=>456, 'package_id'=>0, 'name'=>'Package', 'type'=>'package'],
            ['name'=>[], 'type'=>[], 'version'=>'invalid'],
        ]]],
    ],
]];
function renderInventory(): string {
    global $user, $sites, $inspectedId, $inspection;
    $viewSection='inventory';$viewCategory='extensions';
    $canUpdate=false; $canCoreUpdate=false; $canBackup=false;
    ob_start(); require dirname(__DIR__).'/extensions/com_nicodewebmonitor/administrator/tmpl/registry.php'; return ob_get_clean();
}
set_error_handler(static function($severity,$message) { throw new ErrorException($message,0,$severity); });
$html=renderInventory();
$checks=[
    'only_selected_category'=>str_contains($html,'data-category="extensions"')&&!str_contains($html,'data-category="runtime"'),
    'escaped_remote_name'=>!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),
    'unknown_values'=>str_contains($html,'No disponible') && !str_contains($html,'Array'),
    'extension_version'=>str_contains($html,'0.2.0-dev'),
    'disabled_extension'=>str_contains($html,'<td>No</td>'),
    'package_relation'=>str_contains($html,'<td>456</td>') && str_contains($html,'<td>123</td>') && str_contains($html,'<td>0</td>'),
];
$inspection['inventory']['sections']['extensions']=['status'=>'error','data'=>null];
$checks['unavailable_extensions']=str_contains(renderInventory(),'El Conector no ha podido leer esta categoría.');
$inspection=['ok'=>false,'error'=>'access_denied'];
$html=renderInventory();
$checks['failure_without_stale_inventory']=str_contains($html,'access_denied') && !str_contains($html,'Extensiones instaladas');
if (in_array(false,$checks,true)) { throw new RuntimeException('View checks failed'); }
echo json_encode(['passed'=>count($checks),'checks'=>array_keys($checks)],JSON_PRETTY_PRINT).PHP_EOL;
