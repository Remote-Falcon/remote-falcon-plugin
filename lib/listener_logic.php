<?php
// Pure-logic helpers extracted from remote_falcon_listener.php so they
// can be unit-tested independently of FPP.
//
// Functions in this file MUST NOT have side effects beyond their return
// value (no file I/O, no HTTP, no global mutation, no logging).
// Anything in here should be testable in isolation with PHPUnit.

if (!function_exists('rf_get_next_sequence')) {

    /**
     * Find the sequence that comes after $currentlyPlaying in $mainPlaylist.
     * Wraps to the first sequence if $currentlyPlaying is the last entry.
     * Matches by base filename (no path, no extension).
     *
     * @param array $mainPlaylist Array of stdClass items each with a
     *                            ->sequenceName property.
     * @param string $currentlyPlaying Base filename to look for.
     * @return string Base filename of the next sequence, or "" if not found.
     */
    function rf_get_next_sequence(array $mainPlaylist, string $currentlyPlaying): string {
        $nextScheduled = "";
        $count = count($mainPlaylist);
        for ($i = 0; $i < $count; $i++) {
            if (!isset($mainPlaylist[$i]->sequenceName)) {
                continue;
            }
            if (pathinfo($mainPlaylist[$i]->sequenceName, PATHINFO_FILENAME) !== $currentlyPlaying) {
                continue;
            }
            if ($i + 1 === $count) {
                $nextScheduled = $mainPlaylist[0]->sequenceName ?? "";
            } else {
                $nextScheduled = $mainPlaylist[$i + 1]->sequenceName ?? "";
            }
            break;
        }
        return pathinfo($nextScheduled, PATHINFO_FILENAME);
    }

    /**
     * Clamp the FPP status check interval to a safe minimum. Values <= 0
     * would cause usleep(0) busy-loops; values < 0.1 risk starving other
     * system work on resource-constrained hosts.
     *
     * @param mixed $value Value as read from the INI file.
     * @return float Clamped value, always >= 0.1.
     */
    function rf_clamp_status_check_time($value): float {
        $f = floatval($value);
        if ($f < 0.1) {
            return 0.1;
        }
        return $f;
    }
}
