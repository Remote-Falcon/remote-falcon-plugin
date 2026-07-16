<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared sync-payload builder in lib/sync_builder.php.
 *
 * The builder is the single source of truth for the /syncPlaylists payload
 * (issue #158) — these tests pin its output to what the browser path
 * historically sent (js/remote_falcon_ui.js syncPlaylistToRF), item type by
 * item type, so the headless command and the UI can never drift again.
 */
final class SyncBuilderTest extends TestCase {

    /** Metadata fetcher stub: returns canned FPP /api/media/<name>/meta shapes. */
    private function metaFetcher(array $byMediaName): callable {
        return function (string $mediaName) use ($byMediaName): ?array {
            return $byMediaName[$mediaName] ?? null;
        };
    }

    private function neverCalledFetcher(): callable {
        return function (string $mediaName): ?array {
            $this->fail('metadata fetcher must not be called');
        };
    }

    // -------- rf_strip_file_extension --------

    public function testStripFileExtension(): void {
        $this->assertSame('song', rf_strip_file_extension('song.fseq'));
        $this->assertSame('my.song', rf_strip_file_extension('my.song.mp3'));
        $this->assertSame('noext', rf_strip_file_extension('noext'));
        $this->assertSame('', rf_strip_file_extension(''));
    }

    // -------- rf_is_image_url / rf_parse_album_art_url --------

    public function testIsImageUrl(): void {
        $this->assertTrue(rf_is_image_url('https://x.com/a.png'));
        $this->assertTrue(rf_is_image_url('http://x.com/a.JPG?size=2'));
        $this->assertTrue(rf_is_image_url('https://x.com/a.jpeg'));
        $this->assertFalse(rf_is_image_url('https://x.com/a.html'));
        $this->assertFalse(rf_is_image_url('ftp://x.com/a.png'));
        $this->assertFalse(rf_is_image_url(''));
    }

    public function testParseAlbumArtUrl_trimsTrailingCommaAndQuotes(): void {
        $this->assertSame(
            'https://x.com/art.png',
            rf_parse_album_art_url('"https://x.com/art.png",')
        );
        $this->assertSame(
            'https://x.com/art.png',
            rf_parse_album_art_url('  https://x.com/art.png  ')
        );
    }

    public function testParseAlbumArtUrl_rejectsNonImageOrEmpty(): void {
        $this->assertSame('', rf_parse_album_art_url('not a url'));
        $this->assertSame('', rf_parse_album_art_url(null));
        $this->assertSame('', rf_parse_album_art_url(''));
    }

    // -------- rf_build_sync_payload: item types --------

    public function testSequenceItem(): void {
        $items = [
            ['type' => 'sequence', 'sequenceName' => 'a.fseq', 'duration' => 30],
        ];
        $out = rf_build_sync_payload($items, false, $this->neverCalledFetcher());
        $this->assertSame([[
            'playlistName' => 'a',
            'playlistDuration' => 30,
            'playlistIndex' => 1,
            'playlistType' => 'SEQUENCE',
        ]], $out);
    }

    public function testBothItem_withoutMetadata(): void {
        $items = [
            ['type' => 'both', 'sequenceName' => 'a.fseq', 'mediaName' => 'a.mp3', 'duration' => 42],
        ];
        $out = rf_build_sync_payload($items, false, $this->neverCalledFetcher());
        $this->assertSame([[
            'playlistName' => 'a',
            'playlistDuration' => 42,
            'playlistIndex' => 1,
            'playlistType' => 'SEQUENCE',
        ]], $out);
    }

    public function testBothItem_withMetadata(): void {
        $items = [
            ['type' => 'both', 'sequenceName' => 'a.fseq', 'mediaName' => 'a.mp3', 'duration' => 42],
        ];
        $meta = $this->metaFetcher([
            'a.mp3' => ['format' => ['tags' => [
                'title' => 'Song A',
                'artist' => 'Artist A',
                'comment' => '"https://x.com/a.png",',
            ]]],
        ]);
        $out = rf_build_sync_payload($items, true, $meta);
        $this->assertSame([[
            'playlistName' => 'a',
            'playlistDuration' => 42,
            'playlistIndex' => 1,
            'playlistType' => 'SEQUENCE',
            'mediaTitle' => 'Song A',
            'mediaArtist' => 'Artist A',
            'mediaAlbumUrl' => 'https://x.com/a.png',
        ]], $out);
    }

    public function testBothItem_metadataFetchFailsFallsBackToEmptyFields(): void {
        $items = [
            ['type' => 'both', 'sequenceName' => 'a.fseq', 'mediaName' => 'a.mp3', 'duration' => 42],
        ];
        $out = rf_build_sync_payload($items, true, $this->metaFetcher([]));
        $this->assertSame([[
            'playlistName' => 'a',
            'playlistDuration' => 42,
            'playlistIndex' => 1,
            'playlistType' => 'SEQUENCE',
            'mediaTitle' => '',
            'mediaArtist' => '',
            'mediaAlbumUrl' => '',
        ]], $out);
    }

    public function testMediaItem_durationIsZeroAndNameFromMedia(): void {
        $items = [
            ['type' => 'media', 'mediaName' => 'track.mp3', 'duration' => 180],
        ];
        $out = rf_build_sync_payload($items, false, $this->neverCalledFetcher());
        $this->assertSame([[
            'playlistName' => 'track',
            'playlistDuration' => 0,
            'playlistIndex' => 1,
            'playlistType' => 'MEDIA',
        ]], $out);
    }

    public function testCommandItem_usesNoteAsName(): void {
        $items = [
            ['type' => 'command', 'note' => 'Fog On', 'duration' => 5],
        ];
        $out = rf_build_sync_payload($items, false, $this->neverCalledFetcher());
        $this->assertSame([[
            'playlistName' => 'Fog On',
            'playlistDuration' => 0,
            'playlistIndex' => 1,
            'playlistType' => 'COMMAND',
        ]], $out);
    }

    public function testCommandItem_withoutNoteIsSkippedButStillConsumesIndex(): void {
        $items = [
            ['type' => 'command', 'duration' => 5],
            ['type' => 'sequence', 'sequenceName' => 'b.fseq', 'duration' => 10],
        ];
        $out = rf_build_sync_payload($items, false, $this->neverCalledFetcher());
        // Matches the browser behavior: the unsyncable item is skipped but
        // playlistIndex still reflects the FPP playlist position.
        $this->assertCount(1, $out);
        $this->assertSame('b', $out[0]['playlistName']);
        $this->assertSame(2, $out[0]['playlistIndex']);
    }

    public function testUnknownItemTypeIsSkipped(): void {
        $items = [
            ['type' => 'pause', 'duration' => 10],
            ['type' => 'sequence', 'sequenceName' => 'b.fseq', 'duration' => 10],
        ];
        $out = rf_build_sync_payload($items, false, $this->neverCalledFetcher());
        $this->assertCount(1, $out);
        $this->assertSame(2, $out[0]['playlistIndex']);
    }

    public function testMixedPlaylist_indexesAreSequentialFppPositions(): void {
        $items = [
            ['type' => 'sequence', 'sequenceName' => 'a.fseq', 'duration' => 30],
            ['type' => 'media', 'mediaName' => 'b.mp3', 'duration' => 200],
            ['type' => 'command', 'note' => 'PSA', 'duration' => 1],
            ['type' => 'both', 'sequenceName' => 'd.fseq', 'mediaName' => 'd.mp3', 'duration' => 60],
        ];
        $out = rf_build_sync_payload($items, false, $this->neverCalledFetcher());
        $this->assertSame(['a', 'b', 'PSA', 'd'], array_column($out, 'playlistName'));
        $this->assertSame([1, 2, 3, 4], array_column($out, 'playlistIndex'));
        $this->assertSame(['SEQUENCE', 'MEDIA', 'COMMAND', 'SEQUENCE'], array_column($out, 'playlistType'));
    }

    public function testEmptyPlaylistReturnsEmptyArray(): void {
        $this->assertSame([], rf_build_sync_payload([], true, $this->neverCalledFetcher()));
    }

    public function testFloatDurationIsTruncatedToInt(): void {
        $items = [
            ['type' => 'sequence', 'sequenceName' => 'a.fseq', 'duration' => 30.7],
        ];
        $out = rf_build_sync_payload($items, false, $this->neverCalledFetcher());
        $this->assertSame(30, $out[0]['playlistDuration']);
    }

    // -------- rf_sync_hosted_item_limit_exceeded --------

    public function testHostedItemLimit(): void {
        $this->assertTrue(rf_sync_hosted_item_limit_exceeded('https://remotefalcon.com/remote-falcon-plugins-api', 501));
        $this->assertFalse(rf_sync_hosted_item_limit_exceeded('https://remotefalcon.com/remote-falcon-plugins-api', 500));
        // Self-hosted: no cap.
        $this->assertFalse(rf_sync_hosted_item_limit_exceeded('https://myshow.example.com/plugins-api', 9999));
    }
}
