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

    public function test_registers_all_three_hooks()
    {
        $registered_filters = [];
        $registered_actions = [];

        Functions\expect('add_filter')
            ->twice()
            ->andReturnUsing(function ($hook, $cb, $priority, $args) use (&$registered_filters) {
                $registered_filters[] = $hook;
                return true;
            });
        Functions\expect('add_action')
            ->once()
            ->andReturnUsing(function ($hook, $cb, $priority) use (&$registered_actions) {
                $registered_actions[] = $hook;
                return true;
            });

        register_lapsing_override();

        $this->assertContains('ck_join_flow_should_lapse_member', $registered_filters);
        $this->assertContains('ck_join_flow_should_unlapse_member', $registered_filters);
        $this->assertContains('ck_join_flow_success', $registered_actions);
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
        Functions\expect('get_option')->andReturn(false); // not lapsed

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

    /**
     * The critical threshold: exactly 6 missed months is still Lapsing — suppressed.
     * One fewer missed month than the boundary at which lapsing becomes Lapsed.
     */
    public function test_lapse_suppressed_at_exactly_six_missed_months()
    {
        $sixMissed = $this->monthOffset(-7); // paid 7 months ago = 6 completed missed months
        Functions\expect('get_option')->andReturn(false);

        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$sixMissed]));

        $result = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertFalse($result);
    }

    public function test_lapse_allowed_and_lapsed_flag_set_for_lapsed()
    {
        // 8 missed months → Lapsed
        $eightMonthsAgo = $this->monthOffset(-9);
        Functions\expect('get_option')->andReturn(false);
        Functions\expect('update_option')->once()->andReturn(true); // mark_lapsed

        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$eightMonthsAgo]));

        $result = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertTrue($result);
    }

    /**
     * The critical threshold: exactly 7 missed months is Lapsed — lapse allowed.
     * One more missed month than the upper bound of the Lapsing band.
     */
    public function test_lapse_allowed_at_exactly_seven_missed_months()
    {
        $sevenMissed = $this->monthOffset(-8); // paid 8 months ago = 7 completed missed months
        Functions\expect('get_option')->andReturn(false);
        Functions\expect('update_option')->once()->andReturn(true); // mark_lapsed

        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$sevenMissed]));

        $result = $lapse(true, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertTrue($result);
    }

    /**
     * When the lapsed flag is already set in wp_options, a new payment does NOT
     * reinstate the member. The flag takes precedence over payment history —
     * the member must rejoin explicitly via the join form.
     *
     * This verifies classify_membership_standing's $is_lapsed=true path is
     * correctly threaded through the lapse callback.
     */
    public function test_lapse_allowed_when_flag_already_set_despite_recent_payment()
    {
        $lastMonth  = $this->monthOffset(-1); // would be Good standing without the flag
        $lapsedJson = json_encode([
            'email'     => 'member@example.com',
            'lapsed_at' => '2026-01-01T00:00:00Z',
            'trigger'   => 'invoice_payment_failed',
        ]);
        Functions\expect('get_option')->andReturn($lapsedJson); // flag is already set
        Functions\expect('update_option')->once()->andReturn(true); // mark_lapsed writes again

        [$lapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$lastMonth]));

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
        Functions\expect('get_option')->andReturn(false); // not lapsed

        [, $unlapse] = $this->registerAndCaptureCallbacks($this->fakeFetcher([$lastMonth]));

        $result = $unlapse(false, 'member@example.com', ['provider' => 'stripe', 'trigger' => 'invoice_paid']);
        $this->assertTrue($result);
    }

    public function test_unlapse_suppressed_for_lapsed()
    {
        $lastMonth  = $this->monthOffset(-1);
        $lapsedJson = json_encode(['email' => 'member@example.com', 'lapsed_at' => '2026-01-01T00:00:00Z', 'trigger' => 'x']);
        Functions\expect('get_option')->andReturn($lapsedJson); // lapsed

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

    public function test_success_hook_clears_lapsed_flag()
    {
        [,, $success] = $this->registerAndCaptureCallbacks($this->fakeFetcher([]));

        $email   = 'member@example.com';
        $lapsed  = json_encode(['email' => $email, 'lapsed_at' => '2026-01-01T00:00:00Z', 'trigger' => 'x']);
        Functions\expect('get_option')->andReturn($lapsed);
        Functions\expect('delete_option')->once()->andReturn(true);

        $success(['email' => $email]);
        $this->addToAssertionCount(1); // delete_option ->once() is the assertion
    }

    public function test_success_hook_does_nothing_when_not_lapsed()
    {
        [,, $success] = $this->registerAndCaptureCallbacks($this->fakeFetcher([]));

        Functions\expect('get_option')->andReturn(false);
        Functions\expect('delete_option')->never();

        $success(['email' => 'member@example.com']);
        $this->addToAssertionCount(1);
    }

    /**
     * When $data contains no 'email' key, the success hook must be a complete
     * no-op: it must not call get_option or delete_option, and must not throw.
     */
    public function test_success_hook_does_nothing_when_email_missing_from_data()
    {
        [,, $success] = $this->registerAndCaptureCallbacks($this->fakeFetcher([]));

        Functions\expect('get_option')->never();
        Functions\expect('delete_option')->never();

        $success([]); // no 'email' key
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Full rejoin cycle
    // -------------------------------------------------------------------------

    /**
     * End-to-end scenario: member lapses → rejoins via join form → next payment
     * triggers an allowed unlapse.
     *
     * This is the most important user journey in the lapsing system. It verifies
     * that the three hooks (lapse, success, unlapse) interact correctly through
     * shared persistent state, not just in isolation.
     *
     * Sequence:
     *   1. Lapse hook fires for a member with 8+ missed months → lapse allowed, flag set.
     *   2. Unlapse hook fires before rejoin → suppressed (flag still set).
     *   3. Member completes join form → success hook clears the flag.
     *   4. Unlapse hook fires after rejoin with a recent payment → allowed.
     */
    public function test_full_rejoin_cycle_clears_lapsed_flag_and_allows_unlapse()
    {
        $email          = 'member@example.com';
        $eightMonthsAgo = $this->monthOffset(-9); // 8 missed months → Lapsed
        $lastMonth      = $this->monthOffset(-1);  // 0 missed months → Good

        // Simulate stateful WordPress option storage in memory.
        $store = [];

        Functions\expect('get_option')
            ->times(4) // lapse, unlapse-before, success, unlapse-after
            ->andReturnUsing(function ($key, $default = false) use (&$store) {
                return $store[$key] ?? $default;
            });
        Functions\expect('update_option')
            ->once()
            ->andReturnUsing(function ($key, $value, $autoload) use (&$store) {
                $store[$key] = $value;
                return true;
            });
        Functions\expect('delete_option')
            ->once()
            ->andReturnUsing(function ($key) use (&$store) {
                unset($store[$key]);
                return true;
            });

        // The fetcher returns lapsed-era history during lapse/unlapse-before,
        // then recent history after the member rejoins.
        $phase = 'lapsed';
        [$lapse, $unlapse, $success] = $this->registerAndCaptureCallbacks(
            function (string $e) use (&$phase, $eightMonthsAgo, $lastMonth) {
                return [
                    'month_keys' => $phase === 'lapsed' ? [$eightMonthsAgo] : [$lastMonth],
                    'error'      => null,
                ];
            }
        );

        // Step 1: Lapse fires → member is Lapsed, flag written to store.
        $lapseResult = $lapse(true, $email, ['provider' => 'stripe', 'trigger' => 'invoice_payment_failed']);
        $this->assertTrue($lapseResult, 'Step 1: lapse must be allowed for Lapsed member');

        // Step 2: Unlapse fires before rejoin → must be suppressed (flag is set).
        $unlapseBeforeRejoin = $unlapse(true, $email, ['provider' => 'stripe', 'trigger' => 'invoice_paid']);
        $this->assertFalse($unlapseBeforeRejoin, 'Step 2: unlapse must be suppressed while member is still lapsed');

        // Step 3: Member completes join form → success hook clears the flag.
        $phase = 'rejoined';
        $success(['email' => $email]);

        // Step 4: Unlapse fires after rejoin with a recent payment → must be allowed.
        $unlapseAfterRejoin = $unlapse(true, $email, ['provider' => 'stripe', 'trigger' => 'invoice_paid']);
        $this->assertTrue($unlapseAfterRejoin, 'Step 4: unlapse must be allowed after explicit rejoin');
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
