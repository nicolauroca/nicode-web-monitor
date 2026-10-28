<?php
/** @license GPL-2.0-or-later
 * @package Nicode.joomla.webmonitor
 */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/** Central journal: persist uncertainty before effects and reconcile with a read after failure. */
final class CoreUpdateCoordinator
{
    public function __construct(private DatabaseInterface $db, private CredentialVault $vault,
        private User $user, private ?\Closure $transport = null) {}

    private function permit(string $action = 'nwm.coreupdate'): void
    {
        if ($this->user->guest || $this->user->block
            || !$this->user->authorise('core.manage', 'com_nicodewebmonitor')
            || !$this->user->authorise($action, 'com_nicodewebmonitor')) {
            throw new \RuntimeException('Access denied', 403);
        }
    }

    private function site(int $id): array
    {
        $row = $this->db->setQuery('SELECT * FROM #__nwm_sites WHERE id=' . $id)->loadAssoc();
        if (!$row) { throw new \RuntimeException('Site not found'); }
        return $row;
    }

    /** Secret is separate from inventory and cryptographically bound to its purpose. */
    public function enroll(int $site, #[\SensitiveParameter] string $token): void
    {
        $this->permit(); $this->permit('core.admin');
        $row = $this->site($site);
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)
            || hash_equals($this->vault->open($row['site_id'], $row['credential']), $token)) {
            throw new \InvalidArgumentException('Separate update credential required');
        }
        $backup = $this->db->setQuery('SELECT credential FROM #__nwm_backup_credentials WHERE site='.$site)->loadResult();
        if ($backup && hash_equals($this->vault->open($row['site_id'].':backup', $backup), $token)) {
            throw new \InvalidArgumentException('Separate update credential required');
        }
        $extension = $this->db->setQuery('SELECT credential FROM #__nwm_update_credentials WHERE site='.$site)->loadResult();
        if ($extension && hash_equals($this->vault->open($row['site_id'].':update', $extension), $token)) {
            throw new \InvalidArgumentException('Separate core credential required');
        }
        $sealed = $this->vault->seal($row['site_id'] . ':core-update', $token);
        $this->db->setQuery('INSERT INTO #__nwm_core_update_credentials (site,credential) VALUES (' . $site . ',' . $this->db->quote($sealed)
            . ') ON DUPLICATE KEY UPDATE credential=VALUES(credential)')->execute();
    }

    /** Creating the journal is local only; the first remote step requires another explicit action. */
    public function begin(int $site, string $id, bool $confirmed): string
    {
        $this->permit();
        if (!$confirmed || !preg_match('/^[a-z0-9-]{8,64}$/D', $id)) { throw new \InvalidArgumentException('Confirmation required'); }
        $row = $this->site($site);
        if (!$this->db->setQuery('SELECT site FROM #__nwm_core_update_credentials WHERE site=' . $site)->loadResult()) {
            throw new \RuntimeException('Update credential unavailable');
        }
        $record = (object)['operation'=>$id, 'site'=>$site, 'site_id'=>$row['site_id'], 'status'=>'ready',
            'receipt'=>'', 'updated_at'=>time()];
        $this->db->insertObject('#__nwm_core_update_operations', $record);
        return $id;
    }

    /** Bounded display projection; never return credentials or raw receipts. */
    public function recent(): array
    {
        $this->permit();
        return $this->db->setQuery('SELECT operation,site,status,updated_at FROM #__nwm_core_update_operations ORDER BY updated_at DESC,operation DESC', 0, 100)->loadAssocList();
    }

    public function run(string $operation, bool $advance = false, bool $confirmed = false): array
    {
        $this->permit();
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $operation) || ($advance && !$confirmed)) {
            throw new \InvalidArgumentException('Invalid operation or confirmation');
        }
        $lock = $this->db->quote('nwm-core-update:' . substr(hash('sha256', $this->db->getPrefix() . JPATH_ROOT . $operation), 0, 48));
        if ((int)$this->db->setQuery('SELECT GET_LOCK(' . $lock . ',0)')->loadResult() !== 1) {
            return ['ok'=>false, 'error'=>'operation_busy'];
        }
        try {
            $op = $this->db->setQuery('SELECT * FROM #__nwm_core_update_operations WHERE operation=' . $this->db->quote($operation))->loadAssoc();
            if (!$op) { return ['ok'=>false, 'error'=>'operation_unknown']; }
            if ($advance && !in_array($op['status'], ['ready'], true)) {
                return ['ok'=>false, 'error'=>'reconciliation_required_or_complete'];
            }
            $row = $this->site((int)$op['site']);
            if ($row['site_id'] !== $op['site_id']) { return ['ok'=>false, 'error'=>'identity_mismatch']; }
            $sealed = $this->db->setQuery('SELECT credential FROM #__nwm_core_update_credentials WHERE site=' . (int)$op['site'])->loadResult();
            try { $token = $this->vault->open($row['site_id'] . ':core-update', (string)$sealed); }
            catch (\Throwable) { return ['ok'=>false, 'error'=>'credential_unavailable']; }
            // Autocommit is required: a crash after dispatch must leave the durable uncertain marker.
            if ((int)$this->db->setQuery('SELECT @@in_transaction')->loadResult() !== 0
                || (int)$this->db->setQuery('SELECT @@autocommit')->loadResult() !== 1) {
                return ['ok'=>false, 'error'=>'autocommit_required'];
            }
            if ($advance) { $this->save($operation, 'uncertain', ''); }
            try {
                $args = [$row['base_url'], $row['address'], $row['site_id'], $token, $operation, $advance, $confirmed];
                $result = $this->transport ? ($this->transport)(...$args) : (new UpdateClient(scope: 'core_update'))->request(...$args);
            } catch (\Throwable) { $result = ['ok'=>false, 'error'=>'transport_failed', 'reconcile_required'=>$advance]; }
            if ($result['ok'] ?? false) {
                $receipt = $result['receipt']; // Validated and whitelisted by UpdateClient.
                if ($op['status'] === 'verified' && $receipt['status'] !== 'verified') {
                    return ['ok'=>false,'error'=>'journal_conflict','reconcile_required'=>true];
                }
                $this->save($operation, $receipt['status'], json_encode($receipt, JSON_THROW_ON_ERROR));
            }
            return $result;
        } finally {
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $lock . ')')->loadResult();
        }
    }

    private function save(string $operation, string $status, string $receipt): void
    {
        $this->db->setQuery('UPDATE #__nwm_core_update_operations SET status=' . $this->db->quote($status)
            . ',receipt=' . $this->db->quote($receipt) . ',updated_at=' . time()
            . ' WHERE operation=' . $this->db->quote($operation))->execute();
    }
}
