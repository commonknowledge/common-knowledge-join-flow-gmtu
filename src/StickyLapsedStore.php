<?php
/**
 * Persistent storage for the GMTU sticky-lapsed flag.
 *
 * Once a member reaches Lapsed status (7+ missed completed months), a later
 * payment does not automatically reinstate them. This module records that state
 * in WordPress options so it survives across webhook calls and page loads.
 *
 * Storage: wp_options, key = 'gmtu_sticky_lapsed_' + SHA-256(lowercased email).
 * The value is a JSON object with email, lapsed_at timestamp, and trigger name
 * for audit purposes.
 *
 * The flag is cleared by clear_sticky_lapsed() when a member explicitly rejoins
 * via the join form (hooked into ck_join_flow_success at priority 5).
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Generate the WordPress option key for a given email address.
 *
 * Uses SHA-256 of the lowercased, trimmed email so that keys are a fixed
 * length regardless of email length and contain no special characters.
 *
 * @param string $email Member email address.
 * @return string Option name.
 */
function sticky_lapsed_option_key(string $email): string
{
    return 'gmtu_sticky_lapsed_' . hash('sha256', strtolower(trim($email)));
}

/**
 * Check whether a member is currently marked as sticky-lapsed.
 *
 * @param string $email Member email address.
 * @return bool
 */
function is_sticky_lapsed(string $email): bool
{
    return (bool) get_option(sticky_lapsed_option_key($email), false);
}

/**
 * Mark a member as sticky-lapsed.
 *
 * Records the email, timestamp, and the webhook trigger that caused lapsing
 * in the option value for audit purposes.
 *
 * @param string $email   Member email address.
 * @param string $trigger The webhook trigger (e.g. 'invoice_payment_failed').
 * @param string $lapsed_at ISO 8601 timestamp of when lapsing occurred.
 * @return void
 */
function mark_sticky_lapsed(string $email, string $trigger, string $lapsed_at): void
{
    $value = json_encode([
        'email'     => $email,
        'lapsed_at' => $lapsed_at,
        'trigger'   => $trigger,
    ]);

    // Autoload false — only looked up on-demand during webhook processing.
    update_option(sticky_lapsed_option_key($email), $value, false);
}

/**
 * Clear the sticky-lapsed flag for a member.
 *
 * Called when a member explicitly rejoins via the join form, allowing them
 * to return to Good standing.
 *
 * @param string $email Member email address.
 * @return void
 */
function clear_sticky_lapsed(string $email): void
{
    delete_option(sticky_lapsed_option_key($email));
}
