<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\Console\NicodeWebMonitor\Inventory;

use Joomla\Database\DatabaseInterface;

final class Collector
{
    public function __construct(private DatabaseInterface $database, private string $joomlaVersion,
        private ?array $sharing=null, private array $configuration=[], private string $root='') {}

    /** The monitored component owns the policy, including for local CLI monitoring. */
    public static function forSite(DatabaseInterface $db,string $version): self
    {
        $raw=$db->setQuery("SELECT params FROM #__extensions WHERE type='component' AND element='com_nicodeconnector'")->loadResult();
        $params=json_decode($raw??'{}',true);
        $saved=is_array($params)&&array_key_exists('inventory_sharing',$params)
            ? (is_array($params['inventory_sharing'])?$params['inventory_sharing']:[]) : null;
        if($raw===null || !is_array($params))$saved=[];
        $config=\Joomla\CMS\Factory::getConfig();$safe=[];
        foreach(['sitename','offline','offset','editor','caching','cachetime','cache_handler','sef','sef_rewrite','sef_suffix','gzip','robots','session_handler','lifetime','force_ssl','debug','debug_lang','error_reporting'] as $key)$safe[$key]=$config->get($key);
        return new self($db,$version,$saved,$safe,JPATH_ROOT);
    }

    public function collect(): array
    {
        if(version_compare($this->joomlaVersion,'6.0.0','<'))throw new \RuntimeException('Joomla 6 or newer is required.');
        $sections=[];
        foreach(InventorySchema::policy($this->sharing) as $category=>$allowed){
            // Denial precedes the reader: neither DB queries nor filesystem probes run.
            if(!$allowed){$sections[$category]=['status'=>'denied','reason'=>'sharing_disabled','data'=>null];continue;}
            try{$sections[$category]=['status'=>'available','data'=>$this->read($category)];}
            catch(\Throwable){$sections[$category]=['status'=>'error','reason'=>'collection_failed','data'=>null];}
        }
        return InventorySchema::normalise([
            'schema_version'=>InventorySchema::VERSION,'collector_version'=>'1.1.1-dev.7',
            'collected_at'=>gmdate('Y-m-d\TH:i:s\Z'),'execution_context'=>'local_cli',
            'status'=>in_array('error',array_column($sections,'status'),true)?'partial':'complete',
            'sections'=>$sections,'capabilities'=>['remote_inventory'=>false,'remote_updates'=>false,'remote_backups'=>false],
        ],['local_cli']);
    }

