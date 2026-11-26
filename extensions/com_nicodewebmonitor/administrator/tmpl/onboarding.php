<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
?>
<?php if($canCreate): ?>
<details id="nwm-add-site" class="nwm-panel nwm-onboarding" <?= !$sites||!empty($pairingCode)?'open':'' ?>>
<summary><?= $icon('plus') ?><span><h2>Añadir sitio</h2><small>Instala el Conector, genera un código y vincula el sitio</small></span><?= $icon('chevron') ?></summary>
<div class="nwm-onboarding-body">
<ol class="nwm-steps"><li><span>1</span><div><strong>Instala el Conector</strong><p>En el Joomla que vas a supervisar, instala el paquete Nicode Web Monitor — Conector y abre su componente.</p></div></li><li><span>2</span><div><strong>Genera un código aquí</strong><p>Asigna un nombre reconocible al sitio. No necesitas introducir credenciales ni datos técnicos.</p></div></li><li><span>3</span><div><strong>Conecta desde el otro sitio</strong><p>Copia la dirección de este central y el código en el Conector. El sitio aparecerá aquí tras verificar su inventario.</p></div></li></ol>
<form class="nwm-issue-form" method="post" action="index.php?option=com_nicodewebmonitor#nwm-add-site" autocomplete="off" data-nwm-submit>
<div><label for="new-site-name">Nombre para identificar el sitio</label><input id="new-site-name" name="label" maxlength="120" required placeholder="Por ejemplo, Web corporativa" aria-describedby="nwm-name-help"><p id="nwm-name-help" class="nwm-field-help">Este nombre solo identifica el sitio en el panel.</p></div>
<input type="hidden" name="task" value="issueCode"><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--primary" type="submit"><span data-nwm-button-label>Generar código de vinculación</span><?= $icon('arrow') ?></button>
</form>
<?php if(!empty($pairingCode)): ?>
<section class="nwm-pairing" aria-label="Datos de vinculación"><div class="nwm-pairing-title"><?= $icon('shield') ?><div><h3>Todo listo para vincular</h3><p>Introduce estos dos datos en el Conector y pulsa «Conectar».</p></div><span class="nwm-badge nwm-badge--warning">Caduca en 10 minutos</span></div>
<label for="central-address">Dirección de este central</label><div class="nwm-copy-field"><input id="central-address" value="<?= $escape(rtrim(Uri::root(),'/')) ?>" readonly><button class="nwm-button nwm-button--secondary" type="button" data-nwm-copy="central-address" hidden><?= $icon('copy') ?><span>Copiar dirección</span></button></div>
<label for="pairing-code">Código de vinculación</label><div class="nwm-copy-field"><input id="pairing-code" type="password" value="<?= $escape($pairingCode) ?>" readonly autocomplete="off" spellcheck="false"><button class="nwm-button nwm-button--quiet" type="button" data-nwm-reveal="pairing-code" aria-controls="pairing-code" aria-pressed="false" hidden>Mostrar</button><button class="nwm-button nwm-button--secondary" type="button" data-nwm-copy="pairing-code" hidden><?= $icon('copy') ?><span>Copiar código</span></button></div>
<noscript><p>Código para copiar: <code class="nwm-code-fallback"><?= $escape($pairingCode) ?></code></p></noscript>
<p class="nwm-field-help">Sirve para un único sitio. Trátalo como una contraseña temporal. Generar otro código sustituye al anterior de tu usuario.</p><p role="status" class="nwm-copy-status" data-nwm-copy-status aria-live="polite"></p></section>
<?php endif; ?>
<details class="nwm-local-help"><summary>¿Quieres supervisar también este Joomla central?</summary><p>Instala aquí el paquete Conector y vincúlalo con un código de este panel. Central y Conector pueden convivir; este Joomla también puede conectarse a otro central.</p><?php if($hasConnector): ?><a href="index.php?option=com_nicodeconnector">Abrir el Conector de este Joomla<?= $icon('arrow') ?></a><?php endif; ?></details>
</div></details>
<?php endif; ?>
