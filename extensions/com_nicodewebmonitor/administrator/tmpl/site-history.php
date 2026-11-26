<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
?>
<section class="nwm-panel nwm-detail-panel"><h2>Historial y avisos</h2>
        <div class="nwm-details-grid"><section><h4>Comprobaciones recientes</h4><?php if(!empty($site['checks'])): ?><ol class="nwm-timeline"><?php foreach($site['checks'] as $check): ?><li><time><?= $escape($date($check['checked_at'])) ?></time><span><?= $escape($detailLabels[$check['detail']]??$check['detail']) ?></span></li><?php endforeach; ?></ol><?php else: ?><p>El sitio todavía no tiene comprobaciones guardadas.</p><?php endif; ?></section>
        <section><h4>Alertas de inventario</h4><p><?= !isset($site['alerts']) ? 'Sin información de alertas.' : ($site['alerts']['active']?'Hay una observación que requiere revisión.':(($site['alerts']['state']??'')==='never_checked'?'A la espera de la primera comprobación.':'Sin alertas activas de inventario.')) ?></p>
        <?php if(!empty($site['alerts']['transitions'])): ?><details class="nwm-subdetails"><summary>Cambios de estado</summary><ol class="nwm-timeline"><?php foreach($site['alerts']['transitions'] as $event): ?><li><time><?= $escape($date($event['at'])) ?></time><span><?= $escape(($states[$event['from']][0]??$event['from']).' → '.($states[$event['to']][0]??$event['to'])) ?></span><small><?= $escape(['recovered'=>'Recuperación','opened'=>'Alerta detectada','changed'=>'Cambio de estado','baseline'=>'Primera observación'][$event['kind']]??$event['kind']) ?></small></li><?php endforeach; ?></ol></details><?php endif; ?>
        <?php if(!empty($site['notification'])): $notice=$site['notification']; ?><p class="nwm-notification">Correo: <?= $escape($deliveryLabels[$notice['delivery']]??$notice['delivery']) ?>.<br>Evaluado: <?= $escape($date($notice['evaluated_at'])) ?>.</p><p class="nwm-muted">Aceptación por SMTP no confirma la entrega al buzón.</p>
        <?php if($canAdmin && in_array($notice['delivery'],['uncertain','not_accepted'],true)): ?><form method="post" action="<?= $escape($siteLink('history')) ?>"><input type="hidden" name="task" value="notificationRetry"><input type="hidden" name="id" value="<?= (int)$site['id'] ?>"><label class="nwm-confirm"><input type="checkbox" name="confirmed" value="1" required><span>He comprobado la entrega; otro intento podría duplicar el correo.</span></label><?= HTMLHelper::_('form.token') ?><button class="nwm-button nwm-button--secondary" type="submit">Autorizar otro intento de correo</button></form><?php endif; endif; ?>
        </section></div>
</section>
