<?php
/** @license GPL-2.0-or-later
 * @package Nicode.joomla.webmonitor
 */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

/** One sequenced joint-backup request. Never retries; a failed write requires status reconciliation. */
final class BackupClient
{
    public function __construct(
        private ?string $caFile = null,
        private array $allowedPrivateAddresses = [],
        private int $timeoutMs = 15000,
        private int $maxBytes = 2097152,
    ) {
        if ($timeoutMs < 100 || $timeoutMs > 30000 || $maxBytes < 1024 || $maxBytes > 4194304) {
            throw new \InvalidArgumentException('Invalid client limits');
        }
    }

    /** IP is explicitly enrolled, validated and pinned: DNS cannot change the destination. */
    public function request(string $baseUrl, string $address, string $siteId, #[\SensitiveParameter] string $token, string $operation, ?int $sequence = null, bool $confirmed = false): array
    {
        $sent = false;
        $failure = static function (string $error) use (&$sent, $sequence): array {
            return ['ok'=>false, 'error'=>$error, 'reconcile_required'=>$sent && $sequence !== null];
        };
        if (!preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $operation)
            || ($sequence !== null && (!$confirmed || $sequence < 0 || $sequence > 1000000))) {
            return $failure('invalid_operation');
        }
        if (!extension_loaded('curl')) {
            return $failure('curl_unavailable');
        }
        $url = (new InventoryClient())->endpoint($baseUrl);
        if ($url !== null) { $url = str_replace('task=inventory', 'task=backup_site', $url); }
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
        $request = ['site_id'=>$siteId, 'operation'=>$operation, 'action'=>$sequence === null ? 'status' : 'step'];
        if ($sequence === null) { $url .= '&' . http_build_query($request, '', '&', PHP_QUERY_RFC3986); }
        else { $request += ['sequence'=>$sequence, 'confirmed'=>true]; }
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
            if ($sequence !== null) {
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
            $receipt = $this->receipt($data, $siteId, $operation, $sequence, $status);
            if ($receipt === null) { return $failure('invalid_receipt'); }
            return ['ok'=>true, 'received_at'=>gmdate('Y-m-d\TH:i:s\Z'),
                'reconcile_required'=>$receipt['status'] === 'uncertain', 'receipt'=>$receipt];
        } catch (\Throwable) {
            // Do not disclose remote bodies, URLs, headers, credentials or exception text.
            return $failure('transport_failed');
        } finally {
            curl_close($curl);
        }
    }

    private function receipt(mixed $data, string $siteId, string $operation, ?int $sequence, int $http): ?array
    {
        if (!is_array($data) || ($data['site_id'] ?? null) !== $siteId
            || ($data['operation'] ?? null) !== $operation || ($data['scope'] ?? null) !== 'files_and_database') { return null; }
        $status = $data['status'] ?? null;
        if ($status === 'uncertain') {
            return $http === 202 ? ['site_id'=>$siteId, 'operation'=>$operation, 'scope'=>'files_and_database', 'status'=>'uncertain'] : null;
        }
        if ($http !== 200 || !in_array($status, ['ready','progress','complete'], true)
            || !is_int($data['next_sequence'] ?? null) || $data['next_sequence'] < 0 || $data['next_sequence'] > 1000001
            || ($data['restoration_verified'] ?? null) !== false) { return null; }
        if ($sequence !== null && $data['next_sequence'] !== $sequence + 1) { return null; }
        $result = array_intersect_key($data, array_flip(['site_id','operation','scope','status','next_sequence','restoration_verified']));
        if ($status === 'ready') { return $sequence === null && $data['next_sequence'] === 0 ? $result : null; }
        if (!in_array($data['phase'] ?? null, $status === 'complete' ? ['complete'] : ['files','database','verify'], true)
            || !is_int($data['cursor'] ?? null) || $data['cursor'] < 0 || $data['next_sequence'] < 1
            || !array_key_exists('manifest_sha256', $data)) { return null; }
        $hash = $data['manifest_sha256'];
        if (($hash !== null && (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)))
            || ($status === 'complete' && $hash === null)) { return null; }
        return $result + array_intersect_key($data, array_flip(['phase','cursor','manifest_sha256']));
    }
}
