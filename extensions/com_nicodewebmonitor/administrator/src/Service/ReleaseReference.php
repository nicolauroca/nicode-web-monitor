<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

/** Public upstream facts, independent of the sites and their inventory. */
final class ReleaseReference
{
    public const SOURCES=['php'=>'https://www.php.net/releases/branches.php','joomla'=>'https://developer.joomla.org/roadmap.html'];
    public static function parse(string $product,string $body,int $now): array
    {
        $branches=[];
        if($product==='php'){
            $rows=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($rows)||!array_is_list($rows)||count($rows)>100)throw new \UnexpectedValueException('Invalid reference');
            foreach($rows as $row){
                if(!is_array($row)||!in_array($row['state']??'',['stable','security','eol'],true))continue;
                $key=$row['branch']??'';$latest=$row['latest']??'';
                if(!is_string($key)||!preg_match('/^\d+\.\d+$/D',$key)||!self::version($latest)||!str_starts_with($latest,$key.'.'))continue;
                $entry=self::entry($latest,$row['initial_release']??null,$row['active_support_end']??null,$row['security_support_end']??null);
                if($entry&&strtotime($entry['released'].' UTC')<=$now)$branches[$key]=$entry;
            }
        }elseif($product==='joomla'){
            $document=new \DOMDocument();$previous=libxml_use_internal_errors(true);
            try{$loaded=$document->loadHTML($body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
            finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
            if(!$loaded)throw new \UnexpectedValueException('Invalid reference');
            $xpath=new \DOMXPath($document);
            foreach($xpath->query('//table') as $table){
                $facts=[];
                foreach($xpath->query('.//tr',$table) as $tr){
                    $heading=$xpath->query('./th',$tr)->item(0);$value=$xpath->query('./td',$tr)->item(0);
                    if($heading&&$value)$facts[trim(preg_replace('/\s+/',' ',$heading->textContent))]=trim($value->textContent);
                }
                $latest=$facts['Current Release']??'';
                if(!self::version($latest))continue;
                $major=explode('.',$latest)[0];
                $entry=self::entry($latest,$facts['Release Date of Series']??null,$facts['End of regular bugfix Support for '.$major.'.x']??null,$facts['End of security fix only Support for '.$major.'.x']??null);
                if($entry&&strtotime($entry['released'].' UTC')<=$now)$branches[$major]=$entry;
            }
        }else{throw new \InvalidArgumentException('Unknown reference');}
        if(!$branches)throw new \UnexpectedValueException('Reference unavailable');
        return ['checked_at'=>$now,'branches'=>$branches];
    }
    public static function version(mixed $version): bool
    {
        return is_string($version)&&strlen($version)<32&&preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,4}$/D',$version)===1;
    }
    private static function entry(string $latest,mixed $released,mixed $active,mixed $security): ?array
    {
        $dates=[];
        foreach([$released,$active,$security] as $raw){
            if(!is_string($raw)||strlen($raw)>40)return null;
            $date=false;
            foreach(['!Y-m-d\TH:i:sP','!j F Y','!Y-m-d'] as $format){
                $candidate=\DateTimeImmutable::createFromFormat($format,$raw,new \DateTimeZone('UTC'));
                $errors=\DateTimeImmutable::getLastErrors();
                if($candidate&&(!$errors||(!$errors['warning_count']&&!$errors['error_count']))){$date=$candidate;break;}
            }
            if(!$date)return null;$dates[]=$date->format('Y-m-d');
        }
        if(!($dates[0]<=$dates[1]&&$dates[1]<=$dates[2]))return null;
        return ['latest'=>$latest,'released'=>$dates[0],'active_until'=>$dates[1],'security_until'=>$dates[2]];
    }
}
