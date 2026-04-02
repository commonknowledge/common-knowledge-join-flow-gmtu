<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_lapsing_override;

class LapsingOverrideTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    // -------------------------------------------------------------------------
    // Helper: capture all three callbacks registered by register_lapsing_override
    // -------------------------------------------------------------------------

    /**
     * Register hooks with an injectable fetcher and capture all three callbacks.
     *
     * @param callable|null $fetcher Fake fetcher to inject; null = default real fetcher.
     * @return array{0: callable, 1: callable, 2: callable}
     */
    private function registerAndCaptureCallbacks(?callable $fetcher = null): array
    {
        $lapseCallback   = null;
        $unlapseCallback = null;
        $successCallback = null;

        Functions\expect('add_filter')
            ->twice()
            ->andReturnUsing(function ($hook, $cb) use (&$lapseCallback, &$unlapseCallback) {
                if ($hook === 'ck_join_flow_should_lapse_member') {
                    $lapseCallback = $cb;
                } elseif ($hook === 'ck_join_flow_should_unlapse_member') {
                    $unlapseCallback = $cb;
                }
                return true;
            });

        Functions\expect('add_action')
            ->once()
            ->andReturnUsing(function ($hook, $cb) use (&$successCallback) {
                if ($hook === 'ck_join_flow_success') {
                    $successCallback = $cb;
                }
                return true;
            });

        register_lapsing_override($fetcher);

        return [$lapseCallback, $unlapseCallback, $successCallback];
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function test_registers_lapse_filter()
    {
        $registered = [];

        Functions\expect('add_filter')
            ->twice()
            ->andReturnUsing(function ($hook, $cb, $priority, $args) use (&$registered) {
                $registered[] = $hook;
                return true;
            });
        Functions\expect('add_action')->once()->andReturn(true);

        register_lapsing_override();

        $this->assertContains('ck_join_flow_should_lapse_member', $registered);
    }

    public function test_registers_unlapse_filter()
    {
        $registered = [];

        Functions\expect('add_filter')
            ->twice()
            ->andReturnUsing(function ($hook, $cb, $priority, $args) use (&$registered) {
                $registered[] = $hook;
                return true;
            });
        Functions\expect('add_action')->once()->andReturn(true);

        register_lapsing_override();

        $this->assertContains('ck_join_flow_should_unlapse_member', $registered);
    }

    public function test_registers_success_action()
    {
        $registered = [];

        Functions\expect('add_filter')->twice()->andReturn(true);
        Functions\expect('add_action')
            ->once()
            ->andReturnUsing(function ($hook, $cb, $priority) use (&$registered) {
                $registered[] = $hook;
                return true;
            });

        register_lapsing_override();

        $this->assertContains('ck_join_flow_success', $registered);
    }

    // -------------------------------------------------------------------------
    // ck_join_flow_should_lapse_member
    // -------------------------------------------------------------------------

    public function test_lapse_passes_through_for_non_stripe_provider()
    {
        [$lapse] = $this->registerAndCaptureCallbacks();

        $result = $lapse(true, 'member@example.com', ['provider' => 'gocardless', 'trigger' => 'x']);
        $this->assertTrue($result);

        $result = $lapse(false, 'member@example.com', ['provider' => 'gocardless', 'trigger' => 'x']);
        $this->assertFalse($result);
    }

    public function test_lapse_suppressed_for_good_standing()
    {
        // Last payment was last month — 0 missed months → Good
        $lastMonth = $this->monthOffset(-1);
        Functions\expect('get_option')->andReturn(false); // not lapsed-lapsed

        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$lastMonth]));

        $result = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertFalse($result);
    }

    public function test_lapse_suppressed_for_early_arrears()
    {
        // 3 missed months → Early arrears
        $threeMonthsAgo = $this->monthOffset(-4);
        Functions\expect('get_option')->andReturn(false);

        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$threeMonthsAgo]));

        $result = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertFalse($result);
    }

    public function test_lapse_suppressed_for_lapsing()
    {
        // 5 missed months → Lapsing
        $fiveMonthsAgo = $this->monthOffset(-6);
        Functions\expect('get_option')->andReturn(false);

        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$fiveMonthsAgo]));

        $result = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertFalse($result);
    }

    public function test_lapse_allowed_and_lapsed_flag_set_for_lapsed()
    {
        // 8 missed months → Lapsed
        $eightMonthsAgo = $this->monthOffset(-9);
        Functions\expect('get_option')->andReturn(false);
        Functions\expect('update_option')->once()->andReturn(true); // mark_lapsed_lapsed

        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$eightMonthsAgo]));

        $result = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertTrue($result);
    }

    public function test_lapse_falls_through_on_stripe_api_error()
    {
        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcherWithError('Connection timeout'));

        $result = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertTrue($result); // original default returned

        $result2 = $lapse(false, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertFalse($result2);
    }

    public function test_lapse_falls_through_when_no_gmtu_history()
    {
        // Empty history, no error → not a GMTU member → pass through
        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([]));

        $result = $lapse(false, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertFalse($result);

        $result2 = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertTrue($result2);
    }

    // -------------------------------------------------------------------------
    // ck_join_flow_should_unlapse_member
    // -------------------------------------------------------------------------

    public function test_unlapse_passes_through_for_non_stripe_provider()
    {
        [, $unlapse] = $this->registerAndCaptureCallbacks();

        $result = $unlapse(true, 'member@example.com', ['provider' => 'gocardless', 'trigger' => 'x']);
        $this->assertTrue($result);
    }

    public function test_unlapse_allowed_for_good_standing_non_lapsed()
    {
        $lastMonth = $this->monthOffset(-1);
        Functions\expect('get_option')->andReturn(false); // not lapsed-lapsed

        [, $unlapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$lastMonth]));

        $result = $unlapse(false, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_paid']);
        $this->assertTrue($result);
    }

    public function test_unlapse_suppressed_for_lapsed_lapsed()
    {
        $lastMonth = $this->monthOffset(-1);
        $lapsed = json_encode(['email' => 'member@example.com', 'lapsed_at' => '2026-01-01T00:00:00Z', 'trigger' => 'x']);
        Functions\expect('get_option')->andReturn($lapsed); // lapsed-lapsed

        [, $unlapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$lastMonth]));

        $result = $unlapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_paid']);
        $this->assertFalse($result);
    }

    public function test_unlapse_suppressed_for_early_arrears()
    {
        $threeMonthsAgo = $this->monthOffset(-4);
        Functions\expect('get_option')->andReturn(false);

        [, $unlapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$threeMonthsAgo]));

        $result = $unlapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_paid']);
        $this->assertFalse($result);
    }

    public function test_unlapse_falls_through_on_stripe_api_error()
    {
        [, $unlapse] = $this->registerAndCaptureCallbacks($this->fakeFetcherWithError('Timeout'));

        $result = $unlapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_paid']);
        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // ck_join_flow_success
    // -------------------------------------------------------------------------

    public function test_success_hook_clears_lapsed_lapsed_flag()
    {
        [,, $success] = $this->registerAndCaptureCallbacks($this->fakeFetcher([]));

        $email = 'member@example.com';
        $lapsed = json_encode(['email' => $email, 'lapsed_at' => '2026-01-01T00:00:00Z', 'trigger' => 'x']);
        Functions\expect('get_option')->andReturn($lapsed);
        Functions\expect('delete_option')->once()->andReturn(true);

        $success(['email' => $email]);
        $this->addToAssertionCount(1); // delete_option ->once() is the assertion
    }

    public function test_success_hook_does_nothing_when_not_lapsed_lapsed()
    {
        [,, $success] = $this->registerAndCaptureCallbacks($this->fakeFetcher([]));

        Functions\expect('get_option')->andReturn(false);
        Functions\expect('delete_option')->never();

        $success(['email' => 'member@example.com']);
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return a 'YYYY-MM' string offset from the current month.
     * e.g. monthOffset(-1) = last month, monthOffset(-7) = 7 months ago.
     */
    private function monthOffset(int $offset): string
    {
        $ts = mktime(0, 0, 0, (int)gmdate('n') + $offset, 1, (int)gmdate('Y'));
        return gmdate('Y-m', $ts);
    }

    /**
     * Return a fake fetcher callable that always returns the given month keys.
     */
    private function fakeFetcher(array $monthKeys): callable
    {
        return function (string $email) use ($monthKeys): array {
            return [
                'month_keys' => $monthKeys,
                'error'      => null,
            ];
        };
    }

    /**
     * Return a fake fetcher callable that always returns an error.
     */
    private function fakeFetcherWithError(string $errorMessage): callable
    {
        return function (string $email) use ($errorMessage): array {
            return [
                'month_keys' => [],
                'error'      => $errorMessage,
            ];
        };
    }
}
