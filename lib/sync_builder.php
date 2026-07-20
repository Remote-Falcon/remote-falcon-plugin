<?php
// Shared playlist-sync payload builder (issue #158).
//
// Single source of truth for the /syncPlaylists payload. Consumed by:
//   - sync_playlists.php           (UI "Sync with RF" button, server-side)
//   - commands/update_remote_playlist.php  (headless FPP command)
//
// Before this file existed the browser (js/remote_falcon_ui.js) and the
// command each built their own payload and drifted: the command sent no
// playlistType, no media metadata, and dropped command-type items. The
// builder pins the rich shape for both callers.
//
// rf_build_sync_payload() and the small helpers above it are PURE (the
// metadata fetcher is injected) — unit-tested in tests/SyncBuilderTest.php.
// rf_sync_playlist_to_rf() is the effectful orchestrator (FPP API + RF API).

if (!function_exists('rf_build_sync_payload')) {

    /** Strip the last file extension: "my.song.mp3" -> "my.song". */
    function rf_strip_file_extension(string $filename): string {
        return preg_replace('/\.[^.]+$/', '', $filename);
    }

    /** True when $url is an http(s) URL ending in a common image extension. */
    function rf_is_image_url(string $url): bool {
        return preg_match('/^https?:\/\/\S+\.(png|jpe?g|gif|webp|bmp|svg)(\?\S*)?$/i', $url) === 1;
    }

    /**
     * Extract an album-art URL from an FPP media comment tag. The tag value
     * is often wrapped in quotes and/or has a trailing comma (ffprobe
     * artifact). Returns "" unless the cleaned value is an image URL.
     */
    function rf_parse_album_art_url(?string $commentTag): string {
        if ($commentTag === null || $commentTag === '') {
            return '';
        }
        $candidate = trim($commentTag);
        if (substr($candidate, -1) === ',') {
            $candidate = trim(substr($candidate, 0, -1));
        }
        if (strlen($candidate) >= 2 && $candidate[0] === '"' && substr($candidate, -1) === '"') {
            $candidate = trim(substr($candidate, 1, -1));
        }
        return rf_is_image_url($candidate) ? $candidate : '';
    }

    /**
     * Build the /syncPlaylists payload from a decoded FPP mainPlaylist array.
     *
     * Mirrors the historical browser behavior exactly:
     *  - 'both'      -> SEQUENCE (name from sequenceName); media metadata
     *                   included only when $syncMetadata is true
     *  - 'sequence'  -> SEQUENCE (name from sequenceName)
     *  - 'media'     -> MEDIA, duration 0 (name from mediaName)
     *  - 'command'   -> COMMAND, duration 0 (name from note; skipped if no note)
     *  - anything else (pause, ...) -> skipped
     * Skipped items still consume a playlistIndex so indexes always reflect
     * true FPP playlist positions.
     *
     * @param array    $mainPlaylist  Decoded items (assoc arrays) from FPP's
     *                                /api/playlist/<name> mainPlaylist field.
     * @param bool     $syncMetadata  Include mediaTitle/mediaArtist/mediaAlbumUrl
     *                                for 'both' items.
     * @param callable $fetchMediaMeta fn(string $mediaName): ?array — decoded
     *                                FPP /api/media/<name>/meta response, or
     *                                null on failure.
     * @return array List of assoc arrays matching SyncPlaylistDetails.
     */
    function rf_build_sync_payload(array $mainPlaylist, bool $syncMetadata, callable $fetchMediaMeta): array {
        $sequences = [];
        $playlistIndex = 1;
        foreach ($mainPlaylist as $item) {
            $type = isset($item['type']) ? $item['type'] : '';
            if ($type === 'both') {
                $entry = [
                    'playlistName' => rf_strip_file_extension(isset($item['sequenceName']) ? $item['sequenceName'] : ''),
                    'playlistDuration' => (int) (isset($item['duration']) ? $item['duration'] : 0),
                    'playlistIndex' => $playlistIndex,
                    'playlistType' => 'SEQUENCE',
                ];
                if ($syncMetadata) {
                    $meta = $fetchMediaMeta(isset($item['mediaName']) ? $item['mediaName'] : '');
                    $tags = isset($meta['format']['tags']) && is_array($meta['format']['tags'])
                        ? $meta['format']['tags'] : [];
                    $entry['mediaTitle'] = isset($tags['title']) ? (string) $tags['title'] : '';
                    $entry['mediaArtist'] = isset($tags['artist']) ? (string) $tags['artist'] : '';
                    $entry['mediaAlbumUrl'] = rf_parse_album_art_url(isset($tags['comment']) ? (string) $tags['comment'] : null);
                }
                $sequences[] = $entry;
            } else if ($type === 'sequence') {
                $sequences[] = [
                    'playlistName' => rf_strip_file_extension(isset($item['sequenceName']) ? $item['sequenceName'] : ''),
                    'playlistDuration' => (int) (isset($item['duration']) ? $item['duration'] : 0),
                    'playlistIndex' => $playlistIndex,
                    'playlistType' => 'SEQUENCE',
                ];
            } else if ($type === 'media') {
                $sequences[] = [
                    'playlistName' => rf_strip_file_extension(isset($item['mediaName']) ? $item['mediaName'] : ''),
                    'playlistDuration' => 0,
                    'playlistIndex' => $playlistIndex,
                    'playlistType' => 'MEDIA',
                ];
            } else if ($type === 'command' && isset($item['note']) && $item['note'] !== null) {
                $sequences[] = [
                    'playlistName' => (string) $item['note'],
                    'playlistDuration' => 0,
                    'playlistIndex' => $playlistIndex,
                    'playlistType' => 'COMMAND',
                ];
            }
            $playlistIndex++;
        }
        return $sequences;
    }

    /**
     * Hosted-service guard: remotefalcon.com caps a sync at 500 items.
     * Self-hosted instances have no client-side cap.
     */
    function rf_sync_hosted_item_limit_exceeded(string $pluginsApiPath, int $totalItems): bool {
        return strpos($pluginsApiPath, 'remotefalcon.com') !== false && $totalItems > 500;
    }

    /**
     * Full playlist sync: fetch the playlist from the local FPP API, build
     * the rich payload, POST it to <pluginsApiPath>/syncPlaylists.
     *
     * Does NOT write plugin settings or restart the listener — callers own
     * those side effects (the command switches remotePlaylist and restarts;
     * auto-sync re-syncs the same playlist and must NOT restart).
     *
     * @param string $playlistName FPP playlist to sync.
     * @param array  $cfg          From rf_load_settings(): remoteToken,
     *                             pluginsApiPath, raw.
     * @param array  $opts         Optional: 'syncMetadata' (bool; default =
     *                             the saved autoSyncMetadata setting),
     *                             'fppBase' (string; default http://127.0.0.1).
     * @return array ['ok' => bool, 'error' => ?string, 'count' => int]
     */
    function rf_sync_playlist_to_rf(string $playlistName, array $cfg, array $opts = []): array {
        $fppBase = isset($opts['fppBase']) ? rtrim($opts['fppBase'], '/') : 'http://127.0.0.1';
        $pluginsApiPath = rtrim($cfg['pluginsApiPath'], '/');
        if ($pluginsApiPath === '' || strlen($cfg['remoteToken']) <= 1) {
            return ['ok' => false, 'error' => 'no_token_or_api_path', 'count' => 0];
        }

        $raw = rf_http_request('GET', $fppBase . '/api/playlist/' . rawurlencode($playlistName), [], null, 15);
        $playlistData = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($playlistData) || !isset($playlistData['mainPlaylist']) || !is_array($playlistData['mainPlaylist'])) {
            return ['ok' => false, 'error' => 'playlist_fetch_failed', 'count' => 0];
        }

        $totalItems = isset($playlistData['playlistInfo']['total_items'])
            ? (int) $playlistData['playlistInfo']['total_items']
            : count($playlistData['mainPlaylist']);
        if (rf_sync_hosted_item_limit_exceeded($pluginsApiPath, $totalItems)) {
            return ['ok' => false, 'error' => 'too_many_items', 'count' => 0];
        }

        if (isset($opts['syncMetadata'])) {
            $syncMetadata = (bool) $opts['syncMetadata'];
        } else {
            $saved = isset($cfg['raw']['autoSyncMetadata']) ? urldecode($cfg['raw']['autoSyncMetadata']) : 'false';
            $syncMetadata = ($saved === 'true');
        }

        $fetchMediaMeta = function (string $mediaName) use ($fppBase): ?array {
            if ($mediaName === '') {
                return null;
            }
            $raw = rf_http_request('GET', $fppBase . '/api/media/' . rawurlencode($mediaName) . '/meta', [], null, 10);
            $decoded = $raw !== null ? json_decode($raw, true) : null;
            return is_array($decoded) ? $decoded : null;
        };

        $sequences = rf_build_sync_payload($playlistData['mainPlaylist'], $syncMetadata, $fetchMediaMeta);
        if (count($sequences) === 0) {
            return ['ok' => false, 'error' => 'empty_playlist', 'count' => 0];
        }

        $response = rf_http_request(
            'POST',
            $pluginsApiPath . '/syncPlaylists',
            ['Content-Type' => 'application/json', 'remotetoken' => $cfg['remoteToken']],
            json_encode(['playlists' => $sequences]),
            30
        );
        if ($response === null) {
            return ['ok' => false, 'error' => 'sync_failed', 'count' => 0];
        }
        return ['ok' => true, 'error' => null, 'count' => count($sequences)];
    }
}
