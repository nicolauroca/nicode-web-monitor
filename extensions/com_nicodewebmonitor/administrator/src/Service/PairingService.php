<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Connection\PublicAddress;

final class PairingService
{
    public function __construct(private DatabaseInterface $db, private CredentialVault $vault,
        private ?\Closure $resolve = null, private ?\Closure $clientFactory = null) {}

    public function issue(User $user, string $label): string
    {
        $this->permit($user);
        $label=trim($label);
        if ($label==='' || strlen($label)>120 || preg_match('/[\x00-\x1f\x7f]/',$label)) throw new \InvalidArgumentException('Site name required');
        $code=bin2hex(random_bytes(32));
        $this->db->transactionStart();
        try {
            // Serialise issuance per administrator and retain at most one active code.
            $this->db->setQuery('SELECT id FROM #__users WHERE id='.(int)$user->id.' FOR UPDATE')->loadResult();
            $this->db->setQuery('DELETE FROM #__nwm_pairing WHERE expires_at<'.time().' OR created_by='.(int)$user->id)->execute();
            $row=(object)['code_hash'=>hash('sha256',$code),'label'=>$label,'created_by'=>$user->id,'expires_at'=>time()+600];
            $this->db->insertObject('#__nwm_pairing',$row);
            $this->db->transactionCommit();
        } catch (\Throwable $e) { $this->db->transactionRollback(); throw $e; }
        return $code;
    }

    public function claim(#[\SensitiveParameter] array $payload): array
    {
        return $this->claimUsing($payload,false);
    }

    /** In-process entry point; never selected from an HTTP payload. */
    public function claimLocal(#[\SensitiveParameter] array $payload): array
    {
        return $this->claimUsing($payload,true);
    }

    private function claimUsing(#[\SensitiveParameter] array $payload,bool $local): array
    {
        $code=$payload['code']??''; $uuid=$payload['site_id']??''; $token=$payload['credential']??'';
        if (!is_string($code) || !preg_match('/^[a-f0-9]{64}$/D',$code)
            || !is_string($uuid) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid)
            || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token) || !is_string($payload['base_url']??null)) {
            throw new \RuntimeException('code_unavailable');
        }
        $url=PublicAddress::url($payload['base_url']);
        $binding=hash('sha256',($local?'local':'https')."\n".$url."\n".$uuid."\n".$token);
        $this->db->transactionStart();
        try {
            $where='code_hash='.$this->db->quote(hash('sha256',$code));
            $row=$this->db->setQuery('SELECT * FROM #__nwm_pairing WHERE '.$where.' FOR UPDATE')->loadAssoc();
            if (!$row || (int)$row['expires_at']<time()) throw new \RuntimeException('code_unavailable');
            $owner=new User((int)$row['created_by']); $this->permit($owner);
            if ($row['request_hash'] !== null) {
                if (!hash_equals($row['request_hash'],$binding)
                    || !(int)$this->db->setQuery('SELECT COUNT(*) FROM #__nwm_sites WHERE id='.(int)$row['site'])->loadResult()) {
                    throw new \RuntimeException('code_unavailable');
                }
                // A receipt replay acknowledges exactly the same claim, never a second enrollment.
                $result=['id'=>(int)$row['site']];
            } else {
                try {
                    $registry=new SiteRegistry($this->db,$this->vault,$owner,$this->clientFactory);
                    if ($local) $result=$registry->pairLocal($row['label'],$url,$uuid,$token);
                    else {
                        $address=$this->resolve ? ($this->resolve)($url) : (new PublicAddress())->resolve($url);
                        $result=$registry->pair($row['label'],$url,$address,$uuid,$token);
                    }
                } catch (\Throwable) { throw new \RuntimeException('verification_failed'); }
                $this->db->setQuery('UPDATE #__nwm_pairing SET request_hash='.$this->db->quote($binding).', site='.(int)$result['id'].' WHERE '.$where)->execute();
            }
            $this->db->transactionCommit();
            return ['ok'=>true,'site_id'=>$uuid,'site'=>(int)$result['id']];
        } catch (\Throwable $e) { $this->db->transactionRollback(); throw $e; }
    }

    private function permit(User $user): void
    {
        if (!$user->id || $user->guest || $user->block || !$user->authorise('core.manage','com_nicodewebmonitor')
            || !$user->authorise('core.create','com_nicodewebmonitor')) throw new \RuntimeException('code_unavailable');
    }
}
