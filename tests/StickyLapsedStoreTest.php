<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\sticky_lapsed_option_key;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\is_sticky_lapsed;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\mark_sticky_lapsed;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\clear_sticky_lapsed;

class StickyLapsedStoreTest extends TestCase
{
    // --- sticky_lapsed_option_key ---

    public function test_option_key_has_correct_prefix()
    {
        $key = sticky_lapsed_option_key('test@example.com');
        $this->assertStringStartsWith('gmtu_sticky_lapsed_', $key);
    }

    public function test_option_key_is_deterministic()
    {
        $this->assertSame(
            sticky_lapsed_option_key('test@example.com'),
            sticky_lapsed_option_key('test@example.com')
        );
    }

    public function test_option_key_is_case_insensitive()
    {
        $this->assertSame(
            sticky_lapsed_option_key('Test@Example.COM'),
            sticky_lapsed_option_key('test@example.com')
        );
    }

    public function test_option_key_suffix_is_sha256_of_lowercased_email()
    {
        $email = 'test@example.com';
        $expected = 'gmtu_sticky_lapsed_' . hash('sha256', strtolower(trim($email)));
        $this->assertSame($expected, sticky_lapsed_option_key($email));
    }

    public function test_different_emails_produce_different_keys()
    {
        $this->assertNotSame(
            sticky_lapsed_option_key('alice@example.com'),
            sticky_lapsed_option_key('bob@example.com')
        );
    }

    // --- is_sticky_lapsed ---

    public function test_is_sticky_lapsed_returns_false_when_option_missing()
    {
        Functions\expect('get_option')
            ->once()
            ->with(sticky_lapsed_option_key('member@example.com'), false)
            ->andReturn(false);

        $this->assertFalse(is_sticky_lapsed('member@example.com'));
    }

    public function test_is_sticky_lapsed_returns_true_when_option_exists()
    {
        $value = json_encode(['email' => 'member@example.com', 'lapsed_at' => '2026-04-01T00:00:00Z']);
        Functions\expect('get_option')
            ->once()
            ->andReturn($value);

        $this->assertTrue(is_sticky_lapsed('member@example.com'));
    }

    // --- mark_sticky_lapsed ---

    public function test_mark_sticky_lapsed_calls_update_option_with_correct_key()
    {
        $email = 'member@example.com';
        $key = sticky_lapsed_option_key($email);

        Functions\expect('update_option')
            ->once()
            ->with($key, \Mockery::type('string'), false)
            ->andReturn(true);

        mark_sticky_lapsed($email, 'invoice_payment_failed', '2026-04-01T00:00:00Z');
        $this->addToAssertionCount(1); // Brain Monkey ->once() expectation counts as the assertion
    }

    public function test_mark_sticky_lapsed_stores_json_with_email()
    {
        $email = 'member@example.com';
        $stored = null;

        Functions\expect('update_option')
            ->once()
            ->andReturnUsing(function ($key, $value, $autoload) use (&$stored) {
                $stored = json_decode($value, true);
                return true;
            });

        mark_sticky_lapsed($email, 'invoice_payment_failed', '2026-04-01T00:00:00Z');

        $this->assertSame($email, $stored['email']);
        $this->assertArrayHasKey('lapsed_at', $stored);
        $this->assertSame('invoice_payment_failed', $stored['trigger']);
    }

    public function test_mark_sticky_lapsed_sets_autoload_false()
    {
        $autoloadValue = null;

        Functions\expect('update_option')
            ->once()
            ->andReturnUsing(function ($key, $value, $autoload) use (&$autoloadValue) {
                $autoloadValue = $autoload;
                return true;
            });

        mark_sticky_lapsed('member@example.com', 'test', '2026-04-01T00:00:00Z');

        $this->assertFalse($autoloadValue);
    }

    // --- clear_sticky_lapsed ---

    public function test_clear_sticky_lapsed_calls_delete_option_with_correct_key()
    {
        $email = 'member@example.com';
        $key = sticky_lapsed_option_key($email);

        Functions\expect('delete_option')
            ->once()
            ->with($key)
            ->andReturn(true);

        clear_sticky_lapsed($email);
        $this->addToAssertionCount(1); // Brain Monkey ->once() expectation counts as the assertion
    }
}
