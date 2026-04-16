<?php
/**
 * GMTU membership lapsing override.
 *
 * Intercepts the parent plugin's lapsing decisions and applies GMTU's
 * standing rules. The parent plugin fires lapse/unlapse events in response
 * to Stripe webhooks, but its default behaviour is more aggressive than
 * GMTU requires -- GMTU only lapses a member after 7 or more missed months.
 *
 * Members who miss fewer than 7 completed months are not lapsed. Any
 * successful payment within that window resets them to Good standing.
 *
 * Once a member reaches 7+ missed months they are lapsed. A later payment
 * does not reinstate them automatically -- they must rejoin via the join form.
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
 *                               the real implementation.
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
            log_warning("GMTU lapsing override: payment history error for $email ({$history['error']}). Passing through to default.");
            return $should_lapse;
        }

        if (empty($history['month_keys'])) {
            log_warning("GMTU lapsing override: no GMTU payment history found for $email. Member may have joined before this plugin was active. Passing through to default.");
            return $should_lapse;
        }

        $lapsed   = is_lapsed($email);
        $now_utc  = gmdate('Y-m');
        $standing = classify_membership_standing($history['month_keys'], $now_utc, $lapsed);

        if ($standing === STANDING_LAPSED) {
            $trigger   = $context['trigger'] ?? 'unknown';
            $lapsed_at = gmdate('Y-m-d\TH:i:s\Z');
            mark_lapsed($email, $trigger, $lapsed_at);
            log_info("GMTU lapsing override: $email is LAPSED ($trigger). Allowing lapse.");
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
            log_warning("GMTU lapsing override: payment history error for $email ({$history['error']}). Passing through to default.");
            return $should_unlapse;
        }

        if (empty($history['month_keys'])) {
            log_warning("GMTU lapsing override: no GMTU payment history found for $email. Member may have joined before this plugin was active. Passing through to default.");
            return $should_unlapse;
        }

        $lapsed   = is_lapsed($email);
        $now_utc  = gmdate('Y-m');
        $standing = classify_membership_standing($history['month_keys'], $now_utc, $lapsed);

        if ($standing === STANDING_GOOD && !$lapsed) {
            log_info("GMTU lapsing override: $email is GOOD. Allowing unlapse.");
            return true;
        }

        log_info("GMTU lapsing override: $email is $standing (lapsed=$lapsed). Suppressing unlapse.");
        return false;
    }, 10, 3);

    // ------------------------------------------------------------------
    // Action: clear lapsed flag on explicit rejoin.
    // Priority 5 runs before the notification hooks (10, 20).
    // ------------------------------------------------------------------
    add_action('ck_join_flow_success', function (array $data): void {
        $email = $data['email'] ?? null;
        if ($email && is_lapsed($email)) {
            clear_lapsed($email);
            log_info("GMTU lapsing override: Cleared lapsed flag for $email on successful rejoin.");
        }
    }, 5, 1);
}
