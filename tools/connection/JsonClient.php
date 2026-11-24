<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Connection;

final class JsonClient
{
    public function __construct(private ?string $caFile = null, private array $testAddresses = []) {}

    public function post(string $base, string $address, #[\SensitiveParameter] array $payload): array
    {
        $base = PublicAddress::url($base);
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) && !in_array($address, $this->testAddresses, true)) {
            throw new \RuntimeException('public_address_required');
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($body) > 4096) throw new \InvalidArgumentException('request_too_large');
        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT) ?: 443;
        $address = str_contains($address, ':') ? '['.$address.']' : $address;
        $response = ''; $headers = 0;
        $curl = curl_init($base.'/index.php?option=com_nicodewebmonitor&task=connection.claim');
        try {
            $options = [CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0, CURLOPT_PROXY=>'', CURLOPT_RESOLVE=>["$host:$port:$address"],
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_CONNECTTIMEOUT_MS=>5000, CURLOPT_TIMEOUT_MS=>20000,
                CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
                CURLOPT_WRITEFUNCTION=>static function($c, $chunk) use (&$response) {
                    if (strlen($response)+strlen($chunk)>8192) return 0;
                    $response.=$chunk; return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION=>static function($c, $line) use (&$headers) {
                    $headers+=strlen($line); return $headers>32768 ? 0 : strlen($line);
                }];
            if ($this->caFile !== null) $options[CURLOPT_CAINFO]=$this->caFile;
            if (!curl_setopt_array($curl,$options) || curl_exec($curl) === false) {
                throw new \RuntimeException(match(curl_errno($curl)) {
                    60,77=>'tls_verification_failed', CURLE_OPERATION_TIMEDOUT=>'connection_uncertain', default=>'connection_failed'});
            }
            $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($status>=300 && $status<400) throw new \RuntimeException('redirect_rejected');
            if ($status===401) throw new \RuntimeException('central_auth_required');
            if ($status===403) throw new \RuntimeException('central_access_denied');
            if ($status===404) throw new \RuntimeException('central_component_missing');
            if ($status>=500) throw new \RuntimeException('central_server_error');
            $type=(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE);
            if (strtolower(trim(explode(';',$type)[0]))!=='application/json') throw new \RuntimeException('invalid_response');
            try { $data=json_decode($response,true,8,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new \RuntimeException('invalid_response'); }
            if ($status!==200 || !is_array($data) || ($data['ok']??false)!==true) {
                $error=$data['error']??'';
                throw new \RuntimeException(in_array($error,['code_unavailable','verification_failed'],true) ? $error : 'connection_failed');
            }
            return $data;
        } finally { curl_close($curl); }
    }
}
