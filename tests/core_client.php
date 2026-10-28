<?php
/** @package Nicode.joomla.webmonitor. Disposable TLS fixture; credentials only on stdin. */
require __DIR__.'/../extensions/com_nicodewebmonitor/administrator/src/Service/InventoryClient.php';
require __DIR__.'/../extensions/com_nicodewebmonitor/administrator/src/Service/UpdateClient.php';
$d=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$client=new \Nicode\Component\NicodeWebMonitor\Administrator\Service\UpdateClient($d['ca'],['127.0.0.1'],15000,2097152,'core_update');
echo json_encode($client->request($d['url'],'127.0.0.1',$d['site_id'],$d['token'],$d['operation'],$d['execute'],$d['execute']),JSON_THROW_ON_ERROR);
