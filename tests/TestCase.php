<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;
use Brain\Monkey;

/**
 * Base test case for all GMTU tests.
 *
 * Sets up and tears down Brain Monkey on every test.
 */
abstract class TestCase extends PolyfillTestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Set up the global $joinBlockLog with a Mockery mock.
     *
     * By default allows all info/warning calls silently.
     * Tests can add specific expectations on top.
     *
     * @return \Mockery\MockInterface
     */
    protected function mockLogger(): \Mockery\MockInterface
    {
        global $joinBlockLog;
        $joinBlockLog = \Mockery::mock('JoinBlockLog');
        $joinBlockLog->shouldReceive('info')->byDefault();
        $joinBlockLog->shouldReceive('warning')->byDefault();
        return $joinBlockLog;
    }

    /**
     * Clear the global $joinBlockLog.
     */
    protected function clearLogger(): void
    {
        global $joinBlockLog;
        $joinBlockLog = null;
    }
}
