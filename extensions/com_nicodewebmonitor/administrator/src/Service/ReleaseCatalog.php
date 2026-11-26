<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\User\User;

final class ReleaseCatalog
{
    public function __construct(private DatabaseInterface $db,private ?\Closure $download=null){}
    public static function seed(): array
    {
        $file=__DIR__.'/release-catalog.json';
        if(!is_readable($file))return [];
        try{$data=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}
        catch(\Throwable){return [];}
    }
    public function read(): array
    {
        $params=$this->params();$catalog=self::seed();
        foreach(['php','joomla'] as $product){
            $cached=$params['release_references'][$product]??null;
            if(is_array($cached)&&is_int($cached['checked_at']??null)&&is_array($cached['branches']??null)
                &&$cached['checked_at']>($catalog[$product]['checked_at']??0))$catalog[$product]=$cached;
        }
        return $catalog;
    }
    /** Called by a native POST or scheduler, never while rendering a GET. */
    public function refresh(User $user,bool $force=false,?int $now=null): array
    {
        if(!$user->id||$user->guest||$user->block||!$user->authorise('core.manage','com_nicodewebmonitor')
            ||!$user->authorise('nwm.monitor','com_nicodewebmonitor'))throw new \RuntimeException('Access denied',403);
        $now??=time();$catalog=$this->read();$params=$this->params();$changed=[];$failed=[];
        $lastAttempt=(int)($params['release_reference_attempt']??0);
        if(!$force&&$lastAttempt>$now-3600&&$lastAttempt<=$now+300)return ['status'=>'cached'];
        foreach(['php','joomla'] as $product){
            $checked=$catalog[$product]['checked_at']??0;
            if(!$force&&$checked<=$now+300&&$checked>$now-86400)continue;
            try{$body=$this->download?($this->download)($product):(new ReferenceHttp())->fetch($product);
                $changed[$product]=ReleaseReference::parse($product,$body,$now);
            }catch(\Throwable){$failed[]=$product;}
        }
        if(!$changed&&!$failed)return ['status'=>'cached'];
        $this->db->transactionStart(true);
        try{
            // Reload under lock; preserve notification settings and unrelated component params.
            $params=$this->params(true);$params['release_reference_attempt']=$now;
            foreach($changed as $product=>$reference){
                if(($reference['checked_at'])>=($params['release_references'][$product]['checked_at']??0))$params['release_references'][$product]=$reference;
            }
            $this->db->setQuery("UPDATE #__extensions SET params=".$this->db->quote(json_encode($params,JSON_THROW_ON_ERROR))." WHERE type='component' AND element='com_nicodewebmonitor'")->execute();
            $this->db->transactionCommit(true);
        }catch(\Throwable $e){$this->db->transactionRollback(true);throw $e;}
        return ['status'=>$failed?'partial':'updated','updated'=>array_keys($changed),'unavailable'=>$failed];
    }
    private function params(bool $lock=false): array
    {
        $raw=$this->db->setQuery("SELECT params FROM #__extensions WHERE type='component' AND element='com_nicodewebmonitor'".($lock?' FOR UPDATE':''))->loadResult();
        $params=json_decode($raw??'{}',true);return is_array($params)?$params:[];
    }
}
