<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/** Same-installation inventory: no DNS, socket or forged HTTPS request. */
final class LocalInventory
{
    public function __construct(private DatabaseInterface $db) {}

    public function fetch(string $siteId, #[\SensitiveParameter] string $token): array
    {
        $row=$this->db->setQuery("SELECT enabled,params FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadAssoc();
        $params=json_decode($row['params']??'{}',true)?:[];
        if (!$row || !(int)$row['enabled'] || !is_string($params['token_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$params['token_hash'])) return ['ok'=>false,'error'=>'connector_unavailable'];
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$siteId)) return ['ok'=>false,'error'=>'identity_mismatch'];
        if (($params['site_id']??'')!==$siteId) return ['ok'=>false,'error'=>'identity_mismatch'];
        if (!preg_match('/^[a-f0-9]{64}$/D',$token) || !hash_equals($params['token_hash'],hash('sha256',$token))) return ['ok'=>false,'error'=>'access_denied'];
        try {
            Factory::getApplication()->bootPlugin('nicodewebmonitor','system');
            $inventory=(new \Nicode\Plugin\System\NicodeWebMonitor\Inventory\Collector($this->db,JVERSION))->collect();
            $inventory['execution_context']=PHP_SAPI==='cli' ? 'local_cli' : 'local_web';
            return ['ok'=>true,'received_at'=>gmdate('Y-m-d\TH:i:s\Z'),'inventory'=>$inventory];
        } catch (\Throwable) { return ['ok'=>false,'error'=>'collection_failed']; }
    }
}
