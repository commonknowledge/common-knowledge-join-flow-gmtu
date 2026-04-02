<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use function CommonKnowledge\JoinBlock\Organisation\GMTU\classify_membership_standing;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\count_missed_completed_months;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\month_key_to_index;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\last_paid_month_before;
use const CommonKnowledge\JoinBlock\Organisation\GMTU\STANDING_GOOD;
use const CommonKnowledge\JoinBlock\Organisation\GMTU\STANDING_EARLY_ARREARS;
use const CommonKnowledge\JoinBlock\Organisation\GMTU\STANDING_LAPSING;
use const CommonKnowledge\JoinBlock\Organisation\GMTU\STANDING_LAPSED;

class MembershipStandingTest extends TestCase
{
    // --- month_key_to_index ---

    public function test_month_key_to_index_april_2026()
    {
        $this->assertSame(2026 * 12 + 4, month_key_to_index('2026-04'));
    }

    public function test_month_key_to_index_january()
    {
        $this->assertSame(2026 * 12 + 1, month_key_to_index('2026-01'));
    }

    public function test_month_key_to_index_december()
    {
        $this->assertSame(2025 * 12 + 12, month_key_to_index('2025-12'));
    }

    // --- count_missed_completed_months ---

    public function test_count_missed_zero_when_paid_last_month()
    {
        // As-of April, last paid March: last completed month is March, missed = 0
        $this->assertSame(0, count_missed_completed_months('2026-03', '2026-04'));
    }

    public function test_count_missed_one_when_paid_two_months_ago()
    {
        // As-of April, last paid February: missed March = 1
        $this->assertSame(1, count_missed_completed_months('2026-02', '2026-04'));
    }

    public function test_count_missed_two_when_paid_three_months_ago()
    {
        // As-of April, last paid January: missed Feb, Mar = 2
        $this->assertSame(2, count_missed_completed_months('2026-01', '2026-04'));
    }

    public function test_count_missed_three()
    {
        // As-of April, last paid December: missed Jan, Feb, Mar = 3
        $this->assertSame(3, count_missed_completed_months('2025-12', '2026-04'));
    }

    public function test_count_missed_six()
    {
        // As-of April, last paid September: missed Oct, Nov, Dec, Jan, Feb, Mar = 6
        $this->assertSame(6, count_missed_completed_months('2025-09', '2026-04'));
    }

    public function test_count_missed_seven()
    {
        // As-of April, last paid August: missed Sep..Mar = 7
        $this->assertSame(7, count_missed_completed_months('2025-08', '2026-04'));
    }

    public function test_count_missed_year_boundary()
    {
        // As-of March 2026, last paid November 2025: missed Dec, Jan, Feb = 3
        $this->assertSame(3, count_missed_completed_months('2025-11', '2026-03'));
    }

    public function test_count_missed_null_last_paid_returns_large_number()
    {
        $result = count_missed_completed_months(null, '2026-04');
        $this->assertGreaterThan(1000, $result);
    }

    // --- last_paid_month_before ---

    public function test_last_paid_before_current_month()
    {
        $this->assertSame('2026-03', last_paid_month_before(['2026-02', '2026-03'], '2026-04'));
    }

    public function test_last_paid_excludes_current_month()
    {
        // Current month payment should NOT be returned as last_paid
        $this->assertSame('2026-03', last_paid_month_before(['2026-03', '2026-04'], '2026-04'));
    }

    public function test_last_paid_returns_null_when_no_prior_payments()
    {
        // Only current month payment
        $this->assertNull(last_paid_month_before(['2026-04'], '2026-04'));
    }

    public function test_last_paid_returns_null_for_empty_history()
    {
        $this->assertNull(last_paid_month_before([], '2026-04'));
    }

    public function test_last_paid_uses_most_recent_prior_month()
    {
        $this->assertSame('2026-02', last_paid_month_before(['2025-06', '2026-02'], '2026-04'));
    }

    // --- classify_membership_standing ---

    public function test_good_standing_paid_last_month()
    {
        $result = classify_membership_standing(['2026-03'], '2026-04', false);
        $this->assertSame(STANDING_GOOD, $result);
    }

    public function test_good_standing_paid_two_months_ago()
    {
        // Missed 1 completed month
        $result = classify_membership_standing(['2026-02'], '2026-04', false);
        $this->assertSame(STANDING_GOOD, $result);
    }

    public function test_good_standing_paid_three_months_ago()
    {
        // Missed 2 completed months (Jan, Feb) — still good
        $result = classify_membership_standing(['2026-01'], '2026-04', false);
        $this->assertSame(STANDING_GOOD, $result);
    }

    public function test_early_arrears_three_missed_months()
    {
        // Missed Jan, Feb, Mar = 3
        $result = classify_membership_standing(['2025-12'], '2026-04', false);
        $this->assertSame(STANDING_EARLY_ARREARS, $result);
    }

    public function test_lapsing_four_missed_months()
    {
        $result = classify_membership_standing(['2025-11'], '2026-04', false);
        $this->assertSame(STANDING_LAPSING, $result);
    }

    public function test_lapsing_six_missed_months()
    {
        $result = classify_membership_standing(['2025-09'], '2026-04', false);
        $this->assertSame(STANDING_LAPSING, $result);
    }

    public function test_lapsed_seven_missed_months()
    {
        $result = classify_membership_standing(['2025-08'], '2026-04', false);
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_lapsed_twelve_missed_months()
    {
        $result = classify_membership_standing(['2025-03'], '2026-04', false);
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_lapsed_no_payment_history_at_all()
    {
        $result = classify_membership_standing([], '2026-04', false);
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_new_member_first_payment_this_month_is_good()
    {
        // No prior payments, first payment is in the current month
        $result = classify_membership_standing(['2026-04'], '2026-04', false);
        $this->assertSame(STANDING_GOOD, $result);
    }

    public function test_sticky_lapsed_overrides_good_payment()
    {
        // Even with a recent payment, sticky-lapsed wins
        $result = classify_membership_standing(['2026-03'], '2026-04', true);
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_sticky_lapsed_overrides_current_month_payment()
    {
        $result = classify_membership_standing(['2026-04'], '2026-04', true);
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_year_boundary_three_missed()
    {
        // As-of March 2026, last paid November 2025: missed Dec, Jan, Feb = 3
        $result = classify_membership_standing(['2025-11'], '2026-03', false);
        $this->assertSame(STANDING_EARLY_ARREARS, $result);
    }

    public function test_multiple_payments_uses_most_recent_for_missed_count()
    {
        // Most recent prior payment is Feb, missed only March = 1 → good
        $result = classify_membership_standing(['2025-06', '2026-02'], '2026-04', false);
        $this->assertSame(STANDING_GOOD, $result);
    }

    public function test_payment_in_current_month_does_not_override_existing_lapse_determination()
    {
        // Last payment before current month was July 2025 — 8 missed months → lapsed
        // Even if there's also a payment in current month, last_paid_before is still July
        // This covers the case where a sticky-lapsed member paid again without rejoining
        $result = classify_membership_standing(['2025-07', '2026-04'], '2026-04', true);
        $this->assertSame(STANDING_LAPSED, $result);
    }
}
