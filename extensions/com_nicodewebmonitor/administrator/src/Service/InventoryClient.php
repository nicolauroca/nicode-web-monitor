<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

/** Read-only transport. Enrollment/storage/UI are deliberately separate concerns. */
final class InventoryClient
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
    public function fetch(string $baseUrl, string $address, string $siteId, #[\SensitiveParameter] string $token): array
    {
        $failure = static fn (string $error): array => ['ok' => false, 'error' => $error];
        if (!extension_loaded('curl')) {
            return $failure('curl_unavailable');
        }
        $url = $this->endpoint($baseUrl);
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
            if ($this->caFile !== null) {
                $options[CURLOPT_CAINFO] = $this->caFile;
            }
            if (!curl_setopt_array($curl, $options)) {
                return $failure('transport_configuration_failed');
            }
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
            if ($status !== 200) {
                return $failure(match (true) {
                    $status >= 300 && $status < 400 => 'redirect_rejected',
                    $status === 401 || $status === 403 => 'access_denied',
                    $status === 503 => 'connector_unavailable',
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
            if (!is_array($data) || ($data['protocol_version'] ?? null) !== 1) {
                return $failure('unsupported_protocol');
            }
            if (($data['site_id'] ?? null) !== $siteId) {
                return $failure('identity_mismatch');
            }
            if (!$this->validInventory($data['inventory'] ?? null)) {
                return $failure('invalid_inventory');
            }
            return ['ok' => true, 'received_at' => gmdate('Y-m-d\TH:i:s\Z'), 'inventory' => $data['inventory']];
        } catch (\Throwable) {
            // Do not disclose remote bodies, URLs, headers, credentials or exception text.
            return $failure('transport_failed');
        } finally {
            curl_close($curl);
        }
    }

    private function endpoint(string $base): ?string
    {
        if (strlen($base) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $base)) {
            return null;
        }
        $parts = parse_url($base);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))) {
            return null;
        }
        $host = $parts['host'] ?? '';
        if (strlen($host) > 253 || !preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z][a-zA-Z0-9-]{0,62}$/D', $host)) {
            return null;
        }
        $path = $parts['path'] ?? '';
        // Deliberately restrict base paths; enrollment must use the canonical site URL.
        if (!preg_match('~^(?:/[a-zA-Z0-9_-]+)*/?$~D', $path)) {
            return null;
        }
        return rtrim($base, '/') . '/index.php?option=com_nicodewebmonitor&task=inventory';
    }

    private function validInventory(mixed $data): bool
    {
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1
            || !in_array($data['status'] ?? null, ['complete', 'partial'], true)
            || ($data['execution_context'] ?? null) !== 'remote_https'
            || !is_string($data['collected_at'] ?? null)) {
            return false;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['collected_at'], new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $data['collected_at']) {
            return false;
        }
        foreach (['remote_inventory', 'remote_updates', 'remote_backups'] as $capability) {
            if (!is_bool($data['capabilities'][$capability] ?? null)) {
                return false;
            }
        }
        if (!$data['capabilities']['remote_inventory']) {
            return false;
        }
        foreach (['joomla', 'runtime', 'database', 'extensions', 'hosting'] as $section) {
            $value = $data['sections'][$section] ?? null;
            if (!is_array($value) || !array_key_exists('data', $value)
                || !in_array($value['status'] ?? null, ['available', 'unavailable', 'error'], true)
                || ($value['status'] === 'available' && !is_array($value['data']))
                || ($value['status'] !== 'available' && $value['data'] !== null)
                || ($value['status'] === 'error' && $data['status'] !== 'partial')) {
                return false;
            }
        }
        return true;
    }
}
