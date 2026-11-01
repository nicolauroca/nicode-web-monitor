<?php
/** @package Nicode.joomla.webmonitor @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\User\User;
use Joomla\CMS\Factory;
use Joomla\CMS\Mail\MailerFactoryInterface;
require_once __DIR__.'/DurableDispatch.php';

/** One attempt per observed transition; reservation precedes external delivery. */
final class Notifications
{
    public function __construct(private DatabaseInterface $db, private User $user, private ?\Closure $sender = null, private ?MailerFactoryInterface $mailerFactory = null) {}
    private function permit(string $action): void
    {
        if ($this->user->guest || $this->user->block || !$this->user->authorise('core.manage','com_nicodewebmonitor') || !$this->user->authorise($action,'com_nicodewebmonitor')) {
            throw new \RuntimeException('Access denied',403);
        }
    }
    public function retry(int $site): void
    {
        $this->permit('core.admin');
        // Only an explicit acknowledgement may re-arm an uncertain delivery.
        $this->db->setQuery("UPDATE #__nwm_notifications SET observed_state='retry_requested' WHERE site=".$site." AND delivery IN ('uncertain','not_accepted')")->execute();
    }
    public function run(bool $enabled, string $recipient, int $limit = 10): array
    {
        $this->permit('nwm.monitor');
        if (!$enabled) { return ['status'=>'disabled','evaluated'=>0,'accepted'=>0,'uncertain'=>0]; }
        if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || strlen($recipient)>254 || preg_match('/[\r\n]/',$recipient) || $limit<1 || $limit>10) {
            throw new \InvalidArgumentException('Invalid notification configuration');
        }
        if (!DurableDispatch::ready($this->db)) { return ['status'=>'autocommit_required','evaluated'=>0,'accepted'=>0,'uncertain'=>0]; }
        $lock=$this->db->quote('nwm-mail:'.substr(hash('sha256',$this->db->getPrefix().JPATH_ROOT),0,40));
        if ((int)$this->db->setQuery('SELECT GET_LOCK('.$lock.',0)')->loadResult()!==1) {
            return ['status'=>'busy','evaluated'=>0,'accepted'=>0,'uncertain'=>0];
        }
        $summary=['status'=>'complete','evaluated'=>0,'accepted'=>0,'uncertain'=>0];
        try {
            $sites=$this->db->setQuery('SELECT s.id,s.label,n.observed_state,n.recipient_hash,n.delivery FROM #__nwm_sites s LEFT JOIN #__nwm_notifications n ON n.site=s.id ORDER BY COALESCE(n.evaluated_at,0),s.id',0,$limit)->loadAssocList();
            $hash=hash('sha256',$recipient);
            foreach($sites as $site) {
                $id=(int)$site['id'];
                $last=(new CheckHistory($this->db))->recent($id,1)[0]??null;
                $state=CheckHistory::state($last);
                $previous=$site['recipient_hash']===$hash ? $site['observed_state'] : null;
                $changed=$previous!==$state;
                $send=$changed && ($state!=='recent_complete' || $previous!==null);
                $delivery=$send?'uncertain':($changed?'baseline':$site['delivery']);
                // A single autocommitted reservation survives crash/lost SMTP acknowledgement.
                $this->db->setQuery('INSERT INTO #__nwm_notifications (site,observed_state,recipient_hash,delivery,evaluated_at,attempted_at) VALUES ('
                    .$id.','.$this->db->quote($state).','.$this->db->quote($hash).','.$this->db->quote($delivery).','.time().','.($send?time():0).') ON DUPLICATE KEY UPDATE observed_state=VALUES(observed_state),recipient_hash=VALUES(recipient_hash),delivery=VALUES(delivery),evaluated_at=VALUES(evaluated_at),attempted_at=IF(VALUES(attempted_at)>0,VALUES(attempted_at),attempted_at)')->execute();
                $summary['evaluated']++;
                if (!$send) { continue; }
                $body='Nicode Web Monitor'."\nSite #".$id.': '.preg_replace('/[\x00-\x1f\x7f]/',' ', $site['label'])
                    ."\nObservation state: ".$state."\nPrevious state: ".($previous??'No notification baseline')
                    ."\nTime (UTC): ".gmdate('Y-m-d H:i:s')
                    ."\nOpen the central Joomla dashboard for details. This describes connector observations, not public website availability.\n";
                try {
                    if ($this->sender) { $accepted=($this->sender)($recipient,'Nicode Web Monitor: connector observation',$body)===true; }
                    else {
                        $mailer=($this->mailerFactory ?? Factory::getContainer()->get(MailerFactoryInterface::class))->createMailer();
                        if ($mailer->Mailer !== 'smtp' || !in_array($mailer->SMTPSecure, ['tls','ssl'], true)) {
                            throw new \RuntimeException('Configure explicit TLS SMTP in Joomla');
                        }
                        // Joomla's opportunistic-TLS fallback retries; require explicit TLS and one attempt.
                        $mailer->SMTPAutoTLS=false;
                        $mailer->addRecipient($recipient);
                        $mailer->setSubject('Nicode Web Monitor: connector observation');
                        $mailer->setBody($body);
                        $mailer->isHtml(false);
                        $mailer->Timeout=10;
                        $mailer->Timelimit=10;
                        $accepted=$mailer->send()===true;
                    }
                    $delivery=$accepted?'accepted':'not_accepted';
                } catch (\Throwable) { $delivery='uncertain'; }
                $this->db->setQuery('UPDATE #__nwm_notifications SET delivery='.$this->db->quote($delivery).' WHERE site='.$id)->execute();
                if ($delivery==='accepted') { $summary['accepted']++; }
                else { $summary['uncertain']++; $summary['status']='delivery_unconfirmed'; }
            }
            return $summary;
        } finally { $this->db->setQuery('SELECT RELEASE_LOCK('.$lock.')')->loadResult(); }
    }
}
