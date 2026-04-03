<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\lapsed_option_key;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\is_lapsed;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\mark_lapsed;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\clear_lapsed;

class LapsedStoreTest extends TestCase
{
    // --- lapsed_option_key ---

    public function test_option_key_format_is_prefix_plus_sha256_of_lowercased_email()
    {
        $email    = 'test@example.com';
        $expected = 'gmtu_lapsed_' . hash('sha256', strtolower(trim($email)));

        $this->assertSame($expected, lapsed_option_key($email));
    }

    public function test_option_key_is_case_insensitive()
    {
        $this->assertSame(
            lapsed_option_key('Test@Example.COM'),
            lapsed_option_key('test@example.com')
        );
    }

    // --- is_lapsed ---

    public function test_is_lapsed_returns_false_when_option_missing()
    {
        Functions\expect('get_option')
            ->once()
            ->with(lapsed_option_key('member@example.com'), false)
            ->andReturn(false);

        $this->assertFalse(is_lapsed('member@example.com'));
    }

    public function test_is_lapsed_returns_true_when_option_exists()
    {
        $value = json_encode(['email' => 'member@example.com', 'lapsed_at' => '2026-04-01T00:00:00Z', 'trigger' => 'x']);
        Functions\expect('get_option')
            ->once()
            ->andReturn($value);

        $this->assertTrue(is_lapsed('member@example.com'));
    }

    // --- mark_lapsed ---

    public function test_mark_lapsed_stores_json_with_email_trigger_and_timestamp()
    {
        $email  = 'member@example.com';
        $stored = null;

        Functions\expect('update_option')
            ->once()
            ->andReturnUsing(function ($key, $value, $autoload) use (&$stored) {
                $stored = json_decode($value, true);
                return true;
            });

        mark_lapsed($email, 'invoice_payment_failed', '2026-04-01T00:00:00Z');

        $this->assertSame($email, $stored['email']);
        $this->assertArrayHasKey('lapsed_at', $stored);
        $this->assertSame('invoice_payment_failed', $stored['trigger']);
    }

    public function test_mark_lapsed_sets_autoload_false()
    {
        $autoloadValue = null;

        Functions\expect('update_option')
            ->once()
            ->andReturnUsing(function ($key, $value, $autoload) use (&$autoloadValue) {
                $autoloadValue = $autoload;
                return true;
            });

        mark_lapsed('member@example.com', 'test', '2026-04-01T00:00:00Z');

        $this->assertFalse($autoloadValue);
    }

    // --- clear_lapsed ---

    public function test_clear_lapsed_calls_delete_option_with_correct_key()
    {
        $email = 'member@example.com';
        $key   = lapsed_option_key($email);

        Functions\expect('delete_option')
            ->once()
            ->with($key)
            ->andReturn(true);

        clear_lapsed($email);
        $this->addToAssertionCount(1);
    }
}
