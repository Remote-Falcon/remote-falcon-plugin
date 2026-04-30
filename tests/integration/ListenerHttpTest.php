<?php

/**
 * Integration tests for lib/listener_http.php. Each test spins up a fresh
 * pair of mock servers (FPP + RF), configures expected routes, calls a
 * lib function, and asserts on the response and on what the mock recorded.
 */
final class ListenerHttpTest extends IntegrationTestCase {

    // -------- FPP localhost API --------

    public function testFppGetStatus_returnsDecodedObject(): void {
        $this->fppMock->setRoute('/api/system/status', [
            'body' => [
                'status_name' => 'playing',
                'current_sequence' => 'song.fseq',
                'seconds_remaining' => 12,
            ],
        ]);
        $result = rf_http_fpp_get_status($this->fppMock->getBaseUrl());
        $this->assertNotNull($result);
        $this->assertSame('playing', $result->status_name);
        $this->assertSame('song.fseq', $result->current_sequence);
        $this->assertSame(12, $result->seconds_remaining);
    }

    public function testFppGetStatus_returnsNullOn404(): void {
        // No route configured → router returns 404
        $this->assertNull(rf_http_fpp_get_status($this->fppMock->getBaseUrl()));
    }

    public function testFppGetStatus_returnsNullOnInvalidJson(): void {
        $this->fppMock->setRoute('/api/system/status', [
            'body' => 'not valid json',
            'contentType' => 'text/plain',
        ]);
        $this->assertNull(rf_http_fpp_get_status($this->fppMock->getBaseUrl()));
    }

    public function testFppGetStatus_returnsNullOnTimeout(): void {
        $this->fppMock->setRoute('/api/system/status', [
            'body' => ['status_name' => 'playing'],
            'delayMs' => 1500,
        ]);
        // Timeout 1s, mock delays 1.5s → must return null
        $start = microtime(true);
        $result = rf_http_fpp_get_status($this->fppMock->getBaseUrl(), 1);
        $elapsed = microtime(true) - $start;
        $this->assertNull($result);
        $this->assertLessThan(1.4, $elapsed, 'Timeout should bail before mock delay completes');
    }

    public function testFppGetPlaylist_returnsDecodedObject(): void {
        $this->fppMock->setRoute('/api/playlist/MyShow', [
            'body' => [
                'mainPlaylist' => [
                    ['sequenceName' => 'a.fseq'],
                    ['sequenceName' => 'b.fseq'],
                ],
            ],
        ]);
        $result = rf_http_fpp_get_playlist($this->fppMock->getBaseUrl(), 'MyShow');
        $this->assertNotNull($result);
        $this->assertCount(2, $result->mainPlaylist);
    }

    public function testFppInsertImmediate_buildsCorrectUrlAndReturnsTrue(): void {
        $this->fppMock->setRoute('/api/command/Insert Playlist Immediate*', ['body' => 'ok']);
        $ok = rf_http_fpp_insert_immediate($this->fppMock->getBaseUrl(), 'MyShow', 3);
        $this->assertTrue($ok);

        $recordings = $this->fppMock->getRecordings();
        $this->assertCount(1, $recordings);
        $this->assertSame('GET', $recordings[0]['method']);
        $this->assertSame('/api/command/Insert Playlist Immediate/MyShow/3/3', $recordings[0]['path']);
    }

    public function testFppInsertAfterCurrent_buildsCorrectUrlAndReturnsTrue(): void {
        $this->fppMock->setRoute('/api/command/Insert Playlist After Current*', ['body' => 'ok']);
        $ok = rf_http_fpp_insert_after_current($this->fppMock->getBaseUrl(), 'MyShow', 5);
        $this->assertTrue($ok);

        $recordings = $this->fppMock->getRecordings();
        $this->assertCount(1, $recordings);
        $this->assertSame('/api/command/Insert Playlist After Current/MyShow/5/5', $recordings[0]['path']);
    }

    public function testFppInsert_returnsFalseOn404(): void {
        // No route → 404 → null body → false return
        $this->assertFalse(rf_http_fpp_insert_immediate($this->fppMock->getBaseUrl(), 'MyShow', 1));
    }

    // -------- Remote Falcon plugins API --------

