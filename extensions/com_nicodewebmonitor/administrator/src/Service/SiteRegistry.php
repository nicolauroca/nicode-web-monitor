<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\User\User;
final class SiteRegistry
{
    public function __construct(private DatabaseInterface $db, private CredentialVault $vault, private User $user) {}
    private function permit(string $action): void
    {
        if (!$this->user->authorise('core.manage', 'com_nicodewebmonitor') || !$this->user->authorise($action, 'com_nicodewebmonitor')) {
            throw new \RuntimeException('Access denied', 403);
        }
    }
    public function all(): array
    {
        $this->permit('core.manage');
        $sites = $this->db->setQuery('SELECT id, site_id, label, base_url, address FROM #__nwm_sites ORDER BY id')->loadAssocList();
        $history = new CheckHistory($this->db);
        foreach ($sites as &$site) {
            $site['checks'] = $history->recent((int)$site['id']);
            $site['check_state'] = CheckHistory::state($site['checks'][0] ?? null);
        }
        return $sites;
    }
    public function add(string $label, string $url, string $address, string $siteId, #[\SensitiveParameter] string $token): void
    {
        $this->permit('core.create');
        if (trim($label) === '' || strlen($label) > 120 || preg_match('/[\x00-\x1f\x7f]/', $label)
            || (new InventoryClient())->endpoint($url) === null
            || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
            || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $siteId)
            || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Invalid enrollment');
        }
        $row = (object) ['site_id'=>$siteId, 'label'=>trim($label), 'base_url'=>rtrim($url, '/'), 'address'=>$address, 'credential'=>$this->vault->seal($siteId, $token)];
        $this->db->insertObject('#__nwm_sites', $row);
    }
    public function remove(int $id): void
    {
        $this->permit('core.delete');
        $this->db->transactionStart(true);
        try {
            $this->db->setQuery('SELECT id FROM #__nwm_sites WHERE id=' . $id . ' FOR UPDATE')->loadResult();
            $this->db->setQuery('DELETE FROM #__nwm_checks WHERE site=' . $id)->execute();
            $this->db->setQuery('DELETE FROM #__nwm_sites WHERE id=' . $id)->execute();
            $this->db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->db->transactionRollback(true);
            throw $e;
        }
    }

    /** One explicit read; retain only a bounded check summary, not remote inventory. */
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
        $result ??= ($client ?? new InventoryClient())->fetch($row['base_url'], $row['address'], $row['site_id'], $token);
        try {
            (new CheckHistory($this->db))->record($id, $result);
            $result['history_saved'] = true;
        } catch (\Throwable) {
            $result['history_saved'] = false;
        }
        return $result;
    }
}
