<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Joomla\Database\DatabaseInterface;

/** One bounded, allowlisted observation per site; history never retains payloads. */
final class InventoryStore
{
    public function __construct(private DatabaseInterface $db) {}

    public function save(int $site,array $inventory): void
    {
        $clean=InventorySchema::normalise($inventory,['remote_https','local_web','local_cli']);
        $json=json_encode($clean,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        if(strlen($json)>2097152)throw new \UnexpectedValueException('Inventory too large');
        $sections=$clean['sections'];$summary=['coverage'=>array_map(static fn($section)=>$section['status'],$sections)];
        foreach(['joomla'=>['version'],'runtime'=>['php_version'],'database'=>['version'],'updates'=>['total','last_checked_at'],
            'users'=>['total'],'content'=>['articles'],'storage'=>['free_bytes']] as $category=>$fields){
            $summary[$category]=[];
            foreach($fields as $field)$summary[$category][$field]=$sections[$category]['status']==='available'?($sections[$category]['data'][$field]??null):null;
        }
        $this->db->transactionStart(true);
        try{
            if($this->db->setQuery('SELECT id FROM #__nwm_sites WHERE id='.$site.' FOR UPDATE')->loadResult()){
                $this->db->setQuery('DELETE FROM #__nwm_inventory WHERE site='.$site)->execute();
                $row=(object)['site'=>$site,'received_at'=>time(),'summary'=>json_encode($summary,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),'inventory'=>$json];
                $this->db->insertObject('#__nwm_inventory',$row);
            }
            $this->db->transactionCommit(true);
        }catch(\Throwable $e){$this->db->transactionRollback(true);throw $e;}
    }

    public function latest(int $site): array
    {
        $row=$this->db->setQuery('SELECT received_at,inventory FROM #__nwm_inventory WHERE site='.$site)->loadAssoc();
        if(!$row)return ['ok'=>false,'error'=>'inventory_not_saved'];
        try{$inventory=InventorySchema::normalise(json_decode($row['inventory'],true,64,JSON_THROW_ON_ERROR),['remote_https','local_web','local_cli']);}
        catch(\Throwable){return ['ok'=>false,'error'=>'inventory_not_saved'];}
        return ['ok'=>true,'inventory'=>$inventory,'received_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['received_at']),'from_cache'=>true];
    }
}
