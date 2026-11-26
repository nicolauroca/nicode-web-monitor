<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\Console\NicodeWebMonitor\Inventory;

/** Shared catalogue and wire allowlist. Generated into each independent package. */
final class InventorySchema
{
    public const VERSION = 2;
    public const ROW_LIMIT = 1000;

    public static function categories(): array
    {
        return [
            'joomla'=>['Joomla','Versión instalada de Joomla.',true],
            'runtime'=>['PHP y entorno','Versión, límites de PHP, módulos cargados y uso de memoria del proceso.',true],
            'database'=>['Base de datos','Motor, versión, número de tablas y tamaño agregado. Sin nombres de tablas ni registros.',true],
            'extensions'=>['Extensiones','Nombres, versiones, activación e identidad de las extensiones instaladas. Sin sus parámetros.',true],
            'updates'=>['Actualizaciones detectadas','Caché de actualizaciones de Joomla: versiones ofrecidas y fecha de consulta. Sin URLs ni claves de descarga.',false],
            'configuration'=>['Configuración general','Nombre del sitio, modo offline, zona horaria, caché, SEO, compresión y sesiones. Sin configuración SMTP ni secretos.',false],
            'security'=>['Señales de seguridad','HTTPS observado, HTTPS obligatorio, depuración y cifras de MFA. Sin identidades ni credenciales.',false],
            'users'=>['Usuarios en cifras','Totales, usuarios bloqueados, pendientes de activación y altas de los últimos 30 días. Sin nombres, correos ni actividad individual.',false],
            'content'=>['Contenido en cifras','Cantidades de artículos por estado, categorías y elementos de menú. Sin textos, títulos ni autores.',false],
            'languages'=>['Idiomas','Idiomas instalados y de contenido, activación e idiomas predeterminados.',false],
            'templates'=>['Plantillas','Estilos instalados, plantilla, cliente y asignación predeterminada. Sin parámetros.',false],
            'scheduler'=>['Tareas programadas','Nombre, tipo, estado, últimas/próximas ejecuciones y contadores de tareas. Sin parámetros de ejecución.',false],
            'storage'=>['Almacenamiento','Espacio libre y total del sistema de archivos visible para PHP. No es la cuota del alojamiento. Sin rutas ni listado de archivos.',false],
        ];
    }

    /** Missing policy uses the essential profile; a saved policy denies missing keys. */
    public static function policy(?array $saved = null): array
    {
        $result=[];
        foreach(self::categories() as $key=>$category) $result[$key]=$saved===null ? $category[2] : in_array($saved[$key]??false,[true,1,'1'],true);
        return $result;
    }