    public function testRfGetPreferences_sendsTokenHeaderAndReturnsDecoded(): void {
        $this->rfMock->setRoute('/remotePreferences', [
            'body' => ['viewerControlMode' => 'jukebox'],
        ]);
        $result = rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'mytoken');
        $this->assertNotNull($result);
        $this->assertSame('jukebox', $result->viewerControlMode);

        $recordings = $this->rfMock->getRecordings();
        $this->assertCount(1, $recordings);
        $this->assertSame('GET', $recordings[0]['method']);
        $this->assertSame('mytoken', $recordings[0]['headers']['remotetoken'] ?? null);
    }

    public function testRfGetHighestVoted_returnsDecoded(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', [
            'body' => ['winningPlaylist' => 'happy.fseq', 'playlistIndex' => 7],
        ]);
        $result = rf_http_rf_get_highest_voted($this->rfMock->getBaseUrl(), 'tok');
        $this->assertSame('happy.fseq', $result->winningPlaylist);
        $this->assertSame(7, $result->playlistIndex);
    }

    public function testRfGetNextInQueue_includesUpdateQueueQuery(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', [
            'body' => ['nextPlaylist' => 'song.fseq', 'playlistIndex' => 2],
        ]);
        $result = rf_http_rf_get_next_in_queue($this->rfMock->getBaseUrl(), 'tok');
        $this->assertSame('song.fseq', $result->nextPlaylist);

        $recordings = $this->rfMock->getRecordings();
        $this->assertSame('updateQueue=true', $recordings[0]['query']);
    }

    public function testRfUpdateWhatsPlaying_postsCorrectBodyAndHeaders(): void {
        $this->rfMock->setRoute('/updateWhatsPlaying', ['body' => ['ok' => true]]);
        $ok = rf_http_rf_update_whats_playing($this->rfMock->getBaseUrl(), 'tok', '  song-name  ');
        $this->assertTrue($ok);

        $recordings = $this->rfMock->getRecordings();
        $this->assertCount(1, $recordings);
        $rec = $recordings[0];
        $this->assertSame('POST', $rec['method']);
        $this->assertSame('tok', $rec['headers']['remotetoken'] ?? null);
        $this->assertStringContainsString('application/json', $rec['headers']['content-type'] ?? '');
        $this->assertSame(['playlist' => 'song-name'], json_decode($rec['body'], true));
    }

    public function testRfUpdateNextScheduled_postsCorrectBody(): void {
        $this->rfMock->setRoute('/updateNextScheduledSequence', ['body' => ['ok' => true]]);
        $ok = rf_http_rf_update_next_scheduled($this->rfMock->getBaseUrl(), 'tok', 'next');
        $this->assertTrue($ok);

        $recordings = $this->rfMock->getRecordings();
        $this->assertSame(['sequence' => 'next'], json_decode($recordings[0]['body'], true));
    }

    public function testRfPurgeQueue_sendsDeleteWithToken(): void {
        $this->rfMock->setRoute('/purgeQueue', ['body' => ['ok' => true]]);
        $ok = rf_http_rf_purge_queue($this->rfMock->getBaseUrl(), 'tok');
        $this->assertTrue($ok);

        $recordings = $this->rfMock->getRecordings();
        $this->assertCount(1, $recordings);
        $this->assertSame('DELETE', $recordings[0]['method']);
        $this->assertSame('tok', $recordings[0]['headers']['remotetoken'] ?? null);
    }

    public function testRfGet_returnsNullOn500(): void {
        $this->rfMock->setRoute('/remotePreferences', ['status' => 500, 'body' => 'oops']);
        $this->assertNull(rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok'));
    }

    public function testRfPost_returnsFalseOn500(): void {
        $this->rfMock->setRoute('/updateWhatsPlaying', ['status' => 500, 'body' => 'oops']);
        $this->assertFalse(rf_http_rf_update_whats_playing($this->rfMock->getBaseUrl(), 'tok', 'song'));
    }

    // -------- Generic transport --------

    public function testHttpRequest_returnsNullOnNetworkFailure(): void {
        // No server at all on this port
        $result = rf_http_request('GET', 'http://127.0.0.1:1', [], null, 1);
        $this->assertNull($result);
    }

    public function testHttpDecodeJson_handlesNullInput(): void {
        $this->assertNull(rf_http_decode_json(null));
    }

    public function testHttpDecodeJson_handlesGarbage(): void {
        $this->assertNull(rf_http_decode_json('{not json'));
    }
}
