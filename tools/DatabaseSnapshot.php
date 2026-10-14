<?php
declare(strict_types=1);

namespace Nicode\Maintenance;

/** Local development provider. No HTTP entry point or implicit maintenance permission. */
final class DatabaseSnapshot
{
    public function __construct(private readonly string $storage, private readonly int $maxBytes = 134217728)
    {
        if (!is_dir($storage) || is_link($storage) || $maxBytes < 1) {
            throw new \RuntimeException('Private storage directory required');
        }
    }

    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function reserve(string $id): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)) {
            throw new \RuntimeException('Invalid operation ID');
        }
        $path = $this->storage . DIRECTORY_SEPARATOR . $id;
        if (!@mkdir($path, 0700)) {
            throw new \RuntimeException('Operation already exists or cannot be reserved; do not retry blindly');
        }
        $this->record($path, ['status' => 'uncertain', 'operation' => $id]);
        return $path;
    }

    private function record(string $path, array $record): void
    {
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temp = $path . '/state.tmp';
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
            throw new \RuntimeException('Cannot persist operation state');
        }
        // Windows cannot atomically replace an existing file using rename in all environments.
        // Keep the initial uncertain state; final evidence is a separate, immutable file.
        $target = $path . ($record['status'] === 'uncertain' ? '/started.json' : '/completed.json');
        if (!rename($temp, $target)) {
            throw new \RuntimeException('Cannot commit operation state');
        }
    }

    private static function objects(\mysqli $db): array
    {
        $tables = [];
        foreach ($db->query('SHOW FULL TABLES')->fetch_all(MYSQLI_NUM) as $row) {
            if ($row[1] !== 'BASE TABLE') {
                throw new \RuntimeException('Views are not supported');
            }
            $tables[] = $row[0];
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    private static function checkAuxiliaryObjects(\mysqli $db): void
    {
        foreach (['SHOW TRIGGERS', 'SHOW EVENTS', 'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()',
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA <> DATABASE()'] as $query) {
            if ($db->query($query)->num_rows !== 0) {
                throw new \RuntimeException('Triggers, events, routines and cross-database references are not supported');
            }
        }
    }

    /** Caller must stop every writer/DDL job; Joomla offline mode alone is insufficient. */
    public function capture(\mysqli $db, string $id, bool $quiesced): array
    {
        if (!$quiesced) {
            throw new \RuntimeException('Explicit writer-quiescence acknowledgement required');
        }
        $tables = self::objects($db);
        if (!$tables) {
            throw new \RuntimeException('Empty source database');
        }
        self::checkAuxiliaryObjects($db);
        foreach ($db->query('SHOW TABLE STATUS')->fetch_all(MYSQLI_ASSOC) as $table) {
            if (!in_array($table['Engine'], ['InnoDB', 'MEMORY'], true)) {
                throw new \RuntimeException('Only InnoDB and quiesced MEMORY tables are supported');
            }
        }
        $path = $this->reserve($id);
        $out = fopen($path . '/database.jsonl', 'xb');
        if (!$out) { throw new \RuntimeException('Cannot create snapshot'); }
        $bytes = 0;
        $rows = 0;
        $write = function (array $record) use ($out, &$bytes): void {
            $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
            $bytes += strlen($line);
            if ($bytes > $this->maxBytes || fwrite($out, $line) !== strlen($line)) {
                throw new \RuntimeException('Snapshot size limit or write failure');
            }
        };
        try {
            $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT | MYSQLI_TRANS_START_READ_ONLY);
            $write(['format' => 'nwm-db-1', 'sql_mode' => $db->query('SELECT @@SESSION.sql_mode')->fetch_row()[0]]);
            foreach ($tables as $table) {
                $name = self::identifier($table);
                $columns = [];
                foreach ($db->query('SHOW FULL COLUMNS FROM ' . $name)->fetch_all(MYSQLI_ASSOC) as $column) {
                    if (str_contains($column['Extra'], 'GENERATED')) {
                        throw new \RuntimeException('Generated columns are not supported');
                    }
                    $columns[] = $column['Field'];
                }
                $schema = $db->query('SHOW CREATE TABLE ' . $name)->fetch_row()[1];
                $write(['table' => $table, 'schema' => base64_encode($schema), 'columns' => $columns]);
                $result = $db->query('SELECT * FROM ' . $name, MYSQLI_USE_RESULT);
                try {
                    while ($row = $result->fetch_row()) {
                        $write(['row' => array_map(static fn($v) => $v === null ? null : base64_encode((string) $v), $row)]);
                        $rows++;
                    }
                } finally { $result->free(); }
            }
            $db->commit();
            if (!fflush($out) || !fsync($out)) { throw new \RuntimeException('Cannot flush snapshot'); }
        } catch (\Throwable $error) {
            $db->rollback();
            throw new \RuntimeException('Capture incomplete; operation remains uncertain');
        } finally { fclose($out); }
        $record = ['status' => 'captured', 'operation' => $id, 'format' => 'nwm-db-1',
            'sha256' => hash_file('sha256', $path . '/database.jsonl'), 'bytes' => $bytes,
            'tables' => count($tables), 'rows' => $rows, 'restoration_verified' => false];
        $this->record($path, $record);
        return $record;
    }

    /** Restore trusted local snapshots only, into a separately provisioned EMPTY database. */
    public function restore(\mysqli $target, string $snapshotId, string $operationId, bool $confirmed): array
    {
        if (!$confirmed || !preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $snapshotId)) {
            throw new \RuntimeException('Explicit isolated-target confirmation required');
        }
        if (self::objects($target)) { throw new \RuntimeException('Target database must be empty'); }
        self::checkAuxiliaryObjects($target);
        $source = $this->storage . '/' . $snapshotId;
        $file = $source . '/database.jsonl';
        $meta = json_decode((string) @file_get_contents($source . '/completed.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($meta['status'] ?? '') !== 'captured' || !is_file($file) || is_link($file)
            || filesize($file) > $this->maxBytes || filesize($file) !== $meta['bytes']
            || !hash_equals($meta['sha256'], hash_file('sha256', $file))) {
            throw new \RuntimeException('Snapshot integrity check failed');
        }
        $path = $this->reserve($operationId);
        $input = fopen($file, 'rb');
        $old = $target->query('SELECT @@SESSION.sql_mode, @@SESSION.foreign_key_checks')->fetch_row();
        $rows = 0; $tables = 0; $statement = null;
        try {
            $header = json_decode((string) fgets($input), true, 512, JSON_THROW_ON_ERROR);
            if (($header['format'] ?? '') !== 'nwm-db-1') { throw new \RuntimeException('Unknown format'); }
            $target->query("SET SESSION sql_mode='" . $target->real_escape_string($header['sql_mode']) . "'");
            $target->query('SET SESSION foreign_key_checks=0');
            while (($line = fgets($input)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (isset($record['table'])) {
                    $statement?->close();
                    $statement = null;
                    $target->query(base64_decode($record['schema'], true));
                    $columns = implode(',', array_map(self::identifier(...), $record['columns']));
                    $parameters = implode(',', array_fill(0, count($record['columns']), '?'));
                    $statement = $target->prepare('INSERT INTO ' . self::identifier($record['table']) . " ($columns) VALUES ($parameters)");
                    $tables++;
                } elseif (array_key_exists('row', $record) && $statement !== null) {
                    $values = array_map(static fn($v) => $v === null ? null : base64_decode($v, true), $record['row']);
                    $statement->bind_param(str_repeat('s', count($values)), ...$values);
                    $statement->execute();
                    $rows++;
                } else { throw new \RuntimeException('Invalid snapshot record'); }
            }
            if ($rows !== $meta['rows'] || $tables !== $meta['tables']) { throw new \RuntimeException('Snapshot count mismatch'); }
        } catch (\Throwable $error) {
            // DDL commits implicitly: never claim rollback or remove a partially restored database.
            throw new \RuntimeException('Restore incomplete; inspect isolated target, no automatic retry');
        } finally {
            $statement?->close();
            fclose($input);
            $target->query("SET SESSION sql_mode='" . $target->real_escape_string($old[0]) . "'");
            $target->query('SET SESSION foreign_key_checks=' . (int) $old[1]);
        }
        $record = ['status' => 'restored', 'operation' => $operationId, 'source' => $snapshotId,
            'sha256' => $meta['sha256'], 'tables' => $tables, 'rows' => $rows,
            'application_verified' => false];
        $this->record($path, $record);
        return $record;
    }
}
