<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
final class CredentialVault
{
    private string $key;
    public function __construct(#[\SensitiveParameter] string $joomlaSecret)
    {
        if (strlen($joomlaSecret) < 16) {
            throw new \RuntimeException('Credential encryption unavailable');
        }
        $this->key = hash_hkdf('sha256', $joomlaSecret, 32, 'nwm-credentials-v1');
    }
    public function seal(string $siteId, #[\SensitiveParameter] string $token): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($token, $siteId, $nonce, $this->key));
    }
    public function open(string $siteId, #[\SensitiveParameter] string $sealed): string
    {
        $raw = str_starts_with($sealed, 'v1:') ? base64_decode(substr($sealed, 3), true) : false;
        if ($raw === false || strlen($raw) < 40) {
            throw new \RuntimeException('Credential unavailable; enroll again');
        }
        $token = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, 24), $siteId, substr($raw, 0, 24), $this->key);
        if ($token === false) {
            throw new \RuntimeException('Credential unavailable; enroll again');
        }
        return $token;
    }
}
