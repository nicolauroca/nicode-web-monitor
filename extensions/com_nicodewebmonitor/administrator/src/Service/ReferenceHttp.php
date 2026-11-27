<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Nicode\Component\NicodeWebMonitor\Administrator\Connection\PublicAddress;

/** Only two fixed public sources, TLS verified, DNS validated and pinned. No site data. */
final class ReferenceHttp
{
    public function fetch(string $product): string
    {
        $url=ReleaseReference::SOURCES[$product]??throw new \InvalidArgumentException('Unknown reference');
        $host=parse_url($url,PHP_URL_HOST);$ip=(new PublicAddress())->resolve('https://'.$host);
        $resolved=str_contains($ip,':')?'['.$ip.']':$ip;$body='';$headers=0;
        $curl=curl_init($url);
        try{
            curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$resolved],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>3000,CURLOPT_USERAGENT=>'Nicode-Web-Monitor/1.1.1-dev.8',
                CURLOPT_HTTPHEADER=>['Accept: application/json, text/html'],
                CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body){if(strlen($body)+strlen($chunk)>300000)return 0;$body.=$chunk;return strlen($chunk);},
                CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$headers){$headers+=strlen($line);return $headers>32768?0:strlen($line);}]);
            if(curl_exec($curl)===false||(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)throw new \RuntimeException('Reference unavailable');
            return $body;
        }finally{curl_close($curl);}
    }
}
