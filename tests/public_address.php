<?php
/** No Joomla or network required. */
require __DIR__.'/../tools/connection/PublicAddress.php';
use Nicode\Connection\PublicAddress;
$passed=0;
function check($ok){global $passed;if(!$ok)throw new RuntimeException('Address policy failed');$passed++;}
function rejects($fn){try{$fn();}catch(Throwable){check(true);return;}check(false);}
foreach(['http://example.org','https://127.0.0.1','https://2130706433','https://[::1]','https://user:pass@example.org','https://example.org?secret=x','https://example.org/#x','https://example.org/../admin','https://example.org\\@internal','https://example.org/%2fadmin'] as $url) rejects(fn()=>PublicAddress::url($url));
check(PublicAddress::url('https://EXAMPLE.org:443/joomla/')==='https://example.org/joomla');
check((new PublicAddress(fn()=>[['ip'=>'93.184.216.34']]))->resolve('https://example.org')==='93.184.216.34');
foreach(['127.0.0.1','10.0.0.1','192.168.1.1','169.254.169.254','::1','fc00::1','::ffff:127.0.0.1','0.0.0.0'] as $ip) rejects(fn()=>(new PublicAddress(fn()=>[['ip'=>'93.184.216.34'],['ip'=>$ip]]))->resolve('https://example.org'));
rejects(fn()=>(new PublicAddress(fn()=>[]))->resolve('https://example.org'));
check((new PublicAddress(fn($host)=>$host==='www.example.org' ? [['type'=>'CNAME','target'=>'example.org.']] : [['ip'=>'93.184.216.34']]))->resolve('https://www.example.org')==='93.184.216.34');
check((new PublicAddress(fn()=>[['ipv6'=>'2606:4700:4700::1111']]))->resolve('https://example.org')==='2606:4700:4700::1111');
rejects(fn()=>(new PublicAddress(fn($host)=>[['type'=>'CNAME','target'=>$host]]))->resolve('https://example.org'));
rejects(fn()=>(new PublicAddress(fn($host)=>$host==='example.org' ? [['type'=>'CNAME','target'=>'internal.example.org']] : [['ip'=>'192.168.1.1']]))->resolve('https://example.org'));
echo json_encode(['passed'=>$passed]),"\n";
