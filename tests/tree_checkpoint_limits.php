<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor */
require dirname(__DIR__) . '/tools/TreeManifest.php';
use Nicode\Maintenance\TreeManifest;
$root=$argv[1]; mkdir($root.'/source'); mkdir($root.'/store');
$provider=new TreeManifest($root.'/store'); $checks=[];
function check(bool $ok,string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[]=$name; }
foreach ([0,65] as $units) {
    $caught=false;
    try { $provider->step($root.'/source','budget-test',true,$units); } catch (InvalidArgumentException) { $caught=true; }
    check($caught,'invalid budget '.$units.' rejected');
}
check(!file_exists($root.'/store/budget-test'),'invalid budget creates no operation');
$provider->step($root.'/source','bounded-tree',true);
$path=$root.'/store/bounded-tree/state.json'; $before=file_get_contents($path);
// Exercise the private serialization boundary without creating a huge source tree.
$save=new ReflectionMethod(TreeManifest::class,'save'); $caught=false;
try { $save->invoke(null,$path,['oversize'=>str_repeat('x',TreeManifest::MAX_CHECKPOINT)]); }
catch (RuntimeException) { $caught=true; }
check($caught,'oversized serialization rejected');
check(file_get_contents($path)===$before,'previous committed checkpoint preserved');
check(!file_exists($path.'.next'),'no oversized temporary checkpoint');
file_put_contents($path,str_repeat('x',TreeManifest::MAX_CHECKPOINT+1)); $caught=false;
try { $provider->step($root.'/source','bounded-tree',true,64); } catch (RuntimeException) { $caught=true; }
check($caught,'oversized input checkpoint rejected');
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT);
