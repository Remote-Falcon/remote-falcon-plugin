<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that need mock FPP and RF servers.
 * Spawns one MockServer for each on setUp and tears them down on tearDown.
 *
 * Subclasses access the mocks via $this->fppMock and $this->rfMock and
 * configure routes per-test with $this->fppMock->setRoute(...).
 */
abstract class IntegrationTestCase extends TestCase {
    protected MockServer $fppMock;
    protected MockServer $rfMock;

    protected function setUp(): void {
        parent::setUp();
        $this->fppMock = new MockServer('fpp');
        $this->rfMock = new MockServer('rf');
        $this->fppMock->start();
        $this->rfMock->start();
        // Each test gets a fresh cURL handle for RF calls so connection
        // state from prior tests can't bleed in.
        if (function_exists('_rf_http_rf_curl_reset')) {
            _rf_http_rf_curl_reset();
        }
        // Same for the RF backoff and the interrupt poll floor: a 5xx in one
        // test must not pause RF calls in the next.
        if (function_exists('rf_backoff_reset')) {
            rf_backoff_reset();
        }
        unset($GLOBALS['lastInterruptPollAt']);
    }

    /**
     * Stand in for wall-clock time passing between two listener ticks: lets
     * the current RF backoff expire while keeping its failure count, as a
     * real 30s gap would.
     */
    protected function elapseRfBackoff(): void {
        $GLOBALS['rfBackoff']['until'] = 0.0;
    }

    protected function tearDown(): void {
        $this->fppMock->stop();
        $this->rfMock->stop();
        parent::tearDown();
    }
}
