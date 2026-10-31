<?php
declare(strict_types=1);
namespace Nicode\Plugin\System\NicodeWebMonitor\Transport;

/** @package Nicode.joomla.webmonitor
 * Remote callers select a locally prepared operation, never a package path or URL.
 */
final class UpdateEndpoint
{
    public function __construct(private string $siteId, private string $tokenHash,
        private array $otherHashes, private string $scope = 'extension_update') {
        if (!in_array($scope, ['extension_update','core_update'], true)) { throw new \InvalidArgumentException('Invalid scope'); }
    }

    public function handle(array $server, array $query, string $body, \Closure $provider): array
    {
        if (!in_array(strtolower((string)($server['HTTPS'] ?? '')), ['on','1'], true)) {
            return [403, ['error'=>'https_required']];
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $this->tokenHash)
            || in_array($this->tokenHash, $this->otherHashes, true)) {
            return [503, ['error'=>'update_disabled']];
        }
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/Di', (string)($server['HTTP_AUTHORIZATION'] ?? ''), $m)
            || !hash_equals($this->tokenHash, hash('sha256', $m[1]))) {
            return [401, ['error'=>'unauthorized']];
        }
        $method = $server['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['GET','POST'], true)) { return [405, ['error'=>'method_not_allowed']]; }
        if (strlen($body) > 4096) { return [413, ['error'=>'request_too_large']]; }
        unset($query['option'], $query['task']);
        if ($method === 'POST') {
            if ($query) { return [400, ['error'=>'query_not_allowed']]; }
            if (strtolower(trim(explode(';', $server['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
                return [415, ['error'=>'json_required']];
            }
            try { $r = json_decode($body, true, 8, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { return [400, ['error'=>'invalid_json']]; }
        } else {
            if ($body !== '') { return [400, ['error'=>'body_not_allowed']]; }
            $r = $query;
        }
        if (!is_array($r) || array_is_list($r)
            || count($r) !== ($method === 'POST' ? 4 : 3)
            || ($r['site_id'] ?? null) !== $this->siteId
            || !is_string($r['operation'] ?? null)
            || !preg_match('/^[a-z0-9-]{8,64}$/D', $r['operation'])
            || ($r['action'] ?? null) !== ($method === 'POST' ? 'update' : 'status')
            || ($method === 'POST' && ($r['confirmed'] ?? null) !== true)) {
            return [400, ['error'=>'invalid_request']];
        }
        try {
            $receipt = $provider($r['operation'], $method === 'POST');
            if (!in_array($receipt['status'] ?? null, $this->scope === 'core_update'
                ? ['queued','uncertain','verified'] : ['uncertain','updated'], true)) {
                throw new \RuntimeException('Invalid provider state');
            }
            return [in_array($receipt['status'], ['updated','verified'], true) ? 200 : 202,
                ['site_id'=>$this->siteId, 'operation'=>$r['operation'], 'scope'=>$this->scope]
                + array_intersect_key($receipt, array_flip(['status','plan','installed_extension_id','compound']))];
        } catch (\OutOfBoundsException) { return [404, ['error'=>'operation_unknown']]; }
        catch (\Throwable) { return [409, ['error'=>'update_not_confirmed']]; }
    }
}
