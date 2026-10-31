<?php
/** @license GPL-2.0-or-later
 * @package Nicode.joomla.webmonitor
 */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

use Joomla\Database\DatabaseInterface;

/** Application append-only observations, committed atomically with the current state.
 * Not a tamper-proof audit log: a database administrator can alter these rows.
 */
final class MaintenanceHistory
{
    private const TABLES = ['backup'=>'#__nwm_backup_operations',
        'update'=>'#__nwm_update_operations', 'core'=>'#__nwm_core_update_operations'];

    public function __construct(private DatabaseInterface $db) {}

    public function write(string $kind, string $operation, int $actor, \Closure $mutation): void
    {
        $table = self::TABLES[$kind] ?? throw new \InvalidArgumentException('Invalid maintenance kind');
        $this->db->transactionStart(true);
        try {
            $mutation();
            $row = $this->db->setQuery('SELECT * FROM '.$table.' WHERE operation='.$this->db->quote($operation))->loadAssoc();
            if (!$row) { throw new \RuntimeException('Operation unavailable'); }
            // No credential, raw receipt, URL or exception text enters the event table.
            $event = (object)['kind'=>$kind, 'operation'=>$operation, 'site'=>(int)$row['site'],
                'site_id'=>$row['site_id'], 'status'=>$row['status'], 'actor_id'=>$actor,
                'next_sequence'=>$kind === 'backup' ? (int)$row['next_sequence'] : null,
                'receipt_sha256'=>hash('sha256', $row['receipt']), 'recorded_at'=>time()];
            $this->db->insertObject('#__nwm_maintenance_events', $event);
            $this->db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->db->transactionRollback(true);
            throw $e;
        }
    }

    /** Called only after the coordinator has enforced its maintenance permission. */
    public function recent(string $kind, string $operation): array
    {
        if (!isset(self::TABLES[$kind])) { throw new \InvalidArgumentException('Invalid maintenance kind'); }
        return $this->db->setQuery('SELECT e.id,e.status,e.actor_id,e.next_sequence,e.recorded_at FROM #__nwm_maintenance_events e'
            .' INNER JOIN '.self::TABLES[$kind].' o ON BINARY o.operation=BINARY e.operation AND o.site=e.site AND BINARY o.site_id=BINARY e.site_id'
            .' WHERE e.kind='.$this->db->quote($kind).' AND e.operation='.$this->db->quote($operation)
            .' ORDER BY e.id DESC', 0, 50)->loadAssocList();
    }
}