    /** Fields are [human label, scalar type]; row lists are bounded separately. */
    public static function fields(): array
    {
        return [
            'joomla'=>['version'=>['Versión Joomla','string']],
            'runtime'=>['php_version'=>['Versión PHP','string'],'sapi'=>['Ejecución PHP','string'],'os_family'=>['Sistema operativo','string'],
                'memory_limit'=>['Límite de memoria','string'],'max_execution_time'=>['Tiempo máximo (s)','int'],'upload_max_filesize'=>['Subida máxima','string'],
                'post_max_size'=>['Tamaño máximo POST','string'],'max_input_vars'=>['Variables de entrada máximas','int'],
                'opcache_enabled'=>['OPcache habilitado','bool'],'memory_used_bytes'=>['Memoria del proceso (bytes)','int'],'memory_peak_bytes'=>['Pico del proceso (bytes)','int'],'loaded_extensions'=>['Módulos PHP','strings']],
            'database'=>['driver'=>['Controlador','string'],'server_type'=>['Motor','string'],'version'=>['Versión','string'],'table_count'=>['Tablas Joomla','int'],'size_bytes'=>['Tamaño de tablas (bytes)','int']],
            'updates'=>['total'=>['Entradas en caché','int'],'last_checked_at'=>['Última consulta de proveedores (UTC)','date'],'oldest_checked_at'=>['Consulta más antigua de proveedores (UTC)','date'],'enabled_sources'=>['Proveedores habilitados','int']],
            'configuration'=>['sitename'=>['Nombre del sitio','string'],'offline'=>['Modo offline','bool'],'timezone'=>['Zona horaria','string'],'editor'=>['Editor predeterminado','string'],
                'caching'=>['Modo de caché (0/1/2)','int'],'cachetime'=>['Duración de caché (min)','int'],'cache_handler'=>['Gestor de caché','string'],
                'sef'=>['URLs amigables','bool'],'sef_rewrite'=>['Reescritura de URLs','bool'],'sef_suffix'=>['Sufijo en URLs','bool'],
                'gzip'=>['Compresión gzip','bool'],'robots'=>['Robots globales','string'],'session_handler'=>['Gestor de sesiones','string'],'session_lifetime'=>['Duración de sesión (min)','int']],
            'security'=>['force_ssl'=>['HTTPS obligatorio (0/1/2)','int'],'https_observed'=>['Petición observada por HTTPS','bool'],
                'debug'=>['Depuración de Joomla','bool'],'debug_lang'=>['Depuración de idioma','bool'],'display_errors'=>['Errores PHP visibles','bool'],
                'error_reporting'=>['Nivel de errores configurado','string'],'mfa_users'=>['Usuarios con MFA configurado','int']],
            'users'=>['total'=>['Usuarios totales','int'],'enabled'=>['Habilitados','int'],'blocked'=>['Bloqueados','int'],'pending_activation'=>['Pendientes de activación','int'],'registered_30_days'=>['Altas en 30 días','int']],
            'content'=>['articles'=>['Artículos totales','int'],'published'=>['Publicados','int'],'unpublished'=>['No publicados','int'],'archived'=>['Archivados','int'],'trashed'=>['En papelera','int'],'categories'=>['Categorías de contenido','int'],'menu_items'=>['Elementos de menú del sitio','int']],
            'languages'=>['site_default'=>['Idioma del sitio','string'],'administrator_default'=>['Idioma de administración','string'],'total'=>['Idiomas de contenido','int'],'installed'=>['Paquetes de idioma instalados','strings']],
            'templates'=>['total'=>['Estilos de plantilla','int']],
            'scheduler'=>['total'=>['Tareas totales','int'],'enabled'=>['Habilitadas','int'],'disabled'=>['Deshabilitadas','int'],'locked'=>['Bloqueadas','int']],
            'storage'=>['total_bytes'=>['Capacidad del sistema de archivos (bytes)','int'],'free_bytes'=>['Espacio libre (bytes)','int']],
            'extensions'=>[],
        ];
    }

    public static function rowFields(): array
    {
        return [
            'extensions'=>['id'=>['ID','int'],'name'=>['Nombre','string'],'type'=>['Tipo','string'],'element'=>['Elemento','string'],'folder'=>['Grupo','string'],'client_id'=>['Cliente (0 sitio / 1 admin)','int'],'package_id'=>['Paquete','int'],'enabled'=>['Activada','bool'],'version'=>['Versión','version']],
            'updates'=>['name'=>['Nombre','string'],'type'=>['Tipo','string'],'element'=>['Elemento','string'],'version'=>['Versión ofrecida','release'],'current_version'=>['Versión instalada','release'],'extension_id'=>['ID extensión','int']],
            'languages'=>['title'=>['Idioma','string'],'lang_code'=>['Código','string'],'published'=>['Publicado','bool']],
            'templates'=>['title'=>['Estilo','string'],'template'=>['Plantilla','string'],'client_id'=>['Cliente (0 sitio / 1 admin)','int'],'home'=>['Predeterminado / idioma','string']],
            'scheduler'=>['title'=>['Tarea','string'],'type'=>['Tipo','string'],'state'=>['Estado (0 pausa / 1 activa / -2 papelera)','int'],'last_exit_code'=>['Último código de salida','int'],
                'last_execution'=>['Última ejecución (UTC)','date'],'next_execution'=>['Próxima ejecución (UTC)','date'],'times_executed'=>['Ejecuciones','int'],'times_failed'=>['Fallos','int']],
        ];
    }

