<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Connection;

final class PublicAddress
{
    public function __construct(private ?\Closure $lookup = null) {}

    public static function url(string $url): string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('invalid_url');
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (!$parts || ($parts['scheme'] ?? '') !== 'https'
            || array_intersect(['user','pass','query','fragment'], array_keys($parts))
            || strlen($host) > 253
            || !preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z][a-zA-Z0-9-]{0,62}$/D', $host)
            || !preg_match('~^(?:/[a-zA-Z0-9_-]+)*/?$~D', $parts['path'] ?? '')
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new \InvalidArgumentException('invalid_url');
        }
        return 'https://' . strtolower($host) . (isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
    }

    /** Validate every answer, then pin the chosen address for the actual request. */
    public function resolve(string $url): string
    {
        $host = parse_url(self::url($url), PHP_URL_HOST);
        $pending=[$host]; $seen=[]; $addresses=[]; $count=0;
        while ($pending) {
            $host=array_shift($pending);
            if (isset($seen[$host])) continue;
            if (count($seen)>=8) throw new \RuntimeException('dns_failed');
            $seen[$host]=true;
            $records = $this->lookup ? ($this->lookup)($host) : @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
            foreach ($records ?: [] as $record) {
                if (++$count>64) throw new \RuntimeException('dns_failed');
                if (($record['type']??'')==='CNAME') {
                    $alias=rtrim((string)($record['target']??''),'.');
                    $pending[]=parse_url(self::url('https://'.$alias),PHP_URL_HOST);
                    continue;
                }
                $address = $record['ip'] ?? $record['ipv6'] ?? '';
                if ($address === '') continue;
                if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                    throw new \RuntimeException('public_address_required');
                }
                $addresses[] = $address;
            }
        }
        if (!$addresses || count($addresses) > 32) throw new \RuntimeException('dns_failed');
        // Prefer IPv4 for shared hosting; IPv6 remains supported.
        usort($addresses, static fn($a, $b) => [str_contains($a, ':'), $a] <=> [str_contains($b, ':'), $b]);
        return $addresses[0];
    }
}