    private function read(string $category): array
    {
        return match($category){
            'joomla'=>['version'=>$this->joomlaVersion],
            'runtime'=>['php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'os_family'=>PHP_OS_FAMILY,'memory_limit'=>(string)ini_get('memory_limit'),
                'max_execution_time'=>(int)ini_get('max_execution_time'),'loaded_extensions'=>get_loaded_extensions(),
                'upload_max_filesize'=>(string)ini_get('upload_max_filesize'),'post_max_size'=>(string)ini_get('post_max_size'),
                'max_input_vars'=>(int)ini_get('max_input_vars'),'opcache_enabled'=>extension_loaded('Zend OPcache')&&(bool)ini_get(PHP_SAPI==='cli'?'opcache.enable_cli':'opcache.enable'),
                'memory_used_bytes'=>memory_get_usage(true),'memory_peak_bytes'=>memory_get_peak_usage(true)],
            'database'=>$this->database(),
            'extensions'=>$this->extensions(),
            'updates'=>$this->updates(),
            'configuration'=>$this->configuration(),
            'security'=>['force_ssl'=>$this->integerConfig('force_ssl'),'debug'=>$this->boolConfig('debug'),'debug_lang'=>$this->boolConfig('debug_lang'),
                'https_observed'=>PHP_SAPI==='cli'?null:in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true),
                'display_errors'=>in_array(strtolower((string)ini_get('display_errors')),['1','on','yes','true'],true),
                'error_reporting'=>$this->stringConfig('error_reporting'),'mfa_users'=>$this->count('SELECT COUNT(DISTINCT user_id) FROM #__user_mfa')],
            'users'=>$this->users(),
            'content'=>$this->content(),
            'languages'=>$this->languages(),
            'templates'=>$this->list('SELECT title,template,client_id,home FROM #__template_styles ORDER BY id','SELECT COUNT(*) FROM #__template_styles',static fn($r)=>['title'=>$r['title'],'template'=>$r['template'],'client_id'=>(int)$r['client_id'],'home'=>(string)$r['home']]),
            'scheduler'=>$this->scheduler(),
            'storage'=>$this->storage(),
            default=>throw new \LogicException('Unknown inventory category'),
        };
    }

    private function count(string $sql): int {return (int)$this->database->setQuery($sql)->loadResult();}
    private function stringConfig(string $key): ?string {return is_scalar($this->configuration[$key]??null)?(string)$this->configuration[$key]:null;}
    private function boolConfig(string $key): ?bool {return isset($this->configuration[$key])?(bool)$this->configuration[$key]:null;}
    private function integerConfig(string $key): ?int {return isset($this->configuration[$key])?(int)$this->configuration[$key]:null;}
    private static function date(mixed $value): ?string
    {
        if(!$value || str_starts_with((string)$value,'0000-'))return null;
        try{return (new \DateTimeImmutable((string)$value,new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');}catch(\Throwable){return null;}
    }
    private function list(string $sql,string $count,\Closure $map): array
    {
        $total=$this->count($count);
        $rows=$this->database->setQuery($sql,0,InventorySchema::ROW_LIMIT)->loadAssocList();
        return ['items'=>array_map($map,$rows),'total'=>$total,'truncated'=>$total>count($rows)];
    }
    private function extensions(): array
    {
        return $this->list('SELECT extension_id,name,type,element,folder,client_id,package_id,enabled,manifest_cache FROM #__extensions ORDER BY extension_id','SELECT COUNT(*) FROM #__extensions',ExtensionRecord::fromRow(...));
    }
    private function database(): array
    {
        $result=['driver'=>$this->database->getName(),'server_type'=>$this->database->getServerType(),'version'=>$this->database->getVersion(),'table_count'=>null,'size_bytes'=>null];
        if($result['server_type']==='mysql'){
            $prefix=$this->database->quote($this->database->getPrefix());
            $row=$this->database->setQuery('SELECT COUNT(*) AS total,COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,LENGTH('.$prefix.'))='.$prefix)->loadAssoc();
            $result['table_count']=(int)$row['total'];$result['size_bytes']=(int)$row['bytes'];
        }
        return $result;
    }
    private function updates(): array
    {
        $list=$this->list('SELECT u.name,u.type,u.element,u.version,u.extension_id,e.manifest_cache FROM #__updates AS u LEFT JOIN #__extensions AS e ON e.extension_id=u.extension_id ORDER BY u.update_id','SELECT COUNT(*) FROM #__updates',static function($r){
            $manifest=json_decode($r['manifest_cache']??'{}',true);
            return ['name'=>$r['name'],'type'=>$r['type'],'element'=>$r['element'],'version'=>$r['version'],'extension_id'=>(int)$r['extension_id'],'current_version'=>is_array($manifest)?($manifest['version']??null):null];
        });
        $row=$this->database->setQuery('SELECT COUNT(*) AS sources,MIN(last_check_timestamp) AS oldest,MAX(last_check_timestamp) AS latest FROM #__update_sites WHERE enabled=1')->loadAssoc();
        return $list+['enabled_sources'=>(int)$row['sources'],'last_checked_at'=>(int)$row['latest']>0?gmdate('Y-m-d\TH:i:s\Z',(int)$row['latest']):null,
            'oldest_checked_at'=>(int)$row['oldest']>0?gmdate('Y-m-d\TH:i:s\Z',(int)$row['oldest']):null];
    }
    private function configuration(): array
    {
        $result=[];
        foreach(InventorySchema::fields()['configuration'] as $key=>[$label,$type]){
            $source=['timezone'=>'offset','session_lifetime'=>'lifetime'][$key]??$key;
            $result[$key]=match($type){'bool'=>$this->boolConfig($source),'int'=>$this->integerConfig($source),default=>$this->stringConfig($source)};
        }
        return $result;
    }
    private function content(): array
    {
        $select=['COUNT(*) AS articles'];
        foreach(['published'=>1,'unpublished'=>0,'archived'=>2,'trashed'=>-2] as $key=>$state)$select[]='SUM(CASE WHEN state='.$state.' THEN 1 ELSE 0 END) AS '.$key;
        $result=array_map('intval',$this->database->setQuery('SELECT '.implode(',',$select).' FROM #__content')->loadAssoc());
        $result['categories']=$this->count("SELECT COUNT(*) FROM #__categories WHERE extension='com_content'");
        $result['menu_items']=$this->count('SELECT COUNT(*) FROM #__menu WHERE client_id=0 AND id>1');
        return $result;
    }
    private function users(): array
    {
        $date=$this->database->quote(gmdate('Y-m-d H:i:s',time()-30*86400));
        $sql="SELECT COUNT(*) AS total,SUM(CASE WHEN block=0 THEN 1 ELSE 0 END) AS enabled,SUM(CASE WHEN block=1 THEN 1 ELSE 0 END) AS blocked,"
            ."SUM(CASE WHEN activation<>'' AND activation<>'0' THEN 1 ELSE 0 END) AS pending_activation,"
            .'SUM(CASE WHEN registerDate>='.$date.' THEN 1 ELSE 0 END) AS registered_30_days FROM #__users';
        return array_map('intval',$this->database->setQuery($sql)->loadAssoc());
    }
    private function languages(): array
    {
        $list=$this->list('SELECT title,lang_code,published FROM #__languages ORDER BY lang_id','SELECT COUNT(*) FROM #__languages',static fn($r)=>['title'=>$r['title'],'lang_code'=>$r['lang_code'],'published'=>(bool)$r['published']]);
        $params=json_decode($this->database->setQuery("SELECT params FROM #__extensions WHERE type='component' AND element='com_languages'")->loadResult()??'{}',true);
        $installed=$this->database->setQuery("SELECT element FROM #__extensions WHERE type='language' ORDER BY element",0,300)->loadColumn();
        return $list+['site_default'=>$params['site']??null,'administrator_default'=>$params['administrator']??null,'installed'=>array_values(array_unique($installed))];
    }
    private function scheduler(): array
    {
        $list=$this->list('SELECT title,type,state,last_exit_code,last_execution,next_execution,times_executed,times_failed FROM #__scheduler_tasks ORDER BY id','SELECT COUNT(*) FROM #__scheduler_tasks',static function($r){
            $result=['title'=>$r['title'],'type'=>$r['type'],'last_execution'=>self::date($r['last_execution']),'next_execution'=>self::date($r['next_execution'])];
            foreach(['state','last_exit_code','times_executed','times_failed'] as $key)$result[$key]=$r[$key]===null?null:(int)$r[$key];
            return $result;
        });
        return $list+['enabled'=>$this->count('SELECT COUNT(*) FROM #__scheduler_tasks WHERE state=1'),'disabled'=>$this->count('SELECT COUNT(*) FROM #__scheduler_tasks WHERE state=0'),'locked'=>$this->count('SELECT COUNT(*) FROM #__scheduler_tasks WHERE locked IS NOT NULL')];
    }
    private function storage(): array
    {
        if($this->root===''||!is_dir($this->root))throw new \RuntimeException('not_available');
        $total=@disk_total_space($this->root);$free=@disk_free_space($this->root);
        if($total===false||$free===false)throw new \RuntimeException('not_available');
        return ['total_bytes'=>(int)$total,'free_bytes'=>(int)$free];
    }
}
