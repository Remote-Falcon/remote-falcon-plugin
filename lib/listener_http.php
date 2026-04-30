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
     * Issue a single HTTP request and return the response body, or null
     * on transport error / timeout / non-2xx response.
     */
    function rf_http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): ?string {
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
            return null;
        }
        // Read response headers. PHP 8.4+ exposes http_get_last_response_headers();
        // older versions only expose the locally scoped $http_response_header,
        // which is deprecated in 8.5+. Use get_defined_vars() to read the
        // magic variable on older PHP without writing its name in source
        // (which would trip 8.5's static deprecation warning).
        if (function_exists('http_get_last_response_headers')) {
            $responseHeaders = http_get_last_response_headers() ?? [];
        } else {
            $definedVars = get_defined_vars();
            $responseHeaders = $definedVars['http_response_header'] ?? [];
        }
        $statusCode = 0;
        if (isset($responseHeaders[0])) {
            if (preg_match('#HTTP/[0-9.]+\s+(\d+)#', $responseHeaders[0], $m)) {
                $statusCode = (int) $m[1];
            }
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            return null;
        }
        return $response;
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

    function rf_http_fpp_get_status(string $fppBaseUrl, int $timeout = 5): ?stdClass {
        $url = $fppBaseUrl . '/api/system/status';
        $body = rf_http_request('GET', $url, [], null, $timeout);
        $decoded = rf_http_decode_json($body);
        return $decoded instanceof stdClass ? $decoded : null;
    }

    function rf_http_fpp_get_playlist(string $fppBaseUrl, string $playlistEncoded, int $timeout = 5): ?stdClass {
        $url = $fppBaseUrl . '/api/playlist/' . $playlistEncoded;
        $body = rf_http_request('GET', $url, [], null, $timeout);
        $decoded = rf_http_decode_json($body);
        return $decoded instanceof stdClass ? $decoded : null;
    }

    function rf_http_fpp_insert_immediate(string $fppBaseUrl, string $playlistEncoded, int $index, int $timeout = 5): bool {
        $url = $fppBaseUrl . '/api/command/Insert%20Playlist%20Immediate/' . $playlistEncoded . '/' . $index . '/' . $index;
        $body = rf_http_request('GET', $url, [], null, $timeout);
        return $body !== null;
    }

    function rf_http_fpp_insert_after_current(string $fppBaseUrl, string $playlistEncoded, int $index, int $timeout = 5): bool {
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
        $body = rf_http_request('DELETE', $url, $headers, null, $timeout);
        return $body !== null;
    }

    // --- Internal helpers ---

    function _rf_http_rf_get(string $rfBaseUrl, string $endpoint, string $token, int $timeout): ?stdClass {
        $url = $rfBaseUrl . $endpoint;
        $headers = ['remotetoken' => $token];
        $body = rf_http_request('GET', $url, $headers, null, $timeout);
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
        $body = rf_http_request('POST', $url, $headers, json_encode($payload), $timeout);
        return $body !== null;
    }
}
