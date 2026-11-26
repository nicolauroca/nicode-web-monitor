<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeConnector\Administrator\Service;
use Joomla\Registry\Registry;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\User\User;
use Nicode\Component\NicodeConnector\Administrator\Connection\PublicAddress;
use Nicode\Component\NicodeConnector\Administrator\Connection\JsonClient;

final class Connection
{
    public function __construct(private DatabaseInterface $db, private CredentialVault $vault,
        private ?\Closure $resolve=null, private ?\Closure $send=null) {}

    public function state(): array
    {
        $params=$this->params('component','com_nicodeconnector');
        $plugin=$this->params('plugin','nicodewebmonitor');
        $enabled=(int)$this->db->setQuery("SELECT enabled FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult();
        return ['state'=>$params->get('connection_state','disconnected'),'central'=>$params->get('central_url',''),
            'connected_at'=>$params->get('connected_at',''),'connection_type'=>$params->get('connection_type','https'),'enabled'=>(bool)$enabled && $plugin->get('token_hash','')!==''];
    }

    public function sharing(): array
    {
        $params=$this->params('component','com_nicodeconnector');
        if(!array_key_exists('inventory_sharing',$params->toArray()))return InventorySchema::policy();
        $saved=$params->get('inventory_sharing');
        if(is_object($saved))$saved=(array)$saved;
        return InventorySchema::policy(is_array($saved)?$saved:[]);
    }

    /** Only a local component administrator may change this site's disclosure. */
    public function saveSharing(User $user,array $selected): void
    {
        if($user->guest||$user->block||!$user->authorise('core.manage','com_nicodeconnector')||!$user->authorise('core.admin','com_nicodeconnector'))throw new \RuntimeException('Access denied',403);
        if(array_diff(array_keys($selected),array_keys(InventorySchema::categories())))throw new \InvalidArgumentException('Invalid sharing category');
        foreach($selected as $value)if(!in_array($value,[true,false,1,0,'1','0'],true))throw new \InvalidArgumentException('Invalid sharing value');
        $lock=$this->db->quote('nwm-connector:'.substr(hash('sha256',$this->db->getPrefix().JPATH_ROOT),0,40));
        if(!(int)$this->db->setQuery('SELECT GET_LOCK('.$lock.',0)')->loadResult())throw new \RuntimeException('connection_busy');
        try{
            $params=$this->params('component','com_nicodeconnector');
            $params->set('inventory_sharing',InventorySchema::policy($selected));
            $this->save('component','com_nicodeconnector',$params);
        }finally{$this->db->setQuery('SELECT RELEASE_LOCK('.$lock.')')->loadResult();}
    }

    /** Persist a recoverable pending attempt before any network action. */
    public function connect(string $central, #[\SensitiveParameter] string $code, string $localUrl): void
    {
        $central=PublicAddress::url(trim($central)); $localUrl=PublicAddress::url($localUrl); $code=trim($code);
        if (!preg_match('/^[a-f0-9]{64}$/D',$code)) throw new \RuntimeException('code_unavailable');
        $versions=$this->db->setQuery("SELECT type,manifest_cache FROM #__extensions WHERE (type='component' AND element='com_nicodeconnector') OR (type='plugin' AND folder='system' AND element='nicodewebmonitor')")->loadAssocList();
        $installed=[];
        foreach ($versions as $version) $installed[$version['type']]=json_decode($version['manifest_cache'],true)['version']??'';
        if (empty($installed['component']) || ($installed['component']??'')!==($installed['plugin']??'')) throw new \RuntimeException('package_incomplete');
        $lock=$this->db->quote('nwm-connector:'.substr(hash('sha256',$this->db->getPrefix().JPATH_ROOT),0,40));
        if (!(int)$this->db->setQuery('SELECT GET_LOCK('.$lock.',0)')->loadResult()) throw new \RuntimeException('connection_busy');
        try {
            $params=$this->params('component','com_nicodeconnector');
            $plugin=$this->params('plugin','nicodewebmonitor');
            if ($params->get('connection_state')==='connected') throw new \RuntimeException('already_connected');
            $attempt=hash('sha256',$central."\n".$localUrl."\n".$code);
            if ($params->get('attempt')===$attempt && $params->get('pending_token')) {
                $uuid=$plugin->get('site_id');
                $token=$this->vault->open($uuid,$params->get('pending_token'));
            } else {
                if ($params->get('connection_state')==='pending') throw new \RuntimeException('pending_connection');
                $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&15)|64); $bytes[8]=chr((ord($bytes[8])&63)|128);
                $id=bin2hex($bytes); $uuid=substr($id,0,8).'-'.substr($id,8,4).'-'.substr($id,12,4).'-'.substr($id,16,4).'-'.substr($id,20);
                $token=bin2hex(random_bytes(32));
                $plugin->set('site_id',$uuid); $plugin->set('token_hash',hash('sha256',$token));
                $params->set('central_url',$central); $params->set('connection_state','pending');
                $params->set('attempt',$attempt); $params->set('pending_token',$this->vault->seal($uuid,$token));
                $this->db->transactionStart();
                try {
                    $this->save('plugin','nicodewebmonitor',$plugin,true);
                    $this->save('component','com_nicodeconnector',$params);
                    $this->db->transactionCommit();
                } catch (\Throwable $e) { $this->db->transactionRollback(); throw $e; }
            }
            $payload=['code'=>$code,'site_id'=>$uuid,'credential'=>$token,'base_url'=>$localUrl];
            $local=$this->send===null && $central===$localUrl && (int)$this->db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE type='component' AND element='com_nicodewebmonitor' AND enabled=1")->loadResult()===1;
            if ($local) {
                $centralVersion=json_decode($this->db->setQuery("SELECT manifest_cache FROM #__extensions WHERE type='component' AND element='com_nicodewebmonitor'")->loadResult(),true)['version']??'';
                if ($centralVersion!==$installed['component']) throw new \RuntimeException('package_incomplete');
                \Joomla\CMS\Factory::getApplication()->bootComponent('com_nicodewebmonitor');
                $service=new \Nicode\Component\NicodeWebMonitor\Administrator\Service\PairingService($this->db,
                    new \Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault(\Joomla\CMS\Factory::getApplication()->get('secret')));
                $receipt=$service->claimLocal($payload);
            } else {
                $address=$this->resolve ? ($this->resolve)($central) : (new PublicAddress())->resolve($central);
                $receipt=$this->send ? ($this->send)($central,$address,$payload) : (new JsonClient())->post($central,$address,$payload);
            }
            if (($receipt['ok']??false)!==true || ($receipt['site_id']??'')!==$uuid) throw new \RuntimeException('connection_uncertain');
            $params->set('connection_state','connected'); $params->set('connected_at',gmdate('Y-m-d H:i:s'));
            $params->set('connection_type',$local?'local':'https');
            $params->remove('pending_token'); $params->remove('attempt');
            $this->save('component','com_nicodeconnector',$params);
        } finally { $this->db->setQuery('SELECT RELEASE_LOCK('.$lock.')')->loadResult(); }
    }

