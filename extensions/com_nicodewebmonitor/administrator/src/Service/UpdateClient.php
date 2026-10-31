<?php
/** @license GPL-2.0-or-later
 * @package Nicode.joomla.webmonitor
 */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

/** One locally prepared extension-update request. Never retries; a failed write requires status reconciliation. */
final class UpdateClient
{
    public function __construct(
        private ?string $caFile = null,
        private array $allowedPrivateAddresses = [],
        private int $timeoutMs = 15000,
        private int $maxBytes = 2097152,
        private string $scope = 'extension_update',
    ) {
        if ($timeoutMs < 100 || $timeoutMs > 30000 || $maxBytes < 1024 || $maxBytes > 4194304
            || !in_array($scope, ['extension_update','core_update'], true)) {
            throw new \InvalidArgumentException('Invalid client limits');
        }
    }

    /** IP is explicitly enrolled, validated and pinned: DNS cannot change the destination. */
    public function request(string $baseUrl, string $address, string $siteId, #[\SensitiveParameter] string $token, string $operation, bool $execute = false, bool $confirmed = false): array
    {
        $sent = false;
        $failure = static function (string $error) use (&$sent, $execute): array {
            return ['ok'=>false, 'error'=>$error, 'reconcile_required'=>$sent && $execute];
        };
        if (!preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $operation)
            || ($execute && !$confirmed)) {
            return $failure('invalid_operation');
        }
        if (!extension_loaded('curl')) {
            return $failure('curl_unavailable');
        }
        $url = (new InventoryClient())->endpoint($baseUrl);
        if ($url !== null) { $url = str_replace('task=inventory', $this->scope === 'core_update' ? 'task=update_core' : 'task=update_extension', $url); }
        if ($url === null || !filter_var($address, FILTER_VALIDATE_IP)
            || (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
                && !in_array($address, $this->allowedPrivateAddresses, true))
            || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $siteId)
            || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return $failure('invalid_enrollment');
        }
        $host = parse_url($url, PHP_URL_HOST);
        // Hostnames only; removes alternate IP spellings and cURL URL parser ambiguity.
        $port = parse_url($url, PHP_URL_PORT) ?: 443;
        $resolved = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $request = ['site_id'=>$siteId, 'operation'=>$operation, 'action'=>!$execute ? 'status' : 'update'];
        if (!$execute) { $url .= '&' . http_build_query($request, '', '&', PHP_QUERY_RFC3986); }
        else { $request += ['confirmed'=>true]; }
        $body = '';
        $headersSize = 0;
        $tooLarge = false;
        $curl = curl_init($url);
        try {
            $options = [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => ["$host:$port:$resolved"],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs),
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headersSize, &$tooLarge): int {
                    $headersSize += strlen($line);
                    if ($headersSize > 32768) {
                        $tooLarge = true;
                        return 0;
                    }
                    return strlen($line);
                },
            ];
            if ($execute) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($request, JSON_THROW_ON_ERROR);
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
                $options[CURLOPT_HTTPHEADER][] = 'Expect:';
            }
            if ($this->caFile !== null) {
                $options[CURLOPT_CAINFO] = $this->caFile;
            }
            if (!curl_setopt_array($curl, $options)) {
                return $failure('transport_configuration_failed');
            }
            $sent = true;
            $ok = curl_exec($curl);
            $errno = curl_errno($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            if ($tooLarge) {
                return $failure('response_too_large');
            }
            if ($ok === false) {
                return $failure(match ($errno) {
                    CURLE_OPERATION_TIMEDOUT => 'timeout',
                    // Stable libcurl codes; PHP builds do not expose every alias.
                    60, 77 => 'tls_verification_failed',
                    default => 'connection_failed',
                });
            }
            if (!in_array($status, [200, 202], true)) {
                return $failure(match (true) {
                    $status >= 300 && $status < 400 => 'redirect_rejected',
                    $status === 401 || $status === 403 => 'access_denied',
                    $status === 503 => 'connector_unavailable',
                    $status === 404 => 'operation_unknown',
                    $status === 409 => 'operation_conflict',
                    default => 'http_error',
                });
            }
            if (!is_string($type) || strtolower(trim(explode(';', $type)[0])) !== 'application/json') {
                return $failure('invalid_content_type');
            }
            try {
                $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $failure('invalid_json');
            }
            $receipt = $this->receipt($data, $siteId, $operation, $execute, $status);
            if ($receipt === null) { return $failure('invalid_receipt'); }
            return ['ok'=>true, 'received_at'=>gmdate('Y-m-d\TH:i:s\Z'),
                'reconcile_required'=>in_array($receipt['status'], ['uncertain','queued'], true), 'receipt'=>$receipt];
        } catch (\Throwable) {
            // Do not disclose remote bodies, URLs, headers, credentials or exception text.
            return $failure('transport_failed');
        } finally {
            curl_close($curl);
        }
    }

    private function receipt(mixed $data, string $siteId, string $operation, bool $execute, int $http): ?array
    {
        if (!is_array($data) || ($data['site_id'] ?? null) !== $siteId
            || ($data['operation'] ?? null) !== $operation || ($data['scope'] ?? null) !== $this->scope
            || !in_array($data['status'] ?? null, $this->scope === 'core_update' ? ['queued','verified','uncertain'] : ['updated','uncertain'], true)
            || $http !== (in_array($data['status'], ['updated','verified'], true) ? 200 : 202)) { return null; }
        $plan = $data['plan'] ?? null;
        if (!is_array($plan) || !is_int($plan['extension_id'] ?? null) || $plan['extension_id'] < 1
            || !is_string($plan['from'] ?? null) || !is_string($plan['to'] ?? null)
            || strlen($plan['from'])>80 || strlen($plan['to'])>80 || !version_compare($plan['to'], $plan['from'], '>')
            || !is_string($plan['package_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $plan['package_sha256'])) { return null; }
        $extra = [];
        if (array_key_exists('compound', $data)) {
            if ($this->scope !== 'extension_update' || array_key_exists('installed_extension_id',$data)) { return null; }
            try { $extra['compound'] = PackageReceipt::validate($data); }
            catch (\Throwable) { return null; }
        }
        if (array_key_exists('installed_extension_id', $data)) {
            if ($this->scope !== 'extension_update' || $data['status'] !== 'updated'
                || !is_int($data['installed_extension_id']) || $data['installed_extension_id'] < 1) { return null; }
            $extra['installed_extension_id'] = $data['installed_extension_id'];
        }
        return ['site_id'=>$siteId,'operation'=>$operation,'scope'=>$this->scope,'status'=>$data['status'],
            'plan'=>array_intersect_key($plan, array_flip(['extension_id','from','to','package_sha256']))] + $extra;
    }
}
