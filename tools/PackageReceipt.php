<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** @package Nicode.joomla.webmonitor. Bounded public package observations, without paths or scripts. */
final class PackageReceipt
{
    public static function project(array $receipt): array
    {
        $plan = $receipt['plan'];
        $before = self::state($plan['before']);
        $after = isset($receipt['observed']) ? self::state($receipt['observed']) : null;
        $targets = $plan['targets'];
        if (count($before['parents']) !== 1 || !is_array($targets) || !array_is_list($targets)
            || !$targets || count($targets)>32) { throw new \RuntimeException('Invalid package receipt'); }
        $clean = [];
        foreach ($targets as $target) {
            if (($target['identity']['type'] ?? '')==='package') { throw new \RuntimeException('Nested package unsupported'); }
            $clean[] = ['identity'=>self::identity($target['identity']), 'version'=>self::version($target['version'])];
        }
        $to = self::version($plan['to']); $parent = $before['parents'][0];
        if (!is_string($plan['package_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$plan['package_sha256'])
            || $parent['identity']['type']!=='package' || !is_string($parent['version'])
            || !version_compare($to,$parent['version'],'>')
            || !in_array($receipt['status'],['updated','uncertain'],true)
            || ($receipt['status']==='updated' && ($after===null || !PackageState::matches($after,$to,$clean)
                || $after['parents'][0]['identity']!==$parent['identity']))) {
            throw new \RuntimeException('Inconsistent package receipt');
        }
        return ['status'=>$receipt['status'], 'plan'=>['extension_id'=>$parent['id'],'from'=>$parent['version'],
            'to'=>$to,'package_sha256'=>$plan['package_sha256']],
            'compound'=>['before'=>$before,'targets'=>$clean,'observed'=>$after]];
    }

    public static function validate(array $data): array
    {
        $compound=$data['compound'];
        $result=self::project(['status'=>$data['status'],'plan'=>['before'=>$compound['before'],
            'targets'=>$compound['targets'],'to'=>$data['plan']['to'],'package_sha256'=>$data['plan']['package_sha256']],
            'observed'=>$compound['observed']]);
        if ($result['plan']!==$data['plan']) { throw new \RuntimeException('Package summary mismatch'); }
        return $result['compound'];
    }

    private static function state(array $state): array
    {
        $result=[]; $ids=[];
        foreach (['parents','children'] as $key) {
            if (!is_array($state[$key]) || !array_is_list($state[$key]) || count($state[$key])>64) {
                throw new \RuntimeException('Invalid observation');
            }
            $result[$key]=[];
            foreach ($state[$key] as $row) {
                if (!is_int($row['id']) || $row['id']<1 || isset($ids[$row['id']])
                    || !is_int($row['package_id']) || $row['package_id']<0 || !is_bool($row['protected'])) {
                    throw new \RuntimeException('Invalid observed row');
                }
                $ids[$row['id']]=true;
                $result[$key][]=['id'=>$row['id'],'identity'=>self::identity($row['identity']),
                    'package_id'=>$row['package_id'],'protected'=>$row['protected'],
                    'version'=>$row['version']===null?null:self::version($row['version'])];
            }
        }
        return $result;
    }

    private static function identity(array $id): array
    {
        if (!in_array($id['type'],['package','plugin','module','component','template','library'],true)
            || !is_string($id['element']) || !preg_match('/^[a-z0-9_]{1,128}$/D',$id['element'])
            || !is_string($id['folder']) || !preg_match('/^[a-z0-9_]{0,128}$/D',$id['folder'])
            || !in_array($id['client_id'],[0,1],true)) { throw new \RuntimeException('Invalid identity'); }
        return array_intersect_key($id,array_flip(['type','element','folder','client_id']));
    }

    private static function version(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[0-9][a-zA-Z0-9.+_-]{0,79}$/D',$v)) { throw new \RuntimeException('Invalid version'); }
        return $v;
    }
}