    public function disconnect(): void
    {
        $lock=$this->db->quote('nwm-connector:'.substr(hash('sha256',$this->db->getPrefix().JPATH_ROOT),0,40));
        if (!(int)$this->db->setQuery('SELECT GET_LOCK('.$lock.',0)')->loadResult()) throw new \RuntimeException('connection_busy');
        try {
            $plugin=$this->params('plugin','nicodewebmonitor'); $plugin->set('token_hash','');
            $this->db->transactionStart();
            try {
                $this->save('plugin','nicodewebmonitor',$plugin);
                // Revoking a connection never expands this site's disclosure policy.
                $params=$this->params('component','com_nicodeconnector');
                $retained=new Registry();
                if(array_key_exists('inventory_sharing',$params->toArray()))$retained->set('inventory_sharing',$this->sharing());
                $this->save('component','com_nicodeconnector',$retained);
                $this->db->transactionCommit();
            } catch (\Throwable $e) { $this->db->transactionRollback(); throw $e; }
        } finally { $this->db->setQuery('SELECT RELEASE_LOCK('.$lock.')')->loadResult(); }
    }

    private function where(string $type,string $element): string
    {
        return 'type='.$this->db->quote($type).' AND element='.$this->db->quote($element).($type==='plugin' ? " AND folder='system'" : '');
    }
    private function params(string $type,string $element): Registry
    {
        $row=$this->db->setQuery('SELECT params FROM #__extensions WHERE '.$this->where($type,$element))->loadResult();
        if ($row===null) throw new \RuntimeException('package_incomplete');
        return new Registry($row);
    }
    private function save(string $type,string $element,Registry $params,bool $enable=false): void
    {
        $this->db->setQuery('UPDATE #__extensions SET params='.$this->db->quote($params->toString()).($enable ? ', enabled=1' : '').' WHERE '.$this->where($type,$element))->execute();
    }
}
