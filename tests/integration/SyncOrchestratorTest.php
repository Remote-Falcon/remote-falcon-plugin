<?php

/**
 * Integration tests for rf_sync_playlist_to_rf() — the effectful sync
 * orchestrator in lib/sync_builder.php (fetch playlist from FPP → build the
 * rich payload → POST to the RF plugins API). Closes the CLAUDE.md Layer 1/2
 * gap flagged in the 2026-07-16 release review: the pure builder had unit
 * tests but the orchestrator (and thus the wire behavior the auto-sync and
 * command paths depend on) had none.
 *
 * Uses the standard two-MockServer harness: fppMock plays the local FPP API
 * (playlist + media metadata), rfMock plays the RF plugins API and records
 * the POSTed payload.
 */
final class SyncOrchestratorTest extends IntegrationTestCase {

    private function cfg(array $raw = []): array {
        return [
            'remoteToken' => 'tok',
            'pluginsApiPath' => $this->rfMock->getBaseUrl(),
            'raw' => $raw,
        ];
    }

    private function fppOpts(): array {
        return ['fppBase' => $this->fppMock->getBaseUrl()];
    }

    public function testFullSync_postsRichPayloadWithMetadata(): void {
        $this->fppMock->setRoute('/api/playlist/MyShow', [
            'body' => [
                'playlistInfo' => ['total_items' => 3],
                'mainPlaylist' => [
                    ['type' => 'both', 'sequenceName' => 'a.fseq', 'mediaName' => 'a.mp3', 'duration' => 30],
                    ['type' => 'command', 'note' => 'Fog On', 'duration' => 1],
                    ['type' => 'media', 'mediaName' => 'b.mp3', 'duration' => 200],
                ],
            ],
        ]);
        $this->fppMock->setRoute('/api/media/a.mp3/meta', [
            'body' => ['format' => ['tags' => [
                'title' => 'Song A',
                'artist' => 'Artist A',
                'comment' => '"https://x.com/a.png",',
            ]]],
        ]);
        $this->rfMock->setRoute('/syncPlaylists', ['body' => ['message' => 'Success']]);

        $result = rf_sync_playlist_to_rf('MyShow', $this->cfg(['autoSyncMetadata' => 'true']), $this->fppOpts());

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['count']);

        $recordings = $this->rfMock->getRecordings();
        $this->assertCount(1, $recordings);
        $rec = $recordings[0];
        $this->assertSame('POST', $rec['method']);
        $this->assertSame('tok', $rec['headers']['remotetoken'] ?? null);

        $payload = json_decode($rec['body'], true);
        $this->assertSame([
            [
                'playlistName' => 'a',
                'playlistDuration' => 30,
                'playlistIndex' => 1,
                'playlistType' => 'SEQUENCE',
                'mediaTitle' => 'Song A',
                'mediaArtist' => 'Artist A',
                'mediaAlbumUrl' => 'https://x.com/a.png',
            ],
            [
                'playlistName' => 'Fog On',
                'playlistDuration' => 0,
                'playlistIndex' => 2,
                'playlistType' => 'COMMAND',
            ],
            [
                'playlistName' => 'b',
                'playlistDuration' => 0,
                'playlistIndex' => 3,
                'playlistType' => 'MEDIA',
            ],
        ], $payload['playlists']);
    }

    public function testMetadataSettingOff_omitsMediaFields(): void {
        $this->fppMock->setRoute('/api/playlist/MyShow', [
            'body' => [
                'mainPlaylist' => [
                    ['type' => 'both', 'sequenceName' => 'a.fseq', 'mediaName' => 'a.mp3', 'duration' => 30],
                ],
            ],
        ]);
        $this->rfMock->setRoute('/syncPlaylists', ['body' => ['message' => 'Success']]);

        // autoSyncMetadata unset in raw settings -> defaults to false; the
        // media meta endpoint is deliberately NOT routed, so a fetch would 404
        // and pollute the payload with empty fields if the flag leaked true.
        $result = rf_sync_playlist_to_rf('MyShow', $this->cfg(), $this->fppOpts());

        $this->assertTrue($result['ok']);
        $payload = json_decode($this->rfMock->getRecordings()[0]['body'], true);
        $this->assertArrayNotHasKey('mediaTitle', $payload['playlists'][0]);
    }

    public function testPlaylistFetchFailure_returnsErrorWithoutPosting(): void {
        // No fpp route -> 404 -> playlist_fetch_failed, and RF must not be hit.
        $result = rf_sync_playlist_to_rf('Ghost', $this->cfg(), $this->fppOpts());

        $this->assertFalse($result['ok']);
        $this->assertSame('playlist_fetch_failed', $result['error']);
        $this->assertCount(0, $this->rfMock->getRecordings());
    }

    public function testEmptyPlaylist_returnsErrorWithoutPosting(): void {
        $this->fppMock->setRoute('/api/playlist/Pauses', [
            'body' => ['mainPlaylist' => [['type' => 'pause', 'duration' => 10]]],
        ]);

        $result = rf_sync_playlist_to_rf('Pauses', $this->cfg(), $this->fppOpts());

        $this->assertFalse($result['ok']);
        $this->assertSame('empty_playlist', $result['error']);
        $this->assertCount(0, $this->rfMock->getRecordings());
    }

    public function testRfPostFailure_returnsSyncFailed(): void {
        $this->fppMock->setRoute('/api/playlist/MyShow', [
            'body' => ['mainPlaylist' => [['type' => 'sequence', 'sequenceName' => 'a.fseq', 'duration' => 30]]],
        ]);
        $this->rfMock->setRoute('/syncPlaylists', ['status' => 500, 'body' => 'oops']);

        $result = rf_sync_playlist_to_rf('MyShow', $this->cfg(), $this->fppOpts());

        $this->assertFalse($result['ok']);
        $this->assertSame('sync_failed', $result['error']);
    }
}
