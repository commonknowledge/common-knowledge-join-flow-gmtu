<?php
/**
 * GMTU membership lapsing override.
 *
 * Intercepts the parent plugin's lapsing decisions and applies GMTU's
 * standing rules in place of Stripe's default behaviour.
 *
 * Standing is classified by counting completed calendar months since the
 * member's last successful GMTU payment (see MembershipStanding.php).
 * Stripe's default fires too aggressively — GMTU only lapses at 7+ months.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Register lapsing override hooks.
 *
 * Hooks registered:
 *   - ck_join_flow_should_lapse_member   (filter, priority 10)
 *   - ck_join_flow_should_unlapse_member (filter, priority 10)
 *   - ck_join_flow_success               (action, priority 5)
 *
 * @param callable|null $fetcher Optional override for fetch_gmtu_payment_months().
 *                               Used in tests to inject a fake fetcher. Defaults to
 *                               the real Stripe-backed implementation.
 * @return void
 */
function register_lapsing_override(?callable $fetcher = null): void
{
    $fetch = $fetcher ?? __NAMESPACE__ . '\fetch_gmtu_payment_months';

    // ------------------------------------------------------------------
    // Filter: should we lapse this member?
    // ------------------------------------------------------------------
    add_filter('ck_join_flow_should_lapse_member', function (bool $should_lapse, string $email, array $context) use ($fetch): bool {
        if (($context['provider'] ?? '') !== 'stripe') {
            return $should_lapse;
        }

        $history = $fetch($email);

        if ($history['error'] !== null) {
            log_warning("GMTU lapsing override: Stripe API error for $email ({$history['error']}). Passing through to default.");
            return $should_lapse;
        }

        // No GMTU payment history at all — not a GMTU member, do not interfere.
        if (empty($history['month_keys']) && $history['first_ever_payment_timestamp'] === null) {
            return $should_lapse;
        }

        $sticky   = is_sticky_lapsed($email);
        $now_utc  = gmdate('Y-m');
        $standing = classify_membership_standing($history['month_keys'], $now_utc, $sticky);

        if ($standing === STANDING_LAPSED) {
            $trigger   = $context['trigger'] ?? 'unknown';
            $lapsed_at = gmdate('Y-m-d\TH:i:s\Z');
            mark_sticky_lapsed($email, $trigger, $lapsed_at);
            log_info("GMTU lapsing override: $email is LAPSED ($trigger). Allowing lapse and setting sticky flag.");
            return true;
        }

        log_info("GMTU lapsing override: $email is $standing. Suppressing lapse.");
        return false;
    }, 10, 3);

    // ------------------------------------------------------------------
    // Filter: should we unlapse this member?
    // ------------------------------------------------------------------
    add_filter('ck_join_flow_should_unlapse_member', function (bool $should_unlapse, string $email, array $context) use ($fetch): bool {
        if (($context['provider'] ?? '') !== 'stripe') {
            return $should_unlapse;
        }

        $history = $fetch($email);

        if ($history['error'] !== null) {
            log_warning("GMTU lapsing override: Stripe API error for $email ({$history['error']}). Passing through to default.");
            return $should_unlapse;
        }

        // No GMTU history — not a GMTU member, do not interfere.
        if (empty($history['month_keys']) && $history['first_ever_payment_timestamp'] === null) {
            return $should_unlapse;
        }

        $sticky   = is_sticky_lapsed($email);
        $now_utc  = gmdate('Y-m');
        $standing = classify_membership_standing($history['month_keys'], $now_utc, $sticky);

        if ($standing === STANDING_GOOD && !$sticky) {
            log_info("GMTU lapsing override: $email is GOOD (not sticky). Allowing unlapse.");
            return true;
        }

        log_info("GMTU lapsing override: $email is $standing (sticky=$sticky). Suppressing unlapse.");
        return false;
    }, 10, 3);

    // ------------------------------------------------------------------
    // Action: clear sticky-lapsed flag on explicit rejoin.
    // Priority 5 runs before the notification hooks (10, 20).
    // ------------------------------------------------------------------
    add_action('ck_join_flow_success', function (array $data): void {
        $email = $data['email'] ?? null;
        if ($email && is_sticky_lapsed($email)) {
            clear_sticky_lapsed($email);
            log_info("GMTU lapsing override: Cleared sticky-lapsed flag for $email on successful rejoin.");
        }
    }, 5, 1);
}
