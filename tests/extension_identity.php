<?php
/** @package Nicode.joomla.webmonitor. Ambiguous manifests must not change installation targets. */
require __DIR__.'/../extensions/plg_system_nicodewebmonitor/src/Maintenance/ExtensionIdentity.php';
use Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ExtensionIdentity;
$n=0;
foreach ([
 ['<extension type="module" client="api"><element>mod_qa</element></extension>','mod_qa.xml'],
 ['<extension type="module"><element>mod_other</element><files><filename module="mod_qa">a.php</filename></files></extension>','mod_qa.xml'],
 ['<extension type="plugin" group="system"><element>other</element><files><filename plugin="qa">a.php</filename></files></extension>','qa.xml'],
 ['<extension type="component"><name>com_qa</name><element>com_other</element></extension>','qa.xml'],
 ['<extension type="component"><name>Pretty component name</name></extension>','qa.xml'],
 ['<extension type="module"><element>../mod_qa</element></extension>','mod_qa.xml'],
 ['<extension type="package"><name>pkg_qa</name></extension>','pkg_qa.xml'],
 ['<extension type="template"><name>qa</name></extension>','qa.xml'],
 ['<extension type="template" client="api"><name>qa</name></extension>','templateDetails.xml'],
 ['<extension type="template"><name>../qa</name></extension>','templateDetails.xml'],
 ['<extension type="library"><libraryname>qa</libraryname></extension>','qa.xml'],
 ['<extension type="module"><files><filename module="mod_qa">a.php</filename><filename module="mod_other">b.php</filename></files></extension>','mod_qa.xml'],
 ['<extension type="module"><element>mod_qa</element></extension>','other.xml'],
] as [$xml,$file]) {
 try{ExtensionIdentity::read(simplexml_load_string($xml),$file);throw new LogicException('Unsafe manifest accepted');}catch(RuntimeException $e){$n++;}
}
foreach ([['module','mod_qa','mod_qa.xml',0],['component','com_qa','qa.xml',1],['component','com_qa','com_qa.xml',1],['template','qa','templateDetails.xml',0]] as [$type,$element,$file,$client]) {
 $xml='<extension type="'.$type.'"><name>'.$element.'</name><element>'.$element.'</element></extension>';
 if(ExtensionIdentity::read(simplexml_load_string($xml),$file)!==['type'=>$type,'element'=>$element,'folder'=>'','client_id'=>$client])throw new RuntimeException('Valid identity mismatch');$n++;
}
echo json_encode(['passed'=>$n])."\n";
