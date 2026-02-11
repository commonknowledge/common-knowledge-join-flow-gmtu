<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_log;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\log_info;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\log_warning;

class LoggerTest extends TestCase
{
    protected function tear_down(): void
    {
        $this->clearLogger();
        parent::tear_down();
    }

    public function test_get_log_returns_log_when_global_is_set()
    {
        $mock = $this->mockLogger();
        $this->assertSame($mock, get_log());
    }

    public function test_get_log_returns_null_when_global_is_null()
    {
        $this->clearLogger();
        $this->assertNull(get_log());
    }

    public function test_get_log_returns_null_when_global_is_empty()
    {
        global $joinBlockLog;
        $joinBlockLog = '';
        $this->assertNull(get_log());
    }

    public function test_log_info_calls_info_on_log_object()
    {
        $called = false;
        $mock = $this->mockLogger();
        $mock->shouldReceive('info')->andReturnUsing(function ($msg) use (&$called) {
            if ($msg === 'test message') {
                $called = true;
            }
        });
        log_info('test message');
        $this->assertTrue($called);
    }

    public function test_log_info_does_nothing_when_log_is_null()
    {
        $this->clearLogger();
        log_info('test message');
        $this->assertTrue(true); // No exception thrown
    }

    public function test_log_warning_calls_warning_on_log_object()
    {
        $called = false;
        $mock = $this->mockLogger();
        $mock->shouldReceive('warning')->andReturnUsing(function ($msg) use (&$called) {
            if ($msg === 'warning text') {
                $called = true;
            }
        });
        log_warning('warning text');
        $this->assertTrue($called);
    }

    public function test_log_warning_does_nothing_when_log_is_null()
    {
        $this->clearLogger();
        log_warning('warning text');
        $this->assertTrue(true);
    }
}
