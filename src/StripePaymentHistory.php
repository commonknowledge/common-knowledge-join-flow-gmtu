<?php
/**
 * Stripe payment history fetcher for GMTU membership classification.
 *
 * Fetches only charges that are identifiable as GMTU membership payments:
 *   - status: succeeded
 *   - metadata.id = 'join-gmtu'
 *   - application = GMTU_APPLICATION_ID (Action Network Stripe Connect app)
 *   - not refunded
 *
 * Returns a list of 'YYYY-MM' month keys (UTC) for months that had at least
 * one qualifying charge. Used by LapsingOverride to classify member standing.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Stripe Connect application ID for Action Network (GMTU's integration).
 * Only charges created through this app are counted as GMTU payments.
 */
const GMTU_APPLICATION_ID = 'ca_A2Dv6C8pMeDm6Q0YSXlmSgtdL5tvArgN';

/**
 * Metadata key that identifies a charge as a GMTU membership payment.
 */
const GMTU_METADATA_ID = 'join-gmtu';

/**
 * Fetch the months in which a member made a successful GMTU payment.
 *
 * Queries the Stripe Charges API for all customers matching the email,
 * filters to GMTU-scoped successful charges, and returns deduplicated
 * 'YYYY-MM' month keys (UTC).
 *
 * Fails open: on Stripe API error, returns an error string so the caller
 * can fall through to the parent plugin's default behaviour rather than
 * accidentally lapsing a member.
 *
 * @param string $email Member email address.
 * @return array{
 *   month_keys: string[],
 *   first_ever_payment_timestamp: int|null,
 *   error: string|null
 * }
 */
function fetch_gmtu_payment_months(string $email): array
{
    try {
        $stripe_key = \CommonKnowledge\JoinBlock\Settings::get('STRIPE_SECRET_KEY');
        if (empty($stripe_key)) {
            return ['month_keys' => [], 'first_ever_payment_timestamp' => null, 'error' => 'Stripe secret key not configured'];
        }

        \Stripe\Stripe::setApiKey($stripe_key);

        // A single email address may have multiple Stripe customer records.
        $customers = \Stripe\Customer::all(['email' => $email, 'limit' => 10]);

        $timestamps = [];

        foreach ($customers->data as $customer) {
            $has_more = true;
            $params   = ['customer' => $customer->id, 'limit' => 100];

            while ($has_more) {
                $charges  = \Stripe\Charge::all($params);
                $has_more = $charges->has_more;

                foreach ($charges->data as $charge) {
                    // Skip last item fetched (used as cursor for next page)
                    if ($has_more) {
                        $params['starting_after'] = $charge->id;
                    }

                    if (!is_gmtu_charge($charge)) {
                        continue;
                    }

                    $timestamps[] = $charge->created;
                }
            }
        }

        if (empty($timestamps)) {
            return ['month_keys' => [], 'first_ever_payment_timestamp' => null, 'error' => null];
        }

        sort($timestamps);
        $month_keys = array_values(array_unique(
            array_map(fn(int $ts) => gmdate('Y-m', $ts), $timestamps)
        ));

        return [
            'month_keys'                  => $month_keys,
            'first_ever_payment_timestamp' => $timestamps[0],
            'error'                        => null,
        ];
    } catch (\Stripe\Exception\ApiErrorException $e) {
        return ['month_keys' => [], 'first_ever_payment_timestamp' => null, 'error' => $e->getMessage()];
    } catch (\Throwable $e) {
        return ['month_keys' => [], 'first_ever_payment_timestamp' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Determine whether a Stripe charge counts as a GMTU membership payment.
 *
 * A charge qualifies if:
 *   - status is 'succeeded'
 *   - metadata.id equals 'join-gmtu'
 *   - application equals the Action Network Stripe Connect app ID
 *   - it has not been refunded
 *
 * @param \Stripe\Charge $charge
 * @return bool
 */
function is_gmtu_charge(\Stripe\Charge $charge): bool
{
    if ($charge->status !== 'succeeded') {
        return false;
    }
    if (($charge->metadata->id ?? null) !== GMTU_METADATA_ID) {
        return false;
    }
    if ($charge->application !== GMTU_APPLICATION_ID) {
        return false;
    }
    if ($charge->refunded || $charge->amount_refunded > 0) {
        return false;
    }
    return true;
}
