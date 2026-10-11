<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Joomla\Database\DatabaseInterface;

/** Stores only bounded observations, never payloads, credentials or transport bodies. */
final class CheckHistory
{
    public function __construct(private DatabaseInterface $db) {}

    public function record(int $site, array $result): void
    {
        $ok = ($result['ok'] ?? false) === true;
        $error = $result['error'] ?? '';
        $errors = ['credential_unavailable','curl_unavailable','invalid_enrollment','transport_configuration_failed',
            'response_too_large','timeout','tls_verification_failed','connection_failed','redirect_rejected',
            'access_denied','connector_unavailable','http_error','invalid_content_type','invalid_json',
            'unsupported_protocol','identity_mismatch','invalid_inventory','transport_failed'];
        $collected = $ok ? ($result['inventory']['collected_at'] ?? '') : '';
        $date = is_string($collected) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $collected, new \DateTimeZone('UTC')) : false;
        $row = (object) ['site'=>$site, 'checked_at'=>time(),
            'collected_at'=>$date && $date->format('Y-m-d\TH:i:s\Z') === $collected ? $date->getTimestamp() : null,
            'outcome'=>$ok ? 'received' : 'failed',
            'detail'=>$ok ? (($result['inventory']['status'] ?? '') === 'complete' ? 'complete' : 'partial')
                : (in_array($error, $errors, true) ? $error : 'transport_failed')];
        $this->db->transactionStart(true);
        try {
            // Serialize retention with deletion and concurrent checks of this site.
            $exists = $this->db->setQuery('SELECT id FROM #__nwm_sites WHERE id=' . $site . ' FOR UPDATE')->loadResult();
            if ($exists) {
                $this->db->insertObject('#__nwm_checks', $row);
                $cutoff = $this->db->setQuery('SELECT id FROM #__nwm_checks WHERE site=' . $site . ' ORDER BY id DESC', 99, 1)->loadResult();
                if ($cutoff) {
                    $this->db->setQuery('DELETE FROM #__nwm_checks WHERE site=' . $site . ' AND id<' . (int)$cutoff)->execute();
                }
            }
            $this->db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->db->transactionRollback(true);
            throw $e;
        }
    }

    public function recent(int $site, int $limit = 10): array
    {
        return $this->db->setQuery('SELECT id, checked_at, collected_at, outcome, detail FROM #__nwm_checks WHERE site=' . $site . ' ORDER BY id DESC', 0, max(1, min(100, $limit)))->loadAssocList();
    }

    public static function state(?array $last, ?int $now = null): string
    {
        if (!$last) { return 'never_checked'; }
        $now ??= time();
        $checked = (int)$last['checked_at'];
        if ($checked > $now + 300) { return 'clock_uncertain'; }
        if ($now - $checked > 86400) { return 'stale'; }
        if ($last['outcome'] !== 'received') { return 'check_failed'; }
        if ($last['collected_at'] === null || (int)$last['collected_at'] > $now + 300) { return 'clock_uncertain'; }
        if ($now - (int)$last['collected_at'] > 86400) { return 'stale'; }
        return $last['detail'] === 'complete' ? 'recent_complete' : 'recent_partial';
    }
}