    /** Validate the envelope, strip extra data, and keep malformed scalar values unknown. */
    public static function normalise(mixed $input, array $contexts=['remote_https']): array
    {
        if(!is_array($input) || ($input['schema_version']??null)!==self::VERSION
            || !in_array($input['status']??null,['complete','partial'],true)
            || !in_array($input['execution_context']??null,$contexts,true)
            || self::scalar($input['collected_at']??null,'date')===null) throw new \UnexpectedValueException('invalid_inventory');
        $capabilities=[];
        foreach(['remote_inventory','remote_updates','remote_backups'] as $key){
            if(!is_bool($input['capabilities'][$key]??null))throw new \UnexpectedValueException('invalid_inventory');
            $capabilities[$key]=$input['capabilities'][$key];
        }
        if($input['execution_context']==='remote_https' && !$capabilities['remote_inventory'])throw new \UnexpectedValueException('invalid_inventory');
        $sections=[];$fields=self::fields();$rows=self::rowFields();$hasError=false;
        foreach(self::categories() as $key=>$category){
            $section=$input['sections'][$key]??null;
            if(!is_array($section) || !array_key_exists('data',$section) || !in_array($section['status']??null,['available','denied','unavailable','error'],true))throw new \UnexpectedValueException('invalid_inventory');
            $status=$section['status'];
            if($status!=='available'){
                if($section['data']!==null)throw new \UnexpectedValueException('invalid_inventory');
                $sections[$key]=['status'=>$status,'reason'=>match($status){'denied'=>'sharing_disabled','error'=>'collection_failed',default=>'not_available'},'data'=>null];
                $hasError=$hasError||$status==='error';continue;
            }
            if(!is_array($section['data']))throw new \UnexpectedValueException('invalid_inventory');
            $data=[];
            foreach($fields[$key] as $field=>[$label,$type])$data[$field]=self::scalar($section['data'][$field]??null,$type);
            if(isset($rows[$key])){
                $items=$section['data']['items']??[];
                if(!is_array($items)||!array_is_list($items)||count($items)>self::ROW_LIMIT)throw new \UnexpectedValueException('invalid_inventory');
                $data['items']=[];
                foreach($items as $item){
                    if(!is_array($item))throw new \UnexpectedValueException('invalid_inventory');
                    $clean=[];foreach($rows[$key] as $field=>[$label,$type])$clean[$field]=self::scalar($item[$field]??null,$type);
                    $data['items'][]=$clean;
                }
                $data['total']=self::scalar($section['data']['total']??null,'int');
                $data['truncated']=($section['data']['truncated']??false)===true;
            }
            $sections[$key]=['status'=>'available','data'=>$data];
        }
        if($hasError && $input['status']!=='partial')throw new \UnexpectedValueException('invalid_inventory');
        return ['schema_version'=>self::VERSION,'collector_version'=>self::scalar($input['collector_version']??null,'string'),
            'collected_at'=>$input['collected_at'],'execution_context'=>$input['execution_context'],'status'=>$input['status'],'capabilities'=>$capabilities,'sections'=>$sections];
    }

    private static function scalar(mixed $v,string $type): mixed
    {
        if($type==='int')return is_int($v)?$v:null;
        if($type==='bool')return is_bool($v)?$v:null;
        if($type==='release')return is_string($v)&&preg_match('/\A[0-9][a-zA-Z0-9.+_-]{0,79}\z/D',$v)?$v:null;
        if($type==='strings'){
            if(!is_array($v)||!array_is_list($v)||count($v)>300)return null;
            return array_map(static fn($item)=>self::scalar($item,'string'),$v);
        }
        if($type==='version'){
            $value=is_array($v)&&($v['status']??'')==='available'?self::scalar($v['value']??null,'release'):null;
            return ['status'=>$value===null?'unknown':'available','value'=>$value];
        }
        if(!is_string($v)||strlen($v)>512||preg_match('/[\x00-\x1f\x7f]/',$v))return null;
        if($type==='date'){
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new \DateTimeZone('UTC'));
            return $date&&$date->format('Y-m-d\TH:i:s\Z')===$v?$v:null;
        }
        return $v;
    }
}
