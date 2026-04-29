<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for pure-logic functions in lib/listener_logic.php.
 * These run anywhere PHP runs — no FPP, no network, no filesystem.
 */
final class ListenerLogicTest extends TestCase {

    private function seq(string $name): stdClass {
        $o = new stdClass();
        $o->sequenceName = $name;
        return $o;
    }

    // -------- rf_get_next_sequence --------

    public function testGetNextSequence_normalCase(): void {
        $playlist = [$this->seq('a.fseq'), $this->seq('b.fseq'), $this->seq('c.fseq')];
        $this->assertSame('b', rf_get_next_sequence($playlist, 'a'));
        $this->assertSame('c', rf_get_next_sequence($playlist, 'b'));
    }

    public function testGetNextSequence_lastSequenceWrapsToFirst(): void {
        $playlist = [$this->seq('a.fseq'), $this->seq('b.fseq'), $this->seq('c.fseq')];
        $this->assertSame('a', rf_get_next_sequence($playlist, 'c'));
    }

    public function testGetNextSequence_currentNotFoundReturnsEmpty(): void {
        $playlist = [$this->seq('a.fseq'), $this->seq('b.fseq')];
        $this->assertSame('', rf_get_next_sequence($playlist, 'nonexistent'));
    }

    public function testGetNextSequence_emptyPlaylistReturnsEmpty(): void {
        $this->assertSame('', rf_get_next_sequence([], 'anything'));
    }

    /**
     * Regression test for the missing-break bug fixed in the cleanup branch.
     * Before the fix, this would return 'd' (the neighbor of the SECOND 'b'
     * occurrence — the loop's last match). After the fix, it returns 'c'
     * (the neighbor of the FIRST 'b' occurrence).
     */
    public function testGetNextSequence_duplicateSequenceReturnsFirstMatchNeighbor(): void {
        $playlist = [
            $this->seq('a.fseq'),
            $this->seq('b.fseq'),
            $this->seq('c.fseq'),
            $this->seq('b.fseq'),  // duplicate
            $this->seq('d.fseq'),
        ];
        $this->assertSame('c', rf_get_next_sequence($playlist, 'b'));
    }

    public function testGetNextSequence_handlesPathPrefixedNames(): void {
        $playlist = [$this->seq('subdir/a.fseq'), $this->seq('subdir/b.fseq')];
        $this->assertSame('b', rf_get_next_sequence($playlist, 'a'));
    }

    /**
     * Locks in existing behavior: when the immediate next item has no
     * sequenceName (e.g., a command-type playlist entry), the function
     * returns "" rather than skipping ahead to the next valid sequence.
     * This matches the pre-extraction listener behavior. May be worth
     * revisiting — a playlist like [sequence, command, sequence] reports
     * no next-scheduled while playing the first sequence.
     */
    public function testGetNextSequence_returnsEmptyWhenNextItemMissingSequenceName(): void {
        $missing = new stdClass();  // no sequenceName property
        $playlist = [$this->seq('a.fseq'), $missing, $this->seq('b.fseq')];
        $this->assertSame('', rf_get_next_sequence($playlist, 'a'));
    }

    public function testGetNextSequence_singleSequenceWrapsToItself(): void {
        $playlist = [$this->seq('only.fseq')];
        $this->assertSame('only', rf_get_next_sequence($playlist, 'only'));
    }

    // -------- rf_clamp_status_check_time --------

    /**
     * Regression test for the clamping fix in the cleanup branch.
     * Before the fix, fppStatusCheckTime=0 caused usleep(0) busy-loops.
     */
    public function testClampStatusCheckTime_zeroIsClampedToMinimum(): void {
        $this->assertSame(0.1, rf_clamp_status_check_time(0));
        $this->assertSame(0.1, rf_clamp_status_check_time('0'));
    }

    public function testClampStatusCheckTime_negativeIsClampedToMinimum(): void {
        $this->assertSame(0.1, rf_clamp_status_check_time(-1));
        $this->assertSame(0.1, rf_clamp_status_check_time(-0.5));
    }

    public function testClampStatusCheckTime_belowMinimumIsClamped(): void {
        $this->assertSame(0.1, rf_clamp_status_check_time(0.05));
    }

    public function testClampStatusCheckTime_atMinimumIsKept(): void {
        $this->assertSame(0.1, rf_clamp_status_check_time(0.1));
    }

    public function testClampStatusCheckTime_normalValuesPassThrough(): void {
        $this->assertSame(0.5, rf_clamp_status_check_time(0.5));
        $this->assertSame(1.0, rf_clamp_status_check_time(1));
        $this->assertSame(3.0, rf_clamp_status_check_time('3'));
    }

    public function testClampStatusCheckTime_garbageInputClampsToMinimum(): void {
        // floatval('abc') === 0.0, which is < 0.1
        $this->assertSame(0.1, rf_clamp_status_check_time('not a number'));
    }
}
