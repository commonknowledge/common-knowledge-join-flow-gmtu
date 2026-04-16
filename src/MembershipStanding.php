<?php
/**
 * GMTU membership standing classifier.
 *
 * Pure functions -- no I/O, no WordPress calls, fully unit-testable.
 * Implements GMTU's lapsing rules based on completed calendar months.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

const STANDING_GOOD          = 'good';
const STANDING_EARLY_ARREARS = 'early_arrears';
const STANDING_LAPSING       = 'lapsing';
const STANDING_LAPSED        = 'lapsed';

/**
 * Convert a 'YYYY-MM' month key to a monotonic integer index for arithmetic.
 *
 * @param string $month_key 'YYYY-MM'
 * @return int
 */
function month_key_to_index(string $month_key): int
{
    [$year, $month] = explode('-', $month_key);
    return (int)$year * 12 + (int)$month;
}

/**
 * Count completed calendar months missed since the last successful payment.
 *
 * The current in-progress month ($as_of_month_key) is excluded from the count.
 * The "last completed month" is the month immediately before $as_of_month_key.
 *
 * @param string $last_paid_month_key 'YYYY-MM' of last paid month.
 * @param string $as_of_month_key     Current month 'YYYY-MM'.
 * @return int Number of missed completed months.
 */
function count_missed_completed_months(string $last_paid_month_key, string $as_of_month_key): int
{
    $last_completed_index = month_key_to_index($as_of_month_key) - 1;
    $last_paid_index      = month_key_to_index($last_paid_month_key);

    return max(0, $last_completed_index - $last_paid_index);
}

/**
 * Find the most recent paid month that is strictly before the current month.
 *
 * @param string[] $paid_month_keys Sorted array of 'YYYY-MM' strings.
 * @param string   $as_of_month_key Current month 'YYYY-MM'.
 * @return string|null
 */
function last_paid_month_before(array $paid_month_keys, string $as_of_month_key): ?string
{
    $prior = array_filter($paid_month_keys, fn($k) => $k < $as_of_month_key);
    if (empty($prior)) {
        return null;
    }
    return max($prior);
}

/**
 * Classify a member's GMTU membership standing.
 *
 * Standing is based on the number of completed calendar months since the
 * member's last successful payment. The current in-progress month is excluded.
 *
 * If the member is flagged as lapsed (7+ missed months in a prior assessment),
 * that state takes precedence -- a later payment does not reinstate them
 * automatically. They must rejoin via the join form.
 *
 * @param string[] $paid_month_keys  'YYYY-MM' strings for months with a successful payment.
 * @param string   $as_of_month_key  Current month 'YYYY-MM' (the in-progress month, excluded).
 * @param bool     $is_lapsed        Whether the member has previously been marked as lapsed.
 * @return string One of the STANDING_* constants.
 */
function classify_membership_standing(
    array $paid_month_keys,
    string $as_of_month_key,
    bool $is_lapsed = false
): string {
    // Lapsed always wins -- a later payment does not reinstate automatically.
    if ($is_lapsed) {
        return STANDING_LAPSED;
    }

    $last_paid = last_paid_month_before($paid_month_keys, $as_of_month_key);

    // New member exception: first-ever payment is in the current month and there
    // are no prior payments -- treat as Good standing immediately.
    if ($last_paid === null && in_array($as_of_month_key, $paid_month_keys, true)) {
        return STANDING_GOOD;
    }

    // No payment history at all (before or during current month).
    if ($last_paid === null) {
        return STANDING_LAPSED;
    }

    $missed = count_missed_completed_months($last_paid, $as_of_month_key);

    if ($missed <= 2) {
        return STANDING_GOOD;
    }
    if ($missed === 3) {
        return STANDING_EARLY_ARREARS;
    }
    if ($missed <= 6) {
        return STANDING_LAPSING;
    }
    return STANDING_LAPSED;
}
