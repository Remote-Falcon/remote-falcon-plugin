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

    function rf_http_fpp_get_status(string $fppBaseUrl, int $timeout = 1): ?stdClass {
        $url = $fppBaseUrl . '/api/system/status';
        $body = rf_http_request('GET', $url, [], null, $timeout);
        $decoded = rf_http_decode_json($body);
        return $decoded instanceof stdClass ? $decoded : null;
    }

    function rf_http_fpp_get_playlist(string $fppBaseUrl, string $playlistEncoded, int $timeout = 1): ?stdClass {
        $url = $fppBaseUrl . '/api/playlist/' . $playlistEncoded;
        $body = rf_http_request('GET', $url, [], null, $timeout);
        $decoded = rf_http_decode_json($body);
        return $decoded instanceof stdClass ? $decoded : null;
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

    function rf_http_rf_heartbeat(string $rfBaseUrl, string $token, int $timeout = 5): bool {
        $url = $rfBaseUrl . '/fppHeartbeat';
        $headers = [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Accept' => 'application/json',
            'remotetoken' => $token,
        ];
        $body = _rf_http_rf_curl('POST', $url, $headers, '{}', $timeout);
        return $body !== null;
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
     */
    function _rf_http_rf_curl(string $method, string $url, array $headers, ?string $body, int $timeout): ?string {
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
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status < 200 || $status >= 300) {
            return null;
        }
        return $response;
    }
}
