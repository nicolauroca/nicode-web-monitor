<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

/** Explainable review priorities. Unknown or denied information never becomes a green result. */
final class SiteHealth
{
    public static function assess(array $site,array $catalog,?int $now=null): array
    {
        $now??=time();$summary=$site['inventory_summary']??[];$coverage=$summary['coverage']??[];$issues=[];$indicators=[];
        $known=static fn($key)=>($coverage[$key]??null)==='available';
        $unknown=static fn($key)=>self::item('neutral',($coverage[$key]??null)==='denied'?'No compartido':'Sin datos','El Conector controla qué información comparte.',$key);
        foreach(['joomla'=>'version','runtime'=>'php_version'] as $key=>$field){
            $product=$key==='runtime'?'php':'joomla';
            $indicators[$key]=$known($key)?self::software($product,$summary[$key][$field]??null,$catalog[$product]??[], $now):$unknown($key);
        }
        $updates=$summary['updates']??[];
        $cacheAt=$updates['oldest_checked_at']??null;$stamp=is_string($cacheAt)?strtotime($cacheAt):false;
        $pending=$updates['pending_extensions']??null;
        if(!$known('updates'))$indicators['updates']=$unknown('updates');
        elseif(!is_int($pending))$indicators['updates']=self::item('neutral','Actualizar inventario','Se necesita una observación con el recuento de extensiones pendientes.','updates');
        else{
            $core=(int)($updates['pending_core']??0);$uncertain=(int)($updates['unknown_versions']??0);
            $fresh=$stamp!==false&&$stamp<=$now+300&&$stamp>=$now-86400;
            $label=$pending.' extensiones'.($core?' · Joomla':'');
            $detail='Actualizaciones con versión superior a la instalada, sin duplicar extensiones. Entradas originales: '.(int)($updates['total']??0).'.';
            if(!$fresh)$detail.=' La caché de proveedores está antigua o sin fecha: actualizar el inventario no la refresca.';
            if($uncertain)$detail.=' Hay '.$uncertain.' entradas sin versión instalada comparable.';
            if($updates['truncated']??false)$detail.=' Recuento mínimo: la lista está limitada.';
            $indicators['updates']=self::item(($pending||$core)?'warning':($fresh&&!$uncertain&&!($updates['truncated']??false)?'success':'neutral'),$label,$detail,'updates');
            $indicators['updates']['cache_fresh']=$fresh;
        }
        $security=$summary['security']??[];$flags=[];
        foreach(['debug'=>'Depuración Joomla activa','debug_lang'=>'Depuración de idioma activa','display_errors'=>'Errores PHP visibles'] as $field=>$label){if(($security[$field]??null)===true)$flags[]=$label;}
        if(!$known('security'))$indicators['security']=$unknown('security');
        else{
            if(($security['force_ssl']??null)===0)$flags[]='Joomla no obliga a usar HTTPS';
            $complete=isset($security['debug'],$security['debug_lang'],$security['display_errors'],$security['force_ssl']);
            $indicators['security']=self::item($flags?'warning':($complete?'success':'neutral'),$flags?count($flags).' señales':($complete?'Sin señales':'Datos incompletos'),$flags?implode('. ',$flags).'.':'Solo configuración compartida: no es un escaneo de malware ni de vulnerabilidades.','security');
        }
        $storage=$summary['storage']??[];$free=$storage['free_bytes']??null;$total=$storage['total_bytes']??null;
        if(!$known('storage'))$indicators['storage']=$unknown('storage');
        elseif(!is_int($free)||!is_int($total)||$total<=0||$free>$total)$indicators['storage']=self::item('neutral','Sin medición','Capacidad no disponible.','storage');
        else{
            $percent=$free/$total*100;$tone=$percent<=5||$free<104857600?'danger':($percent<=10||$free<1073741824?'warning':'success');
            $display=$percent>0&&$percent<0.1?'<0,1':str_replace('.',',',(string)round($percent,1));
            $indicators['storage']=self::item($tone,$display.'% libre','Sistema de archivos: '.self::bytes($free).' libres de '.self::bytes($total).'. No es la cuota del alojamiento.','storage');
        }
        $scheduler=$summary['scheduler']??[];
        if(!$known('scheduler'))$indicators['scheduler']=$unknown('scheduler');
        elseif(!isset($scheduler['failed_enabled'],$scheduler['locked'],$scheduler['unobserved_enabled']))$indicators['scheduler']=self::item('neutral','Sin evaluación','Actualizar inventario para evaluar las tareas compartidas.','scheduler');
        else{
            $bad=(int)$scheduler['failed_enabled'];$locked=(int)$scheduler['locked'];$unobserved=(int)$scheduler['unobserved_enabled'];$incomplete=$unobserved||($scheduler['truncated']??false);
            $indicators['scheduler']=self::item($bad||$locked?'warning':($incomplete?'neutral':'success'),$bad||$locked?$bad.' fallos · '.$locked.' bloqueadas':($incomplete?'Datos pendientes':'Sin fallos recientes'),'Solo tareas habilitadas con última ejecución fallida. Un bloqueo puede corresponder a una ejecución en curso. Tareas sin resultado: '.$unobserved.'.'.(($scheduler['truncated']??false)?' La lista está limitada.':''),'scheduler');
        }
        $offline=$summary['configuration']['offline']??null;
        $indicators['configuration']=!$known('configuration')?$unknown('configuration'):self::item($offline===true?'warning':($offline===false?'success':'neutral'),$offline===true?'Modo offline':($offline===false?'Sitio online configurado':'Sin datos'),'La configuración de Joomla no acredita disponibilidad pública.','configuration');
        foreach($indicators as $key=>$indicator){if(in_array($indicator['tone'],['warning','danger'],true))$issues[]=['key'=>$key]+$indicator;}
        $fresh=in_array($site['check_state']??'',['recent_complete','recent_partial'],true);
        if(!$fresh)$issues[]=['key'=>'inventory']+self::item(($site['check_state']??'')==='check_failed'?'danger':'warning','Revisar comprobación','La observación del inventario falta, está antigua o su última consulta requiere revisión.','');
        if(!$fresh)foreach($indicators as &$indicator){$indicator['observed_tone']=$indicator['tone'];if($indicator['tone']==='success'){$indicator['tone']='neutral';$indicator['label']='Sin confirmar';}$indicator['detail'].=' Datos de una observación que requiere actualización.';}unset($indicator);
        $critical=count(array_filter($issues,static fn($i)=>$i['tone']==='danger'));
        $evaluated=count(array_filter($indicators,static fn($i)=>$i['tone']!=='neutral'));
        $tone=$critical?'danger':($issues?'warning':($evaluated===count($indicators)?'success':'neutral'));
        return ['tone'=>$tone,'label'=>$critical?$critical.' prioritarias':($issues?count($issues).' para revisar':($evaluated?'Sin incidencias detectadas':'Sin evaluar')),
            'issues'=>$issues,'indicators'=>$indicators,'evaluated'=>$evaluated,'total'=>count($indicators),'critical'=>$critical,'fresh'=>$fresh];
    }
    public static function software(string $product,mixed $version,array $reference,int $now): array
    {
        $category=$product==='php'?'runtime':'joomla';
        if(!ReleaseReference::version($version))return self::item('neutral','Versión no comparable','Versiones preliminares o personalizadas requieren revisión con su proveedor.',$category);
        $parts=explode('.',$version);$branch=$product==='php'?$parts[0].'.'.$parts[1]:$parts[0];$fact=$reference['branches'][$branch]??null;
        $checked=$reference['checked_at']??0;
        if(!is_array($fact)||!is_int($checked)||$checked>$now+300||$checked<$now-604800)return self::item('neutral','Referencia pendiente','El catálogo oficial falta o tiene más de siete días. Actualiza las referencias.',$category);
        $active=$fact['active_until']??'';$security=$fact['security_until']??'';$latest=$fact['latest']??null;
        if(!ReleaseReference::version($latest)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$active)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$security))return self::item('neutral','Referencia no válida','No se puede evaluar esta versión.',$category);
        $today=gmdate('Y-m-d',$now);$eol=$today>$security;$securityOnly=$today>$active;$pending=version_compare($version,$latest,'<');$ahead=version_compare($version,$latest,'>');
        $label=$eol?'Sin soporte':($ahead?'Posterior a referencia':($pending?'Actualizar a '.$latest:($securityOnly?'Solo seguridad':'Al día')));
        $detail='Última versión de su '.($product==='php'?'rama':'serie').': '.$latest.'. Soporte activo hasta '.$active.'; seguridad hasta '.$security.'.';
        if($product==='php')$detail.=' Evaluación del proyecto PHP; el proveedor puede mantener parches propios.';
        $item=self::item($eol?'danger':($ahead?'neutral':($pending||$securityOnly?'warning':'success')),$label,$detail,$category);
        return $item+['version'=>$version,'latest'=>$latest,'support'=>$eol?'eol':($securityOnly?'security':'active'),'active_until'=>$active,'security_until'=>$security,'reference_at'=>$checked];
    }
    private static function item(string $tone,string $label,string $detail,string $category): array{return compact('tone','label','detail','category');}
    public static function matches(array $health,string $filter): bool
    {
        if($filter==='all')return true;
        if($filter==='critical')return $health['critical']>0;
        if($filter==='review')return count($health['issues'])>0;
        if($filter==='unknown')return $health['evaluated']<$health['total'];
        $key=$filter==='php'?'runtime':$filter;
        return in_array($health['indicators'][$key]['tone']??'neutral',['warning','danger'],true);
    }
    public static function bytes(int $value): string{return $value>=1073741824?round($value/1073741824,1).' GB':round($value/1048576).' MB';}
}
