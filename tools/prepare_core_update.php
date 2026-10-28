<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Pin a local core package; no dispatch or installation. */
if (PHP_SAPI!=='cli' || count($argv)!==6 || $argv[5]!=='--writers-stopped-and-recovery-verified') {
    fwrite(STDERR,"Usage: php prepare_core_update.php JOOMLA_ROOT TRUSTED_UPDATE_ZIP EXPECTED_SHA256 OPERATION --writers-stopped-and-recovery-verified\n");exit(2);
}
try {
    require __DIR__.'/core_cli_bootstrap.php';
    $id=$argv[4];
    if (!preg_match('/^[a-z0-9-]{8,64}$/D',$id)) { throw new RuntimeException('Invalid operation'); }
    // Reuse the gate's overlap checks for private package storage.
    new \Nicode\Maintenance\MaintenanceGate($storage,$root);
    $package=\Nicode\Maintenance\CoreUpdatePreflight::package($argv[2],$argv[3]);
    $before=\Nicode\Maintenance\InstalledCore::inspect();
    $report=\Nicode\Maintenance\CoreUpdatePreflight::assess($before,$package);
    if ($report['status']!=='preflight_passed' || !$before['offline']) { throw new RuntimeException('Preflight blocked'); }
    $base=$storage.'/core';
    if (is_link($base) || (!is_dir($base) && !mkdir($base,0700))) { throw new RuntimeException('Private directory unavailable'); }
    $dir=$base.'/'.$id;
    if (!mkdir($dir,0700)) { throw new RuntimeException('Operation already prepared'); }
    foreach (['journal','snapshot'] as $child) { if (!mkdir($dir.'/'.$child,0700)) { throw new RuntimeException('Directory unavailable'); } }
    if (!copy($argv[2],$dir.'/package.zip') || !hash_equals($argv[3],hash_file('sha256',$dir.'/package.zip'))) { throw new RuntimeException('Package copy mismatch'); }
    $plan=['extension_id'=>$before['extension_id'],'from'=>$before['runtime_version'],'to'=>$package['version'],'package_sha256'=>$argv[3]];
    $permit=['operation'=>$id,'kind'=>'core','writers_stopped'=>true,'recovery_verified'=>true,'expires_at'=>time()+900,
        'root_sha256'=>hash('sha256',strtolower(str_replace('\\','/',$root))),'plan'=>$plan];
    $f=fopen($dir.'/permit.json','x');
    if (!$f) { throw new RuntimeException('Permit unavailable'); }
    try {
        $bytes=json_encode($permit,JSON_THROW_ON_ERROR);
        if (fwrite($f,$bytes)!==strlen($bytes) || !fflush($f) || !fsync($f)) { throw new RuntimeException('Permit not durable'); }
    } finally { fclose($f); }
    echo json_encode(['operation'=>$id,'plan'=>$plan,'expires_at'=>$permit['expires_at'],'installed'=>false,'queued'=>false,
        'recovery'=>'operator_attestation_only'],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) { fwrite(STDERR,"Core preparation failed; no installation attempted. Inspect local prerequisites and private storage.\n");exit(2); }
