<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Component\ComponentHelper;

$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$connected=$state['state']==='connected';
$pending=$state['state']==='pending';
$disabled=($connected || $pending) && !$state['enabled'];
$canAdmin=$user->authorise('core.admin','com_nicodeconnector');
$hasCentral=ComponentHelper::isInstalled('com_nicodewebmonitor') && $user->authorise('core.manage','com_nicodewebmonitor');
$local=($state['connection_type']??'https')==='local';
$localUrl=rtrim(Uri::root(),'/');
$centralUrl=(string)$state['central'];
$centralHost=parse_url($centralUrl,PHP_URL_HOST) ?: $centralUrl;
$localHost=parse_url($localUrl,PHP_URL_HOST) ?: $localUrl;
$localLabel=$localHost.(parse_url($localUrl,PHP_URL_PATH)??'');
$centralLink=$local && $hasCentral ? 'index.php?option=com_nicodewebmonitor' : null;
if ($centralLink===null && parse_url($centralUrl,PHP_URL_SCHEME)==='https' && parse_url($centralUrl,PHP_URL_HOST)) {
    $centralLink=rtrim($centralUrl,'/').'/administrator/index.php?option=com_nicodewebmonitor';
}
$pairedAt=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$state['connected_at'],new \DateTimeZone('UTC'));
if ($pairedAt && $pairedAt->format('Y-m-d H:i:s')!==(string)$state['connected_at']) $pairedAt=false;
$visualState=$disabled ? 'disabled' : ($connected ? 'connected' : ($pending ? 'pending' : 'disconnected'));
$icons=[
    'link'=>'<path d="m10 13 4-4M8 16H6a4 4 0 0 1 0-8h3m6 0h3a4 4 0 0 1 0 8h-3"/>',
    'check'=>'<path d="m7 12 3 3 7-7"/><circle cx="12" cy="12" r="9"/>',
    'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
    'server'=>'<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M11 6.5h6M11 17.5h6"/>',
    'shield'=>'<path d="M12 3 4 6v6c0 4 8 9 8 9s8-5 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/>',
    'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'monitor'=>'<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4M7 11h3l2-4 2 7 2-3h2"/>',
    'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v.01"/>',
    'settings'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
    'off'=>'<path d="m4 4 16 16M9 9l-2 2a4 4 0 0 0 6 6l2-2M15 15l2-2a4 4 0 0 0-6-6l-2 2"/>',
    'chevron'=>'<path d="m9 5 7 7-7 7"/>',
];
$icon=static fn($name)=>'<svg class="nwm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$icons[$name].'</svg>';
$uiVersion='1.1.1-dev.7';
$assets=$this->app->getDocument()->getWebAssetManager();
$assetRoot=Uri::root().'administrator/components/com_nicodeconnector/tmpl/';
$assets->registerAndUseStyle('nwm.connector',$assetRoot.'connector.css',['version'=>$uiVersion]);
$assets->registerAndUseScript('nwm.connector',$assetRoot.'connector.js',['version'=>$uiVersion],['defer'=>true]);
?>
<div class="nwm-connector" data-connection-state="<?= $escape($visualState) ?>">
    <header class="nwm-header">
        <div class="nwm-brand-mark"><?= $icon('monitor') ?></div>
        <div class="nwm-header-copy">
            <p class="nwm-eyebrow">Nicode Web Monitor</p>
            <h1 aria-label="Nicode Web Monitor — Conector">Conector</h1>
            <p>La conexión de este Joomla con tu panel central.</p>
        </div>
        <div class="nwm-release"><span class="nwm-release-label"><span></span>En desarrollo</span><span><?= $escape($uiVersion) ?> · No definitiva</span></div>
    </header>

    <div class="nwm-page-context"><span><?= $icon('server') ?>Este sitio</span><strong><?= $escape($localLabel) ?></strong></div>

    <div class="nwm-layout">
        <div class="nwm-main">
        <?php if ($connected): ?>
            <section class="nwm-panel nwm-status-panel" aria-label="Estado de conexión">
                <div class="nwm-status-heading">
                    <span class="nwm-status-icon"><?= $icon($disabled?'off':'check') ?></span>
                    <div><p class="nwm-section-label">Estado de la conexión</p><h2><?= $disabled ? 'Acceso desactivado' : 'Conectado' ?></h2>
                    <p><?= $disabled ? 'La vinculación existe, pero el central ya no tiene acceso al inventario.' : 'Este Joomla está vinculado a tu panel central.' ?></p></div>
                </div>
                <?php if ($disabled): ?><div class="nwm-notice nwm-notice--warning" role="alert"><?= $icon('info') ?><p><?= $canAdmin ? 'Desconecta esta vinculación y utiliza un código nuevo para restablecer el acceso.' : 'Pide a un administrador que restablezca la vinculación con el central.' ?></p></div><?php endif; ?>
                <dl class="nwm-facts">
                    <div class="nwm-fact nwm-fact--wide"><dt>Panel central</dt><dd><strong><?= $escape($centralHost) ?></strong><span class="nwm-url"><?= $escape($centralUrl) ?></span></dd></div>
                    <div class="nwm-fact"><dt>Tipo de conexión</dt><dd><span class="nwm-method"><?= $icon($local?'server':'shield') ?><?= $local ? 'En este mismo Joomla' : 'HTTPS' ?></span><span class="nwm-fact-help"><?= $local ? 'Central y Conector comparten esta instalación.' : 'Inventario compartido mediante una conexión cifrada.' ?></span></dd></div>
                    <div class="nwm-fact"><dt>Vinculación verificada</dt><dd><?php if ($pairedAt): ?><time datetime="<?= $escape($pairedAt->format('Y-m-d\TH:i:s\Z')) ?>"><?= $escape($pairedAt->format('d/m/Y · H:i')) ?></time><span class="nwm-fact-help">Hora UTC</span><?php else: ?><span>Fecha no disponible</span><?php endif; ?></dd></div>
                </dl>
                <div class="nwm-panel-footer">
                    <?php if ($centralLink && !$disabled): ?><a class="nwm-button nwm-button--primary" href="<?= $escape($centralLink) ?>"<?= $local && $hasCentral ? '' : ' target="_blank" rel="noopener noreferrer" aria-label="Abrir panel central en otra pestaña"' ?>>Abrir panel central<?= $icon('arrow') ?></a><?php endif; ?>
                    <p><?= $disabled ? 'Desactivar el acceso no elimina por sí solo el registro del central.' : 'Consulta el inventario, los avisos y las comprobaciones recientes en el central.' ?></p>
                </div>
            </section>
        <?php else: ?>
            <section class="nwm-panel nwm-setup-panel" aria-label="Estado de conexión">
                <div class="nwm-panel-heading"><span class="nwm-heading-icon"><?= $icon($disabled?'off':($pending?'clock':'link')) ?></span><div><p class="nwm-section-label"><?= $pending ? 'Recuperar la vinculación' : 'Empezar' ?></p><h2><?= $disabled ? 'Acceso desactivado' : ($pending ? 'Conexión pendiente de confirmar' : 'Conectar este sitio') ?></h2></div></div>
                <p class="nwm-intro"><?= $disabled ? 'El acceso de inventario de este intento está desactivado. Cancela el intento antes de generar un código nuevo.' : ($pending ? 'Todavía no se ha confirmado el resultado. Puedes continuar con el mismo código.' : 'Introduce la dirección del central y el código que has generado allí. Nosotros preparamos la conexión.') ?></p>
                <?php if ($pending && !$disabled): ?><div class="nwm-notice nwm-notice--warning" role="status"><?= $icon('clock') ?><p>Repite <strong>la misma dirección y el mismo código</strong> antes de que caduque. Para utilizar un código nuevo, cancela primero este intento en «Gestionar conexión».</p></div><?php endif; ?>
                <?php if ($canAdmin && !$disabled): ?>
                <form class="nwm-connect-form" method="post" action="index.php?option=com_nicodeconnector" autocomplete="off" data-nwm-submit>
                    <div class="nwm-field"><div class="nwm-label-row"><label for="central-url">Dirección del panel central</label><?php if ($hasCentral && !$pending): ?><button class="nwm-text-button" type="button" data-nwm-use-local data-local-url="<?= $escape($localUrl) ?>" hidden>Usar este Joomla</button><?php endif; ?></div>
                        <input id="central-url" class="nwm-input" type="url" name="central_url" value="<?= $escape($centralUrl) ?>" placeholder="https://central.ejemplo.es" pattern="https://.*" inputmode="url" autocapitalize="none" spellcheck="false" maxlength="2048" aria-describedby="central-url-help central-url-error" required>
                        <p class="nwm-field-help" id="central-url-help">La dirección de Joomla, sin <span class="nwm-inline-code">/administrator</span>.</p><p class="nwm-field-error" id="central-url-error" hidden></p>
                    </div>
                    <div class="nwm-field"><label for="connection-code">Código de vinculación</label><div class="nwm-code-input"><input id="connection-code" class="nwm-input" type="password" name="code" pattern="[a-f0-9]{64}" minlength="64" maxlength="64" autocomplete="new-password" autocapitalize="none" spellcheck="false" aria-describedby="connection-code-help connection-code-error" required><button class="nwm-code-toggle" type="button" aria-controls="connection-code" aria-pressed="false" data-nwm-toggle-code hidden>Mostrar</button></div>
                        <p class="nwm-field-help" id="connection-code-help">Pega el código de «Añadir sitio» del central. Es válido durante 10 minutos.</p><p class="nwm-field-error" id="connection-code-error" hidden></p>
                    </div>
                    <input type="hidden" name="task" value="connect"><?= HTMLHelper::_('form.token') ?>
                    <div class="nwm-form-action"><button class="nwm-button nwm-button--primary" type="submit"><span data-nwm-button-label><?= $pending ? 'Confirmar conexión' : 'Conectar' ?></span><?= $icon('arrow') ?></button><span><?= $icon('shield') ?>Vinculación verificada antes de guardar</span></div>
                    <p class="nwm-submit-status" role="status" data-nwm-submit-status hidden></p>
                </form>
                <?php elseif (!$canAdmin): ?><div class="nwm-notice"><?= $icon('info') ?><p>Un administrador de este componente puede vincular el sitio. Solicítale que complete la conexión.</p></div><?php endif; ?>
            </section>
        <?php endif; ?>

        <?php require __DIR__.'/sharing.php'; ?>
        <?php if ($canAdmin && ($connected || $pending)): ?>
            <details class="nwm-panel nwm-management"<?= $pending || $disabled ? ' open' : '' ?>>
                <summary><?= $icon('settings') ?><span>Gestionar conexión</span><?= $icon('chevron') ?></summary>
                <div class="nwm-management-content"><h3><?= $pending ? 'Cancelar este intento' : 'Desconectar del central' ?></h3><p><?= $pending ? 'Cancela y revoca el acceso de este intento antes de generar un código nuevo.' : 'El central dejará de recibir el inventario de este Joomla. Su registro seguirá en el panel hasta que lo elimines allí.' ?></p>
                    <form method="post" action="index.php?option=com_nicodeconnector" data-nwm-submit>
                        <input type="hidden" name="task" value="disconnect"><label class="nwm-confirm"><input type="checkbox" name="confirmed" value="1" required><span><?= $pending ? 'Quiero revocar el acceso de este intento.' : 'Quiero revocar el acceso de inventario de este central.' ?></span></label>
                        <?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--danger" type="submit"><span data-nwm-button-label><?= $pending ? 'Cancelar intento' : 'Desconectar' ?></span></button><p class="nwm-submit-status" role="status" data-nwm-submit-status hidden></p>
                    </form>
                </div>
            </details>
        <?php endif; ?>
        </div>

        <aside class="nwm-sidebar" aria-label="Ayuda y alcance de la conexión">
            <?php if (!$connected): ?>
            <section class="nwm-panel nwm-guide"><p class="nwm-section-label"><?= $pending ? 'Cómo continuar' : 'Desde tu panel central' ?></p><h2><?= $disabled ? 'Empieza con otro código' : ($pending ? 'Retoma este intento' : 'Todo listo en tres pasos') ?></h2><ol class="nwm-steps">
            <?php if ($disabled): ?><li><span>1</span><div><strong>Cancela este intento</strong><p>Abre «Gestionar conexión» y confirma la revocación.</p></div></li><li><span>2</span><div><strong>Genera un código nuevo</strong><p>Hazlo desde «Añadir sitio» en tu panel central.</p></div></li><li><span>3</span><div><strong>Vuelve a conectar</strong><p>Introduce la dirección del central y el nuevo código.</p></div></li>
            <?php elseif ($pending): ?><li><span>1</span><div><strong>Mantén la dirección</strong><p>Usa el mismo panel central con el que iniciaste la conexión.</p></div></li><li><span>2</span><div><strong>Repite el mismo código</strong><p>Podrás recuperar el resultado mientras el código siga vigente.</p></div></li><li><span>3</span><div><strong>¿Necesitas otro código?</strong><p>Cancela primero este intento. Después genera uno nuevo en el central.</p></div></li>
            <?php else: ?><li><span>1</span><div><strong>Añade el sitio</strong><p>Abre Nicode Web Monitor — Central y elige «Añadir sitio».</p></div></li><li><span>2</span><div><strong>Genera un código</strong><p>Ponle un nombre al sitio y copia su código de vinculación.</p></div></li><li><span>3</span><div><strong>Conecta aquí</strong><p>Completa los dos campos. El central comprobará el primer inventario.</p></div></li><?php endif; ?></ol>
            <?php if ($hasCentral): ?><a class="nwm-link" href="index.php?option=com_nicodewebmonitor">Abrir Central en este Joomla<?= $icon('arrow') ?></a><?php endif; ?></section>
            <?php endif; ?>
            <section class="nwm-panel nwm-scope"><div class="nwm-side-title"><?= $icon('grid') ?><h2>Qué comparte este sitio</h2></div><p><?= count(array_filter($sharing)) ?> de <?= count($sharingCategories) ?> categorías permitidas.</p><ul class="nwm-feature-list"><?php foreach($sharingCategories as $key=>$category): if(!$sharing[$key])continue; ?><li><?= $icon('check') ?><span><?= $escape($category[0]) ?></span></li><?php endforeach; ?></ul><a class="nwm-link" href="#nwm-sharing">Revisar permisos de consulta<?= $icon('arrow') ?></a><p class="nwm-scope-note">Usuarios y contenido solo en cifras agregadas. Sin contraseñas, correos ni textos de artículos.</p></section>
            <?php if ($connected): ?><section class="nwm-next-step"><span><?= $icon($disabled?'link':'clock') ?></span><div><h2><?= $disabled ? 'Restablece la vinculación' : 'Comprueba la programación' ?></h2><p><?= $disabled ? 'Después de conectar de nuevo, comprueba que el central puede leer el inventario.' : 'La vinculación está verificada. Revisa en el central que las comprobaciones automáticas se estén ejecutando.' ?></p></div></section><?php endif; ?>
        </aside>
    </div>

    <details class="nwm-advanced"><summary><?= $icon('settings') ?><span>Copias y actualizaciones <span class="nwm-advanced-label">Avanzado</span></span><?= $icon('chevron') ?></summary><div><p>Estas funciones experimentales necesitan una preparación y permisos independientes. No se activan al vincular el inventario.</p><?php if ($canAdmin && $user->authorise('core.manage','com_plugins')): ?><a class="nwm-link" href="index.php?option=com_plugins&amp;view=plugins&amp;filter[search]=Nicode">Abrir configuración avanzada<?= $icon('arrow') ?></a><?php else: ?><p>Un administrador puede gestionar estas funciones.</p><?php endif; ?></div></details>
</div>
