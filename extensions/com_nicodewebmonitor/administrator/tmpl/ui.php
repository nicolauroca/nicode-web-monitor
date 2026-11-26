<?php
/** @license GPL-2.0-or-later — presentation helpers only. */
defined('_JEXEC') or die;
$escape=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'No disponible',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$date=static function($v):string {
    if($v===null || $v==='')return 'Sin fecha';
    try{$time=is_numeric($v) ? new \DateTimeImmutable('@'.(int)$v) : new \DateTimeImmutable((string)$v,new \DateTimeZone('UTC'));return $time->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y · H:i');}
    catch(\Throwable){return 'Fecha no disponible';}
};
$states=[
    'recent_complete'=>['Inventario reciente','success','recent'],
    'recent_partial'=>['Inventario parcial','warning','review'],
    'check_failed'=>['Comprobación fallida','danger','review'],
    'stale'=>['Datos antiguos','warning','review'],
    'clock_uncertain'=>['Fecha no fiable','warning','review'],
    'never_checked'=>['Sin comprobar','neutral','never'],
];
$detailLabels=['complete'=>'Inventario completo','partial'=>'Inventario parcial','access_denied'=>'Acceso rechazado',
    'connector_unavailable'=>'Conector no disponible','connection_failed'=>'No se pudo conectar','timeout'=>'Tiempo de espera agotado',
    'tls_verification_failed'=>'No se pudo validar HTTPS','credential_unavailable'=>'Credencial no disponible',
    'identity_mismatch'=>'La identidad no coincide','redirect_rejected'=>'Redirección rechazada','transport_failed'=>'Petición no confirmada'];
$deliveryLabels=['baseline'=>'Estado inicial registrado','accepted'=>'Aceptado por el servidor de correo','not_accepted'=>'Envío no aceptado','uncertain'=>'Entrega sin confirmar'];
$operationLabels=['ready'=>'Preparada','progress'=>'En curso','complete'=>'Completa','updated'=>'Actualizada','verified'=>'Verificada','queued'=>'En cola','uncertain'=>'Sin confirmar','failed'=>'Fallida'];
$paths=[
 'monitor'=>'<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4M7 11h3l2-4 2 7 2-3h2"/>',
 'server'=>'<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M11 6.5h6M11 17.5h6"/>',
 'check'=>'<circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/>',
 'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
 'alert'=>'<path d="m12 3 10 17H2L12 3Z"/><path d="M12 9v4m0 3v.01"/>',
 'plus'=>'<path d="M12 5v14M5 12h14"/>',
 'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
 'settings'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
 'chevron'=>'<path d="m9 5 7 7-7 7"/>',
 'shield'=>'<path d="M12 3 4 6v6c0 4 8 9 8 9s8-5 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/>',
 'copy'=>'<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
 'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
 'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
];
$icon=static fn($name)=>'<svg class="nwm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$paths[$name].'</svg>';
