<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\System\NicodeWebMonitor\Transport;

final class Endpoint
{
    public function __construct(private string $siteId, private string $tokenHash)
    {
    }

    /** No collector runs until transport, credential and operation are validated. */
    public function handle(array $server, string $task, \Closure $collect): array
    {
        // Do not trust client-supplied Forwarded/X-Forwarded-Proto headers.
        if (!in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['on', '1'], true)) {
            return $this->error(403, 'https_required');
        }
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $this->siteId)
            || !preg_match('/^[a-f0-9]{64}$/D', $this->tokenHash)) {
            return $this->error(503, 'connector_unavailable');
        }
        $header = (string) ($server['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/Di', $header, $matches)
            || !hash_equals($this->tokenHash, hash('sha256', $matches[1]))) {
            return $this->error(401, 'unauthorized');
        }
        if (($server['REQUEST_METHOD'] ?? '') !== 'GET') {
            return $this->error(405, 'method_not_allowed');
        }
        if ($task !== 'inventory') {
            return $this->error(404, 'operation_not_found');
        }
        try {
            $inventory = $collect();
            $inventory['execution_context'] = 'remote_https';
            $inventory['capabilities']['remote_inventory'] = true;
            return [200, ['protocol_version' => 1, 'site_id' => $this->siteId, 'inventory' => $inventory]];
        } catch (\Throwable) {
            return $this->error(503, 'collection_failed');
        }
    }

    private function error(int $status, string $code): array
    {
        return [$status, ['protocol_version' => 1, 'error' => $code]];
    }
}
