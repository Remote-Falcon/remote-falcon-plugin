<?php
// HTTP layer extracted from remote_falcon_listener.php so it can be
// integration-tested against mock servers (no FPP, no RF account).
//
// Functions here take the FPP/RF base URL as a parameter rather than
// reading globals, which makes them callable from tests with arbitrary
// hosts. The listener's existing wrapper functions pass the URLs in
// from $GLOBALS so call sites in the listener don't change.
//
// Internally still uses stream_context + file_get_contents. The perf
// branch will swap this for cURL with keep-alive.

if (!function_exists('rf_http_request')) {

    /**
     * Issue a single HTTP request and return ['status' => int, 'body' => ?string].
     * On transport error / timeout: status 0, body null. Non-2xx responses
     * return their real status and body — callers that need error bodies
     * (e.g. a 400 listing valid values) use this directly; everything else
     * goes through rf_http_request() below.
     */
    function rf_http_request_with_status(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }
        $opts = [
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'header' => implode("\r\n", $headerLines) . (count($headerLines) ? "\r\n" : ""),
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['status' => 0, 'body' => null];
        }
        // Read response headers. PHP 8.4+ exposes http_get_last_response_headers();
        // older versions only expose the locally scoped $http_response_header,
        // which is deprecated in 8.5+. The deprecation is runtime (only fires
        // on actual access), so the function_exists short-circuit on 8.5
        // ensures the deprecated variable is never touched there.
        if (function_exists('http_get_last_response_headers')) {
            $responseHeaders = http_get_last_response_headers() ?? [];
        } else {
            $responseHeaders = $http_response_header ?? [];
        }
        $statusCode = 0;
        if (isset($responseHeaders[0])) {
            if (preg_match('#HTTP/[0-9.]+\s+(\d+)#', $responseHeaders[0], $m)) {
                $statusCode = (int) $m[1];
            }
        }
        return ['status' => $statusCode, 'body' => $response];
    }

    /**
     * Issue a single HTTP request and return the response body, or null
     * on transport error / timeout / non-2xx response.
     */
    function rf_http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): ?string {
        $result = rf_http_request_with_status($method, $url, $headers, $body, $timeout);
        if ($result['body'] === null || $result['status'] < 200 || $result['status'] >= 300) {
            return null;
        }
        return $result['body'];
    }

    /**
     * Decode a JSON response. Returns null on null input or parse error.
     */
    function rf_http_decode_json(?string $response) {
        if ($response === null) {
            return null;
        }
        $decoded = json_decode($response);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $decoded;
    }

    // --- FPP localhost API ---

    /**
     * Probe fppd's status endpoint and report WHY it failed, not just that
     * it did.
     *
     * The old 1s timeout could not tell "localhost was slow" from "fppd is
     * dead": both surfaced as null, and the listener reported the latter.
     * On a small controller an Apache/mod_php worker reaped during idle can
     * take over a second just to fork and initialise, so a slow-but-healthy
     * response was routinely announced as "FPPD is not running!". 3s clears
     * that cold start without making a genuinely down fppd take meaningfully
     * longer to notice, since the caller retries before escalating.
     *
     * The listener reads only fppd's own fields (status_name, current_playlist,
     * current_sequence, current_song, seconds_remaining), so it asks fppd
     * for them directly: Apache proxies /api/fppd/* straight to fppd (every
     * FPP since 2.6). /api/system/status is the same payload run through PHP
     * to add host details -- wifi, interfaces, system info, plugin
     * indicators -- which on a single-core controller made each poll cost
     * hundreds of milliseconds instead of about ten.
     *
     * Because the proxy answers for fppd, it also says when fppd is down: a
     * 502/503/504 is Apache failing to reach it, not fppd answering, so it is
     * reported as unreachable. (/api/system/status answered 200 with
     * status_name "stopped" instead, which looked like a healthy fppd.)
     *
     * @return array{ok: bool, status: ?stdClass, reason: string, httpStatus: int}
     *         reason is one of: ok, unreachable, http_error, bad_body
     */
    function rf_http_fpp_get_status_result(string $fppBaseUrl, int $timeout = 3): array {
        $url = $fppBaseUrl . '/api/fppd/status';
        $result = rf_http_request_with_status('GET', $url, [], null, $timeout);

        // status 0 (connect refused, timeout, DNS) and the proxy's own
        // gateway errors mean "could not talk to fppd". Any other HTTP status
        // means fppd answered, so it is by definition running.
        if ($result['status'] === 0 || in_array($result['status'], [502, 503, 504], true)) {
            return ['ok' => false, 'status' => null, 'reason' => 'unreachable', 'httpStatus' => $result['status']];
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            return ['ok' => false, 'status' => null, 'reason' => 'http_error', 'httpStatus' => $result['status']];
        }
        $decoded = rf_http_decode_json($result['body']);
        if (!($decoded instanceof stdClass)) {
            return ['ok' => false, 'status' => null, 'reason' => 'bad_body', 'httpStatus' => $result['status']];
        }
        return ['ok' => true, 'status' => $decoded, 'reason' => 'ok', 'httpStatus' => $result['status']];
    }

    function rf_http_fpp_get_status(string $fppBaseUrl, int $timeout = 3): ?stdClass {
        $result = rf_http_fpp_get_status_result($fppBaseUrl, $timeout);
        return $result['ok'] ? $result['status'] : null;
    }

    /**
     * $mergeSubs asks FPP to flatten sub-playlists into their parent sections,
     * matching how fppd lays out current_playlist.index. FPP versions without
     * the flag ignore it and return the playlist as saved.
     *
     * Also says whether FPP answered. A missing playlist is an answer (FPP 10
     * returns 200 with a bare ""); a timeout, refused connection or 5xx is
     * not, and says nothing about whether the playlist exists.
     *
     * @return array{answered: bool, playlist: ?stdClass}
     */
    function rf_http_fpp_get_playlist_result(string $fppBaseUrl, string $playlistEncoded, int $timeout = 1, bool $mergeSubs = false): array {
        $url = $fppBaseUrl . '/api/playlist/' . $playlistEncoded . ($mergeSubs ? '?mergeSubs=1' : '');
        $result = rf_http_request_with_status('GET', $url, [], null, $timeout);
        $decoded = rf_http_decode_json($result['body']);
        return [
            'answered' => $result['status'] > 0 && $result['status'] < 500,
            'playlist' => $decoded instanceof stdClass ? $decoded : null,
        ];
    }

    /**
     * fppd's in-memory copy of the running playlist, with mainPlaylist in the
     * order it will actually play (shuffled, for a random playlist) and
     * sub-playlists already flattened. Apache proxies /api/fppd/* to fppd on
     * every FPP from 5.0 (bare /fppd/* is not proxied on 10.x). Only carries
     * the playlist while one is playing.
     */
    function rf_http_fpp_get_live_playlist(string $fppBaseUrl, int $timeout = 1): ?stdClass {
        $body = rf_http_request('GET', $fppBaseUrl . '/api/fppd/playlist/config', [], null, $timeout);
        $decoded = rf_http_decode_json($body);
        return $decoded instanceof stdClass ? $decoded : null;
    }

    /**
     * Names of the playlists FPP knows about, or null if FPP didn't answer.
     * Used to check the remote playlist exists: GET /api/playlist/<missing>
     * returns HTTP 200 with an empty body on FPP 10, so it can't say "missing".
     */
    function rf_http_fpp_get_playlist_names(string $fppBaseUrl, int $timeout = 3): ?array {
        $body = rf_http_request('GET', $fppBaseUrl . '/api/playlists', [], null, $timeout);
        $decoded = rf_http_decode_json($body);
        if (!is_array($decoded)) {
            return null;
        }
        return array_values(array_filter($decoded, 'is_string'));
    }

    function rf_http_fpp_insert_immediate(string $fppBaseUrl, string $playlistEncoded, int $index, int $timeout = 1): bool {
        $url = $fppBaseUrl . '/api/command/Insert%20Playlist%20Immediate/' . $playlistEncoded . '/' . $index . '/' . $index;
        $body = rf_http_request('GET', $url, [], null, $timeout);
        return $body !== null;
    }

    function rf_http_fpp_insert_after_current(string $fppBaseUrl, string $playlistEncoded, int $index, int $timeout = 1): bool {
        $url = $fppBaseUrl . '/api/command/Insert%20Playlist%20After%20Current/' . $playlistEncoded . '/' . $index . '/' . $index;
        $body = rf_http_request('GET', $url, [], null, $timeout);
        return $body !== null;
    }

    // --- Remote Falcon plugins API ---

    function rf_http_rf_get_preferences(string $rfBaseUrl, string $token, int $timeout = 10): ?stdClass {
        return _rf_http_rf_get($rfBaseUrl, '/remotePreferences', $token, $timeout);
    }

    function rf_http_rf_get_highest_voted(string $rfBaseUrl, string $token, int $timeout = 10): ?stdClass {
        return _rf_http_rf_get($rfBaseUrl, '/highestVotedPlaylist', $token, $timeout);
    }

    function rf_http_rf_get_next_in_queue(string $rfBaseUrl, string $token, int $timeout = 10): ?stdClass {
        return _rf_http_rf_get($rfBaseUrl, '/nextPlaylistInQueue?updateQueue=true', $token, $timeout);
    }

    function rf_http_rf_update_whats_playing(string $rfBaseUrl, string $token, string $currentlyPlaying, int $timeout = 10): bool {
        return _rf_http_rf_post($rfBaseUrl, '/updateWhatsPlaying', $token, ['playlist' => trim($currentlyPlaying)], $timeout);
    }

    function rf_http_rf_update_next_scheduled(string $rfBaseUrl, string $token, string $nextScheduled, int $timeout = 10): bool {
        return _rf_http_rf_post($rfBaseUrl, '/updateNextScheduledSequence', $token, ['sequence' => trim($nextScheduled)], $timeout);
    }

    function rf_http_rf_purge_queue(string $rfBaseUrl, string $token, int $timeout = 10): bool {
        $url = $rfBaseUrl . '/purgeQueue';
        $headers = [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Accept' => 'application/json',
            'remotetoken' => $token,
        ];
        $body = _rf_http_rf_curl('DELETE', $url, $headers, null, $timeout);
        return $body !== null;
    }

    /**
     * The heartbeat bypasses the RF backoff on purpose: it neither waits for
     * it nor feeds it. It already runs at a fixed 30s cadence (the lowest
     * rate of any RF call), the server answers it on a cheap fast path, and
     * it's what the control panel uses to call a plugin online. Gating it
     * would let a short blip plus a Retry-After push heartbeats past the
     * panel's stale threshold for no load benefit.
     *
     * @param array|null $payload Listener status sent as the JSON body. The
     *                            Plugins API ignores unknown body fields, so
     *                            older servers accept it unchanged.
     */
    function rf_http_rf_heartbeat(string $rfBaseUrl, string $token, int $timeout = 5, ?array $payload = null): bool {
        $url = $rfBaseUrl . '/fppHeartbeat';
        $headers = [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Accept' => 'application/json',
            'remotetoken' => $token,
        ];
        $json = empty($payload) ? '{}' : json_encode($payload);
        $body = _rf_http_rf_curl('POST', $url, $headers, $json === false ? '{}' : $json, $timeout, false);
        return $body !== null;
    }

    // --- Remote Falcon backoff gate ---
    //
    // Consecutive failed RF calls (no response, 429, 5xx) pause further RF
    // calls for a full-jitter exponential delay (rf_backoff_next_state).
    // While paused, gated calls return null at once without touching the
    // network, the same as a failed call, so callers need no new branches.
    // State lives in $GLOBALS['rfBackoff'] for the listener process; tests
    // clear it with rf_backoff_reset().

    /**
     * Monotonic seconds, so an NTP step (common on a Pi with no RTC) can't
     * stretch or cancel a backoff.
     */
    function rf_monotonic_seconds(): float {
        return function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true);
    }

    function rf_backoff_reset(): void {
        $GLOBALS['rfBackoff'] = ['failures' => 0, 'until' => 0.0, 'delay' => 0.0];
    }

    function rf_backoff_state(): array {
        if (!isset($GLOBALS['rfBackoff']) || !is_array($GLOBALS['rfBackoff'])) {
            rf_backoff_reset();
        }
        return $GLOBALS['rfBackoff'];
    }

    /**
     * Whether RF calls are currently paused by the backoff.
     */
    function rf_backoff_active(?float $now = null): bool {
        $state = rf_backoff_state();
        return ($now ?? rf_monotonic_seconds()) < (float) $state['until'];
    }

    /**
     * Record the outcome of one gated RF call and log streak transitions:
     * the first failure and the recovery at normal level, the failures in
     * between at verbose level.
     */
    function _rf_backoff_record(string $url, int $status, ?string $retryAfterHeader): void {
        $prev = rf_backoff_state();
        $retryAfter = rf_parse_retry_after($retryAfterHeader, time());
        $next = rf_backoff_next_state($prev, $status, $retryAfter, rf_monotonic_seconds(), mt_rand() / mt_getrandmax());
        $GLOBALS['rfBackoff'] = $next;

        if (!function_exists('logEntry')) {
            return;
        }
        $endpoint = (string) parse_url($url, PHP_URL_PATH);
        if ($next['failures'] === 0) {
            if ($prev['failures'] > 0) {
                logEntry("Remote Falcon is responding again (after " . $prev['failures'] . " failed call" . ($prev['failures'] === 1 ? "" : "s") . ")");
            }
            return;
        }
        $what = $status === 0 ? "no response" : "HTTP " . $status;
        $line = "Remote Falcon call to " . $endpoint . " failed (" . $what . "). Pausing Remote Falcon calls for "
            . number_format($next['delay'], 1) . "s"
            . ($retryAfter !== null ? " (server asked for " . $retryAfter . "s)" : "")
            . ". The show keeps running from the FPP schedule.";
        if ($next['failures'] === 1) {
            logEntry("WARNING - " . $line);
        } else {
            logEntry_verbose("WARNING - " . $line . " Consecutive failures: " . $next['failures']);
        }
    }

    // --- Internal helpers (RF) ---

    function _rf_http_rf_get(string $rfBaseUrl, string $endpoint, string $token, int $timeout): ?stdClass {
        $url = $rfBaseUrl . $endpoint;
        $headers = ['remotetoken' => $token];
        $body = _rf_http_rf_curl('GET', $url, $headers, null, $timeout);
        $decoded = rf_http_decode_json($body);
        return $decoded instanceof stdClass ? $decoded : null;
    }

    function _rf_http_rf_post(string $rfBaseUrl, string $endpoint, string $token, array $payload, int $timeout): bool {
        $url = $rfBaseUrl . $endpoint;
        $headers = [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Accept' => 'application/json',
            'remotetoken' => $token,
        ];
        $body = _rf_http_rf_curl('POST', $url, $headers, json_encode($payload), $timeout);
        return $body !== null;
    }

    /**
     * Returns a long-lived cURL handle. The same handle is reused across
     * all RF API calls in the listener process, which lets curl's
     * connection pool reuse the underlying TCP+TLS connection. For a show
     * with frequent RF traffic, this typically saves 100-400ms per call
     * (TLS handshake elimination) once the connection is warm.
     *
     * Tests can call _rf_http_rf_curl_reset() between scenarios to force
     * a fresh handle.
     */
    function &_rf_http_rf_curl_handle() {
        static $ch = null;
        if ($ch === null) {
            $ch = curl_init();
        }
        return $ch;
    }

    function _rf_http_rf_curl_reset(): void {
        // PHP 8.0+ treats cURL handles as objects and garbage-collects them
        // when the last reference is released. Setting the static slot to
        // null is sufficient — calling curl_close() emits a deprecation
        // warning on 8.5+.
        $ch = &_rf_http_rf_curl_handle();
        $ch = null;
    }

    /**
     * Issue a request via the persistent cURL handle. Returns response
     * body on 2xx, null on transport error or non-2xx. Same return-value
     * contract as rf_http_request() so callers don't care which transport
     * is in use.
     *
     * $gated calls honor and feed the backoff: while it is active they
     * return null without sending anything. Only the heartbeat passes false.
     */
    function _rf_http_rf_curl(string $method, string $url, array $headers, ?string $body, int $timeout, bool $gated = true): ?string {
        if ($gated && rf_backoff_active()) {
            return null;
        }
        $ch = &_rf_http_rf_curl_handle();
        // Reset per-request options but KEEP the connection pool by reusing
        // the same handle (curl_close would tear it down).
        curl_reset($ch);
        $hdrs = [];
        foreach ($headers as $name => $value) {
            $hdrs[] = "$name: $value";
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) min($timeout, 5)),
            CURLOPT_FOLLOWLOCATION => false,
            // Allow the handle's TCP/TLS connection to be reused across calls.
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HTTPHEADER => $hdrs,
        ]);
        $retryAfter = null;
        if ($gated) {
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$retryAfter) {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, strlen('Retry-After:')));
                }
                return strlen($line);
            });
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = $response === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            // A transport failure can leave the pooled connection dead (a
            // mid-session network drop blackholes it rather than closing it),
            // and curl would keep reusing it, so every later call would wait
            // out the full timeout. Drop the handle so the next call connects
            // fresh and fails fast at connect time instead.
            _rf_http_rf_curl_reset();
        }
        if ($gated) {
            _rf_backoff_record($url, $status, $retryAfter);
        }
        if ($response === false || $status < 200 || $status >= 300) {
            return null;
        }
        return $response;
    }
}
