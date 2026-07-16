<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for rf_auto_sync_decision() — the pure state machine behind
 * Auto Sync Playlist (issue #13).
 *
 * Contract: a sync fires only after the playlist file's mtime has changed
 * AND then held unchanged for the full quiet window (30 s in production).
 * Every further change resets the window, so an active editing session in
 * the FPP UI coalesces into a single sync after the last save.
 */
final class AutoSyncDecisionTest extends TestCase {

    private const QUIET = 30;

    public function testFirstObservationBaselinesWithoutSyncing(): void {
        $d = rf_auto_sync_decision(null, null, 1000, 5000, self::QUIET);
        $this->assertFalse($d['sync']);
        $this->assertSame(1000, $d['lastMtime']);
        $this->assertNull($d['changedAt']);
    }

    public function testChangeDetectedStartsQuietWindowWithoutSyncing(): void {
        $d = rf_auto_sync_decision(1000, null, 1010, 5000, self::QUIET);
        $this->assertFalse($d['sync']);
        $this->assertSame(1010, $d['lastMtime']);
        $this->assertSame(5000, $d['changedAt']);
    }

    public function testStableButWindowNotElapsedDoesNotSync(): void {
        $d = rf_auto_sync_decision(1010, 5000, 1010, 5029, self::QUIET);
        $this->assertFalse($d['sync']);
        $this->assertSame(5000, $d['changedAt']);
    }

    public function testStableForFullQuietWindowSyncs(): void {
        $d = rf_auto_sync_decision(1010, 5000, 1010, 5030, self::QUIET);
        $this->assertTrue($d['sync']);
        $this->assertSame(1010, $d['lastMtime']);
        $this->assertNull($d['changedAt']);
    }

    public function testNewChangeDuringQuietWindowResetsTheWindow(): void {
        // Saved again 20s into the window: no sync, window restarts.
        $d = rf_auto_sync_decision(1010, 5000, 1020, 5020, self::QUIET);
        $this->assertFalse($d['sync']);
        $this->assertSame(1020, $d['lastMtime']);
        $this->assertSame(5020, $d['changedAt']);
        // ...and only fires 30s after THAT save.
        $d2 = rf_auto_sync_decision($d['lastMtime'], $d['changedAt'], 1020, 5050, self::QUIET);
        $this->assertTrue($d2['sync']);
    }

    public function testSteadyStateAfterSyncDoesNothing(): void {
        $d = rf_auto_sync_decision(1010, null, 1010, 9000, self::QUIET);
        $this->assertFalse($d['sync']);
        $this->assertSame(1010, $d['lastMtime']);
        $this->assertNull($d['changedAt']);
    }

    public function testMissingFileClearsStateWithoutSyncing(): void {
        foreach ([null, false] as $missing) {
            $d = rf_auto_sync_decision(1010, 5000, $missing, 5030, self::QUIET);
            $this->assertFalse($d['sync']);
            $this->assertNull($d['lastMtime']);
            $this->assertNull($d['changedAt']);
        }
    }

    public function testFileReappearingBaselinesWithoutSyncing(): void {
        // Gone...
        $d = rf_auto_sync_decision(1010, null, null, 6000, self::QUIET);
        // ...back: treated as a fresh baseline, not a change.
        $d2 = rf_auto_sync_decision($d['lastMtime'], $d['changedAt'], 2000, 6010, self::QUIET);
        $this->assertFalse($d2['sync']);
        $this->assertSame(2000, $d2['lastMtime']);
        $this->assertNull($d2['changedAt']);
    }

    public function testFullLifecycle(): void {
        $mtime = 1000; $state = ['lastMtime' => null, 'changedAt' => null];
        $step = function (int $now, int $m) use (&$state) {
            $state = rf_auto_sync_decision($state['lastMtime'], $state['changedAt'], $m, $now, self::QUIET);
            return $state['sync'];
        };
        $this->assertFalse($step(100, $mtime));          // baseline
        $this->assertFalse($step(101, $mtime));          // steady
        $this->assertFalse($step(102, $mtime = 1050));   // edit
        $this->assertFalse($step(110, $mtime = 1058));   // edit again
        $this->assertFalse($step(130, $mtime));          // quiet, 20s in
        $this->assertTrue($step(140, $mtime));           // quiet >= 30s -> sync
        $this->assertFalse($step(141, $mtime));          // steady again
    }
}
