<?php
declare(strict_types=1);
namespace Nicode\Maintenance;
require_once __DIR__ . '/DatabaseSnapshot.php';

/** Local provider: ALL writers and DDL must stay stopped across every step.
 * This is not a transaction spanning requests. Private chunks contain sensitive data.
 * @package Nicode.joomla.webmonitor
 */
final class ResumableDatabase
{
    public const ROWS = 128;
    public const CHUNK_BYTES = 1048576;
    public const MAX_BYTES = 134217728;
    public const MAX_CHUNKS = 8192;

    public function __construct(private readonly string $storage) {
        if (!is_dir($storage) || is_link($storage)) { throw new \RuntimeException('Private storage required'); }
    }

    private static function ident(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
    private static function line(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR) . "\n"; }

    private static function catalog(\mysqli $db): array {
        $tables = [];
        foreach ($db->query('SHOW FULL TABLES')->fetch_all(MYSQLI_NUM) as $row) {
            if ($row[1] !== 'BASE TABLE' || count($tables) >= 512) { throw new \RuntimeException('Unsupported catalog'); }
            $tables[] = $row[0];
        }
        if (!$tables) { throw new \RuntimeException('Empty database'); }
        sort($tables, SORT_STRING);
        foreach (['SHOW TRIGGERS', 'SHOW EVENTS', 'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()',
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA<>DATABASE()'] as $sql) {
            if ($db->query($sql)->num_rows) { throw new \RuntimeException('Unsupported auxiliary object'); }
        }
        foreach ($db->query('SHOW TABLE STATUS')->fetch_all(MYSQLI_ASSOC) as $row) {
            if (!in_array($row['Engine'], ['InnoDB', 'MEMORY'], true)) { throw new \RuntimeException('Unsupported engine'); }
        }
        return $tables;
    }

    private static function schema(\mysqli $db, string $table): array {
        $name = self::ident($table); $columns = []; $primary = []; $nullable = []; $unique = [];
        foreach ($db->query('SHOW FULL COLUMNS FROM ' . $name)->fetch_all(MYSQLI_ASSOC) as $column) {
            if (str_contains($column['Extra'], 'GENERATED')) { throw new \RuntimeException('Generated column unsupported'); }
            $columns[] = $column['Field'];
            $nullable[$column['Field']] = $column['Null'] === 'YES';
        }
        foreach ($db->query('SHOW INDEX FROM ' . $name)->fetch_all(MYSQLI_ASSOC) as $index) {
            if ($index['Key_name'] === 'PRIMARY') { $primary[(int) $index['Seq_in_index']] = $index['Column_name']; }
            if ((int) $index['Non_unique'] === 0) { $unique[$index['Key_name']][(int) $index['Seq_in_index']] = $index['Column_name']; }
        }
        if (!$primary) {
            ksort($unique);
            foreach ($unique as $key) {
                if (!array_filter($key, static fn($column) => $column === null || ($nullable[$column] ?? true))) { $primary = $key; break; }
            }
        }
        ksort($primary);
        return ['table' => $table, 'schema' => base64_encode($db->query('SHOW CREATE TABLE ' . $name)->fetch_row()[1]),
            'columns' => $columns, 'primary' => array_values($primary)];
    }

    private static function save(string $path, array $s): void {
        $data = json_encode($s, JSON_THROW_ON_ERROR); $f = fopen($path . '.next', 'wb');
        if (!$f) { throw new \RuntimeException('Cannot checkpoint'); }
        try {
            if (fwrite($f, $data) !== strlen($data) || !fflush($f) || !fsync($f)) { throw new \RuntimeException('Cannot persist'); }
        } finally { fclose($f); }
        if (!rename($path . '.next', $path)) { throw new \RuntimeException('Cannot commit checkpoint'); }
    }

    /** One schema check or <=128 rows/1 MiB of encoded output per call.
     * OFFSET scans may grow; bounded output is NOT a hard database-time bound.
     */
    public function step(\mysqli $db, string $id, bool $quiesced): array {
        if (!$quiesced || !preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)) { throw new \RuntimeException('Identity and quiescence required'); }
        $identity = $db->query('SELECT DATABASE(), @@hostname, @@port, @@SESSION.sql_mode, @@character_set_connection')->fetch_row();
        if (!$identity[0] || $identity[4] !== 'utf8mb4') { throw new \RuntimeException('Database and utf8mb4 connection required'); }
        if ($db->query("SELECT SHA2('nwm',256)")->fetch_row()[0] !== hash('sha256', 'nwm')) { throw new \RuntimeException('SHA-256 database support required'); }
        $dir = $this->storage . '/' . $id;
        if (!is_dir($dir) && !@mkdir($dir, 0700)) { throw new \RuntimeException('Cannot reserve'); }
        if (is_link($dir)) { throw new \RuntimeException('Linked storage'); }
        $lock = fopen($dir . '/lock', 'c+b');
        if (!$lock) { throw new \RuntimeException('Cannot lock'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Operation busy'); }
            $path = $dir . '/state.json';
            clearstatcache();
            if (is_file($path)) {
                if (is_link($path) || filesize($path) > 4194304) { throw new \RuntimeException('Invalid checkpoint'); }
                $s = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                if ($s['identity'] !== $identity || !in_array($s['phase'], ['schema', 'capture', 'verify', 'complete'], true)) {
                    throw new \RuntimeException('Identity or checkpoint conflict');
                }
            } else {
                if (file_exists($dir . '/bound')) { throw new \RuntimeException('Initialization uncertain'); }
                $f = fopen($dir . '/bound', 'xb'); if (!$f) { throw new \RuntimeException('Cannot bind'); } fclose($f);
                $s = ['identity' => $identity, 'tables' => self::catalog($db), 'schemas' => [], 'phase' => 'schema',
                    'table' => 0, 'offset' => 0, 'cursor' => 0, 'chunks' => [], 'bytes' => 0, 'rows' => 0];
                self::save($path, $s);
            }
            if ($s['phase'] === 'complete') { return self::receipt($id, $s); }
            if (self::catalog($db) !== $s['tables']) { throw new \RuntimeException('Catalog changed'); }
            $table = $s['tables'][$s['table']]; $schema = self::schema($db, $table);
            if ($s['phase'] === 'schema') {
                $s['schemas'][] = $schema;
                if (++$s['table'] === count($s['tables'])) { $s['phase'] = 'capture'; $s['table'] = 0; }
            } else {
                if ($schema !== $s['schemas'][$s['table']]) { throw new \RuntimeException('Schema changed'); }
                $bytes = '';
                if ($s['table'] === 0 && $s['offset'] === 0) { $bytes .= self::line(['format' => 'nwm-db-1', 'sql_mode' => $identity[3]]); }
                if ($s['offset'] === 0) { $record = $schema; unset($record['primary']); $bytes .= self::line($record); }
                if (strlen($bytes) > self::CHUNK_BYTES) { throw new \RuntimeException('Schema exceeds chunk budget'); }
                // Joomla includes tables without unique keys. Order their complete row by
                // per-column binary SHA-256 (NULL remains distinct). Identical rows are
                // interchangeable; this relies on the same collision assumption as chunks.
                $order = $schema['primary'] ? array_map(self::ident(...), $schema['primary'])
                    : array_map(static fn($column) => 'SHA2(CAST(' . self::ident($column) . ' AS BINARY),256)', $schema['columns']);
                $sql = 'SELECT * FROM ' . self::ident($table) . ' ORDER BY ' . implode(',', $order)
                    . ' LIMIT ' . self::ROWS . ' OFFSET ' . (int) $s['offset'];
                $result = $db->query($sql, MYSQLI_USE_RESULT); $rows = 0;
                try {
                    while ($row = $result->fetch_row()) {
                        $line = self::line(['row' => array_map(static fn($v) => $v === null ? null : base64_encode((string) $v), $row)]);
                        if (strlen($bytes) + strlen($line) > self::CHUNK_BYTES) {
                            if (!$rows) { throw new \RuntimeException('Single row or schema exceeds chunk budget'); }
                            break;
                        }
                        $bytes .= $line; $rows++;
                    }
                } finally { $result->free(); }
                $digest = hash('sha256', $bytes); $chunk = $dir . '/chunk-' . $s['cursor'];
                if ($s['phase'] === 'capture') {
                    if (count($s['chunks']) >= self::MAX_CHUNKS || $s['bytes'] + strlen($bytes) > self::MAX_BYTES) {
                        throw new \RuntimeException('Snapshot capacity exceeded');
                    }
                    // An orphan chunk can only be adopted after reproducing exactly the same bytes.
                    if (file_exists($chunk)) {
                        if (is_link($chunk) || !hash_equals($digest, hash_file('sha256', $chunk))) { throw new \RuntimeException('Uncommitted chunk conflict'); }
                    } else {
                        $f = fopen($chunk, 'xb'); if (!$f) { throw new \RuntimeException('Cannot reserve chunk'); }
                        try {
                            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) { throw new \RuntimeException('Cannot persist chunk'); }
                        } finally { fclose($f); }
                    }
                    $s['chunks'][] = $digest; $s['bytes'] += strlen($bytes); $s['rows'] += $rows;
                } elseif (!isset($s['chunks'][$s['cursor']]) || !hash_equals($s['chunks'][$s['cursor']], $digest)
                    || is_link($chunk) || !is_file($chunk) || !hash_equals($digest, hash_file('sha256', $chunk))) {
                    throw new \RuntimeException('Source data or captured chunk changed');
                }
                $s['cursor']++; $s['offset'] += $rows;
                // Only an empty page establishes end-of-table, also when byte budget truncates a page.
                if ($rows === 0) { $s['table']++; $s['offset'] = 0; }
                if ($s['table'] === count($s['tables'])) {
                    if ($s['phase'] === 'verify') {
                        if ($s['cursor'] !== count($s['chunks'])) { throw new \RuntimeException('Chunk count changed'); }
                        $s['phase'] = 'complete';
                    } else { $s['phase'] = 'verify'; $s['table'] = 0; $s['offset'] = 0; $s['cursor'] = 0; }
                }
            }
            self::save($path, $s);
            return self::receipt($id, $s);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function receipt(string $id, array $s): array {
        return ['operation' => $id, 'status' => $s['phase'], 'tables' => count($s['tables']), 'rows' => $s['rows'],
            'chunks' => count($s['chunks']), 'bytes' => $s['bytes'], 'restoration_verified' => false,
            'manifest_sha256' => $s['phase'] === 'complete' ? hash('sha256', self::line($s['chunks'])) : null];
    }

    /** Offline assembly for the existing trusted-local DatabaseSnapshot restore.
     * Synchronous, separately named output; never serves or exposes database data over HTTP.
     */
    public function export(string $id, string $outputId): array {
        foreach ([$id, $outputId] as $value) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $value)) { throw new \RuntimeException('Invalid identity'); }
        }
        $dir = $this->storage . '/' . $id;
        if (is_link($dir) || is_link($dir . '/state.json')) { throw new \RuntimeException('Linked source'); }
        $s = json_decode(file_get_contents($dir . '/state.json'), true, 32, JSON_THROW_ON_ERROR);
        if ($s['phase'] !== 'complete') { throw new \RuntimeException('Incomplete capture'); }
        $out = $this->storage . '/' . $outputId;
        if (!mkdir($out, 0700)) { throw new \RuntimeException('Output already exists'); }
        $f = fopen($out . '/database.jsonl', 'xb'); $bytes = 0;
        if (!$f) { throw new \RuntimeException('Cannot export'); }
        try {
            foreach ($s['chunks'] as $index => $hash) {
                $chunk = $dir . '/chunk-' . $index;
                if (is_link($chunk) || !is_file($chunk) || filesize($chunk) > self::CHUNK_BYTES) { throw new \RuntimeException('Invalid chunk'); }
                $data = file_get_contents($chunk);
                if (!hash_equals($hash, hash('sha256', $data)) || fwrite($f, $data) !== strlen($data)) { throw new \RuntimeException('Chunk integrity or output failure'); }
                $bytes += strlen($data);
            }
            if ($bytes !== $s['bytes'] || !fflush($f) || !fsync($f)) { throw new \RuntimeException('Export incomplete'); }
        } finally { fclose($f); }
        $record = ['status' => 'captured', 'operation' => $outputId, 'format' => 'nwm-db-1',
            'sha256' => hash_file('sha256', $out . '/database.jsonl'), 'bytes' => $bytes,
            'tables' => count($s['tables']), 'rows' => $s['rows'], 'restoration_verified' => false];
        self::save($out . '/completed.json', $record);
        return $record;
    }
}
