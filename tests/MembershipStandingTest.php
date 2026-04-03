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

    /**
     * When last paid equals the current (in-progress) month, missed = 0.
     *
     * The calculation is max(0, as_of_index - 1 - as_of_index) = max(0, -1) = 0.
     * This clamp is load-bearing for the new-member exception path: without it,
     * a first-time payment in the current month would produce a negative count.
     */
    public function test_count_missed_clamped_to_zero_when_last_paid_is_current_month()
    {
        $this->assertSame(0, count_missed_completed_months('2026-04', '2026-04'));
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
        $result = classify_membership_standing(['2026-03'], '2026-04');
        $this->assertSame(STANDING_GOOD, $result);
    }

    public function test_good_standing_two_missed_months()
    {
        // 2 missed = upper boundary of Good band
        $result = classify_membership_standing(['2026-01'], '2026-04');
        $this->assertSame(STANDING_GOOD, $result);
    }

    public function test_early_arrears_three_missed_months()
    {
        // Missed Jan, Feb, Mar = 3
        $result = classify_membership_standing(['2025-12'], '2026-04');
        $this->assertSame(STANDING_EARLY_ARREARS, $result);
    }

    public function test_lapsing_four_missed_months()
    {
        $result = classify_membership_standing(['2025-11'], '2026-04');
        $this->assertSame(STANDING_LAPSING, $result);
    }

    public function test_lapsing_six_missed_months()
    {
        $result = classify_membership_standing(['2025-09'], '2026-04');
        $this->assertSame(STANDING_LAPSING, $result);
    }

    public function test_lapsed_seven_missed_months()
    {
        $result = classify_membership_standing(['2025-08'], '2026-04');
        $this->assertSame(STANDING_LAPSED, $result);
    }

    /**
     * Year-boundary case: 7 missed months spanning two calendar years.
     *
     * Last paid May 2025 → missed Jun, Jul, Aug, Sep, Oct, Nov, Dec 2025 = 7 months.
     * As-of January 2026. month_key_to_index must use year × 12 + month for
     * the arithmetic to produce the correct count across the year boundary.
     */
    public function test_lapsed_seven_missed_months_across_year_boundary()
    {
        $result = classify_membership_standing(['2025-05'], '2026-01');
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_lapsed_no_payment_history_at_all()
    {
        $result = classify_membership_standing([], '2026-04');
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_new_member_first_payment_this_month_is_good()
    {
        // No prior payments, first payment is in the current month
        $result = classify_membership_standing(['2026-04'], '2026-04');
        $this->assertSame(STANDING_GOOD, $result);
    }

    public function test_lapsed_flag_overrides_good_payment()
    {
        // Even with a recent payment, lapsed flag wins -- must rejoin
        $result = classify_membership_standing(['2026-03'], '2026-04', true);
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_lapsed_flag_overrides_current_month_payment()
    {
        // Lapsed flag wins even over a current-month payment
        $result = classify_membership_standing(['2026-04'], '2026-04', true);
        $this->assertSame(STANDING_LAPSED, $result);
    }

    public function test_year_boundary_three_missed()
    {
        // As-of March 2026, last paid November 2025: missed Dec, Jan, Feb = 3
        $result = classify_membership_standing(['2025-11'], '2026-03');
        $this->assertSame(STANDING_EARLY_ARREARS, $result);
    }

    public function test_multiple_payments_uses_most_recent_for_missed_count()
    {
        // Most recent prior payment is Feb, missed only March = 1 -> good
        $result = classify_membership_standing(['2025-06', '2026-02'], '2026-04');
        $this->assertSame(STANDING_GOOD, $result);
    }

    /**
     * A current-month payment does NOT immediately reset standing when the
     * member has prior payment history. The missed count is still derived from
     * the most recent *prior* month's payment. The status resets next month once
     * the current month becomes a completed month.
     */
    public function test_current_month_payment_does_not_reset_standing_when_prior_history_exists()
    {
        $fiveMonthsAgo = gmdate('Y-m', mktime(0, 0, 0, (int)gmdate('n') - 6, 1, (int)gmdate('Y')));
        $thisMonth     = gmdate('Y-m');
        $result = classify_membership_standing([$fiveMonthsAgo, $thisMonth], $thisMonth);
        $this->assertSame(STANDING_LAPSING, $result);
    }
}
