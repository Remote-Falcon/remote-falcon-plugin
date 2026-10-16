<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Remote Falcon backoff helpers and the interrupt-mode
 * poll floor in lib/listener_logic.php (season resilience P1-9).
 */
final class BackoffLogicTest extends TestCase {

    // -------- rf_is_backoff_status --------

    public function testBackoffStatus_transportErrorRateLimitAnd5xxCount(): void {
        foreach ([0, 429, 500, 502, 503, 504] as $status) {
            $this->assertTrue(rf_is_backoff_status($status), "status $status");
        }
    }

    public function testBackoffStatus_successAndOther4xxDoNotCount(): void {
        foreach ([200, 204, 301, 400, 401, 403, 404] as $status) {
            $this->assertFalse(rf_is_backoff_status($status), "status $status");
        }
    }

    // -------- rf_backoff_ceiling / rf_backoff_delay --------

    public function testCeiling_doublesFromOneSecondAndCapsAtThirty(): void {
        $this->assertSame(0.0, rf_backoff_ceiling(0));
        $this->assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 30.0, 30.0], array_map('rf_backoff_ceiling', [1, 2, 3, 4, 5, 6, 7]));
    }

    public function testCeiling_longOutageDoesNotOverflow(): void {
        $this->assertSame(30.0, rf_backoff_ceiling(10000));
    }

    public function testDelay_isFullJitterOverTheCeiling(): void {
        $this->assertSame(0.0, rf_backoff_delay(3, 0.0));
        $this->assertSame(2.0, rf_backoff_delay(3, 0.5));
        $this->assertSame(4.0, rf_backoff_delay(3, 1.0));
    }

    public function testDelay_clampsOutOfRangeRandomDraws(): void {
        $this->assertSame(4.0, rf_backoff_delay(3, 7.0));
        $this->assertSame(0.0, rf_backoff_delay(3, -1.0));
    }

    public function testDelay_noFailuresMeansNoWait(): void {
        $this->assertSame(0.0, rf_backoff_delay(0, 1.0));
    }

    // -------- rf_parse_retry_after --------

    public function testRetryAfter_deltaSeconds(): void {
        $this->assertSame(15, rf_parse_retry_after('15', 1000));
        $this->assertSame(15, rf_parse_retry_after(' 15 ', 1000));
        $this->assertSame(0, rf_parse_retry_after('0', 1000));
    }

    public function testRetryAfter_isClampedToTheMaximum(): void {
        $this->assertSame(RF_RETRY_AFTER_MAX_SECONDS, rf_parse_retry_after('86400', 1000));
        $this->assertSame(RF_RETRY_AFTER_MAX_SECONDS, rf_parse_retry_after('99999999999999999999999', 1000));
        $this->assertSame(10, rf_parse_retry_after('86400', 1000, 10));
    }

    public function testRetryAfter_httpDate(): void {
        $now = gmmktime(7, 28, 0, 10, 21, 2026);
        $this->assertSame(20, rf_parse_retry_after('Wed, 21 Oct 2026 07:28:20 GMT', $now));
    }

    public function testRetryAfter_pastHttpDateMeansNoWait(): void {
        $now = gmmktime(7, 28, 0, 10, 21, 2026);
        $this->assertSame(0, rf_parse_retry_after('Wed, 21 Oct 2026 07:00:00 GMT', $now));
    }

    public function testRetryAfter_farFutureHttpDateIsClamped(): void {
        $now = gmmktime(7, 28, 0, 10, 21, 2026);
        $this->assertSame(RF_RETRY_AFTER_MAX_SECONDS, rf_parse_retry_after('Sat, 21 Nov 2026 07:28:00 GMT', $now));
    }

    public function testRetryAfter_missingOrUnparseableIsNull(): void {
        foreach ([null, '', '   ', 'soon', '-5', '1.5', 'tomorrow', '+15', 'Foo, nonsense'] as $value) {
            $this->assertNull(rf_parse_retry_after($value, 1000), var_export($value, true));
        }
    }

    // -------- rf_backoff_next_state --------

    private function fresh(): array {
        return ['failures' => 0, 'until' => 0.0, 'delay' => 0.0];
    }

    public function testNextState_successResets(): void {
        $state = ['failures' => 4, 'until' => 999.0, 'delay' => 8.0];
        $this->assertSame($this->fresh(), rf_backoff_next_state($state, 200, null, 100.0, 0.5));
    }

    public function testNextState_other4xxResetsBecauseTheServerAnswered(): void {
        $state = ['failures' => 4, 'until' => 999.0, 'delay' => 8.0];
        $this->assertSame($this->fresh(), rf_backoff_next_state($state, 404, null, 100.0, 0.5));
    }

    public function testNextState_consecutiveFailuresGrowToTheCap(): void {
        $state = $this->fresh();
        $delays = [];
        for ($i = 0; $i < 7; $i++) {
            $state = rf_backoff_next_state($state, 0, null, 100.0, 1.0);
            $delays[] = $state['delay'];
        }
        $this->assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 30.0, 30.0], $delays);
        $this->assertSame(7, $state['failures']);
        $this->assertSame(130.0, $state['until']);
    }

    public function testNextState_failureAfterSuccessStartsOver(): void {
        $state = ['failures' => 6, 'until' => 0.0, 'delay' => 30.0];
        $state = rf_backoff_next_state($state, 200, null, 100.0, 1.0);
        $state = rf_backoff_next_state($state, 503, null, 100.0, 1.0);
        $this->assertSame(1, $state['failures']);
        $this->assertSame(1.0, $state['delay']);
    }

    public function testNextState_retryAfterIsAFloorOn503And429(): void {
        foreach ([503, 429] as $status) {
            $state = rf_backoff_next_state($this->fresh(), $status, 15, 100.0, 0.5);
            $this->assertSame(15.0, $state['delay'], "status $status");
            $this->assertSame(115.0, $state['until'], "status $status");
        }
    }

    public function testNextState_longerJitterBeatsAShortRetryAfter(): void {
        $state = ['failures' => 5, 'until' => 0.0, 'delay' => 0.0];
        $state = rf_backoff_next_state($state, 503, 2, 100.0, 1.0);
        $this->assertSame(30.0, $state['delay']);
    }

    public function testNextState_retryAfterIgnoredOnOther5xx(): void {
        $state = rf_backoff_next_state($this->fresh(), 500, 15, 100.0, 0.5);
        $this->assertSame(0.5, $state['delay']);
    }

    // -------- rf_interrupt_poll_due --------

    public function testInterruptPoll_firstPollIsDue(): void {
        $this->assertTrue(rf_interrupt_poll_due(5.0, null));
    }

    public function testInterruptPoll_floorIsTwoSeconds(): void {
        $this->assertSame(2.0, RF_INTERRUPT_POLL_FLOOR_SECONDS);
        $this->assertFalse(rf_interrupt_poll_due(101.0, 100.0));
        $this->assertFalse(rf_interrupt_poll_due(101.99, 100.0));
        $this->assertTrue(rf_interrupt_poll_due(102.0, 100.0));
    }

    public function testInterruptPoll_floorIsBelowTheDefaultRequestFetchTime(): void {
        // Interrupt mode must not answer a request slower than non-interrupt
        // mode's default 3s fetch window.
        $this->assertLessThan(3.0, RF_INTERRUPT_POLL_FLOOR_SECONDS);
    }
}
