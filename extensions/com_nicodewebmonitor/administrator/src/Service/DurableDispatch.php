<?php
/** @package Nicode.joomla.webmonitor @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Joomla\Database\DatabaseInterface;

final class DurableDispatch
{
    /** Require autocommit and no active transaction, without committing caller work. */
    public static function ready(DatabaseInterface $db): bool
    {
        try {
            if ((int)$db->setQuery('SELECT @@autocommit')->loadResult() !== 1) { return false; }
            // Both MySQL and MariaDB reject SET TRANSACTION inside an active transaction.
            // This sets the NEXT journal transaction to read-write, not global/session defaults.
            // Unlike @@in_transaction (MariaDB only), it needs no diagnostic-table privilege.
            $db->setQuery('SET TRANSACTION READ WRITE')->execute();
            return true;
        } catch (\Throwable) { return false; }
    }
}
