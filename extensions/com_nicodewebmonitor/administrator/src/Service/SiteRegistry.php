<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\User\User;
final class SiteRegistry
{
    /** Client factory is an application/test dependency, never remote or form configuration. */
    public function __construct(private DatabaseInterface $db, private CredentialVault $vault, private User $user, private ?\Closure $clientFactory = null) {}
    private function permit(string $action): void
    {
        if (!$this->user->authorise('core.manage', 'com_nicodewebmonitor') || !$this->user->authorise($action, 'com_nicodewebmonitor')) {
            throw new \RuntimeException('Access denied', 403);
        }
    }
    public function all(): array
    {
        $this->permit('core.manage');
        $sites = $this->db->setQuery('SELECT s.id,s.site_id,s.label,s.base_url,s.address,s.connection_type,i.received_at AS inventory_received_at,i.summary AS inventory_summary FROM #__nwm_sites AS s LEFT JOIN #__nwm_inventory AS i ON i.site=s.id ORDER BY s.id')->loadAssocList();
        $history = new CheckHistory($this->db);
        foreach ($sites as &$site) {
            $site['inventory_summary']=json_decode($site['inventory_summary']??'null',true);
            $retained = $history->recent((int)$site['id'], 100);
            $site['checks'] = array_slice($retained, 0, 10);
            $site['alerts'] = CheckAlerts::summarize($retained);
            $site['check_state'] = CheckHistory::state($site['checks'][0] ?? null);
            $site['notification'] = $this->db->setQuery('SELECT delivery,evaluated_at,attempted_at FROM #__nwm_notifications WHERE site='.(int)$site['id'])->loadAssoc();
        }
        return $sites;
    }
    /** Bounded, oldest-first monitoring. All task definitions share one database lock. */
    public function monitor(int $limit = 5, int $budgetSeconds = 20): array
    {
        $this->permit('nwm.monitor');
        if ($this->user->guest || $this->user->block || $limit < 1 || $limit > 10 || $budgetSeconds < 1 || $budgetSeconds > 30) {
            throw new \InvalidArgumentException('Invalid monitoring configuration');
        }
        $lock = $this->db->quote('nwm:' . substr(hash('sha256', $this->db->getPrefix() . JPATH_ROOT), 0, 48));
        if ((int) $this->db->setQuery('SELECT GET_LOCK(' . $lock . ', 0)')->loadResult() !== 1) {
            return ['status'=>'busy', 'checked'=>0, 'failed'=>0, 'partial'=>0];
        }
        $summary = ['status'=>'complete', 'checked'=>0, 'failed'=>0, 'partial'=>0];
        $deadline = hrtime(true) + $budgetSeconds * 1000000000;
        try {
            $ids = $this->db->setQuery('SELECT s.id FROM #__nwm_sites s LEFT JOIN #__nwm_checks c ON c.site=s.id GROUP BY s.id ORDER BY COALESCE(MAX(c.checked_at),0), COALESCE(MAX(c.id),0), s.id', 0, $limit)->loadColumn();
            foreach ($ids as $id) {
                $remainingMs = (int) (($deadline - hrtime(true)) / 1000000);
                if ($remainingMs < 100) { $summary['status'] = 'budget_exhausted'; break; }
                $timeoutMs = min(3000, $remainingMs);
                $client = $this->clientFactory ? ($this->clientFactory)($timeoutMs) : new InventoryClient(timeoutMs: $timeoutMs);
                $result = $this->inspect((int)$id, $client);
                $summary['checked']++;
                if (!($result['ok'] ?? false) || !($result['history_saved'] ?? false) || ($result['snapshot_saved']??true)===false) { $summary['failed']++; }
                elseif (($result['inventory']['status'] ?? '') !== 'complete') { $summary['partial']++; }
                // Persistence failure must not keep selecting the same site silently forever.
                if (!($result['history_saved'] ?? false) || ($result['snapshot_saved']??true)===false) { $summary['status']='history_failed'; break; }
            }
            return $summary;
        } finally {
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $lock . ')')->loadResult();
        }
    }
    public function add(string $label, string $url, string $address, string $siteId, #[\SensitiveParameter] string $token): void
    {
        $this->permit('core.create');
        self::validateEnrollment($label, $url, $address, $siteId, $token);
        $row = (object) ['site_id'=>$siteId, 'label'=>trim($label), 'base_url'=>rtrim($url, '/'), 'address'=>$address, 'credential'=>$this->vault->seal($siteId, $token)];
        $this->db->insertObject('#__nwm_sites', $row);
    }
    private static function validateEnrollment(string $label, string $url, string $address, string $siteId, #[\SensitiveParameter] string $token): void
    {
        if (trim($label) === '' || strlen($label) > 120 || preg_match('/[\x00-\x1f\x7f]/', $label)
            || (new InventoryClient())->endpoint($url) === null
            || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
            || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $siteId)
            || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Invalid enrollment');
        }
    }
    /** Verify read-only identity before persisting a guided enrollment. No maintenance is enabled. */
    public function pair(string $label, string $url, string $address, string $siteId, #[\SensitiveParameter] string $token): array
    {
        $this->permit('core.create');
        self::validateEnrollment($label, $url, $address, $siteId, $token);
        $client = $this->clientFactory ? ($this->clientFactory)(5000) : new InventoryClient(timeoutMs: 5000);
        $result = $client->fetch($url, $address, $siteId, $token);
        if (($result['ok'] ?? false) !== true) {
            throw new \RuntimeException('Pairing could not verify the connector');
        }
        $this->db->transactionStart(true);
        try {
            $this->add($label, $url, $address, $siteId, $token);
            $id = (int)$this->db->insertid();
            (new CheckHistory($this->db))->record($id, $result);
            (new InventoryStore($this->db))->save($id,$result['inventory']);
            $this->db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->db->transactionRollback(true);
            throw $e;
        }
        return ['id'=>$id, 'status'=>$result['inventory']['status']];
    }

    /** Called only by the installed connector's authenticated local pairing path. */
    public function pairLocal(string $label,string $url,string $siteId,#[\SensitiveParameter] string $token): array
    {
        $this->permit('core.create');
        if (trim($label)==='' || strlen($label)>120 || preg_match('/[\x00-\x1f\x7f]/',$label)
            || (new InventoryClient())->endpoint($url)===null) throw new \InvalidArgumentException('Invalid enrollment');
        $result=(new LocalInventory($this->db))->fetch($siteId,$token);
        if (!($result['ok']??false)) throw new \RuntimeException('verification_failed');
        $row=(object)['site_id'=>$siteId,'label'=>trim($label),'base_url'=>rtrim($url,'/'),'address'=>'',
            'connection_type'=>'local','credential'=>$this->vault->seal($siteId,$token)];
        $this->db->transactionStart(true);
        try {
            $this->db->insertObject('#__nwm_sites',$row,'id');
            (new CheckHistory($this->db))->record((int)$row->id,$result);
            (new InventoryStore($this->db))->save((int)$row->id,$result['inventory']);
            $this->db->transactionCommit(true);
        } catch (\Throwable $e) { $this->db->transactionRollback(true); throw $e; }
        return ['id'=>(int)$row->id,'status'=>$result['inventory']['status']];
    }
    public function remove(int $id): void
    {
        $this->permit('core.delete');
        $this->db->transactionStart(true);
        try {
            $this->db->setQuery('SELECT id FROM #__nwm_sites WHERE id=' . $id . ' FOR UPDATE')->loadResult();
            $this->db->setQuery('DELETE FROM #__nwm_checks WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_inventory WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_notifications WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_backup_credentials WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_backup_operations WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_core_update_credentials WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_core_update_operations WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_update_credentials WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_update_operations WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_sites WHERE id=' . $id)->execute();
            $this->db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->db->transactionRollback(true);
            throw $e;
        }
    }

    public function latest(int $id): array
    {
        $this->permit('core.manage');
        if(!$this->db->setQuery('SELECT id FROM #__nwm_sites WHERE id='.$id)->loadResult())return ['ok'=>false,'error'=>'site_not_found'];
        return (new InventoryStore($this->db))->latest($id);
    }

    /** One explicit read, a bounded history summary and the latest allowlisted inventory. */
    public function inspect(int $id, ?InventoryClient $client = null): array
    {
        $this->permit('core.manage');
        $row = $this->db->setQuery('SELECT * FROM #__nwm_sites WHERE id=' . (int) $id)->loadAssoc();
        if (!$row) {
            return ['ok'=>false, 'error'=>'site_not_found'];
        }
        try {
            $token = $this->vault->open($row['site_id'], $row['credential']);
        } catch (\Throwable) {
            $result = ['ok'=>false, 'error'=>'credential_unavailable'];
        }
        $result ??= ($row['connection_type']??'https')==='local'
            ? (new LocalInventory($this->db))->fetch($row['site_id'],$token)
            : ($client ?? new InventoryClient())->fetch($row['base_url'], $row['address'], $row['site_id'], $token);
        try {
            (new CheckHistory($this->db))->record($id, $result);
            $result['history_saved'] = true;
        } catch (\Throwable) {
            $result['history_saved'] = false;
        }
        if($result['ok']??false){
            try{(new InventoryStore($this->db))->save($id,$result['inventory']);$result['snapshot_saved']=true;}
            catch(\Throwable){$result['snapshot_saved']=false;}
        }
        return $result;
    }
}
