<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;

/** Read-only projection of retained observations; never sends notifications. */
final class CheckAlerts
{
    public static function summarize(array $newestFirst, ?int $now = null): array
    {
        $now ??= time();
        $events = [];
        $previous = null;
        $state = 'never_checked';
        $append = static function (string $next, int $at) use (&$state, &$events): void {
            if ($next === $state) { return; }
            // A first successful observation is not a recovery.
            $kind = $next === 'recent_complete'
                ? ($state === 'never_checked' ? 'baseline' : 'recovered')
                : ($state === 'never_checked' || $state === 'recent_complete' ? 'opened' : 'changed');
            $events[] = ['from'=>$state, 'to'=>$next, 'kind'=>$kind, 'at'=>$at];
            $state = $next;
        };
        foreach (array_reverse(array_slice($newestFirst, 0, 100)) as $check) {
            $at = (int)$check['checked_at'];
            if ($previous !== null) {
                // Include expiry between observations, so a fresh result after a gap recovers.
                $aged = CheckHistory::state($previous, $at);
                if ($aged === 'stale') {
                    $expiry = (int)$previous['checked_at'] + 86401;
                    if ($previous['outcome'] === 'received' && $previous['collected_at'] !== null) {
                        $expiry = min($expiry, (int)$previous['collected_at'] + 86401);
                    }
                    $append($aged, max((int)$previous['checked_at'], $expiry));
                }
            }
            $append(CheckHistory::state($check, $at), $at);
            $previous = $check;
        }
        $current = CheckHistory::state($previous, $now);
        if ($previous !== null && $current !== $state) {
            $at = $now;
            if ($current === 'stale') {
                $at = (int)$previous['checked_at'] + 86401;
                if ($previous['outcome'] === 'received' && $previous['collected_at'] !== null) {
                    $at = min($at, (int)$previous['collected_at'] + 86401);
                }
                $at = max((int)$previous['checked_at'], $at);
            }
            $append($current, $at);
        }
        return ['state'=>$current, 'active'=>!in_array($current, ['never_checked','recent_complete'], true),
            'transitions'=>array_reverse(array_slice($events, -10))];
    }
}
