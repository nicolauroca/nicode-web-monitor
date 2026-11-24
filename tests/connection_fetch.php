<?php
/** TLS transport fixture. Inputs and secrets arrive on stdin; only stable errors leave. */
require __DIR__.'/../tools/connection/PublicAddress.php';
require __DIR__.'/../tools/connection/JsonClient.php';
$d=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
try {
    $receipt=(new Nicode\Connection\JsonClient($d['ca'],['127.0.0.1']))->post($d['url'],'127.0.0.1',['code'=>str_repeat('a',64)]);
    echo json_encode(['ok'=>true,'receipt'=>$receipt]);
} catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
