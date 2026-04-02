<?php
/**
 * Stripe payment history fetcher for GMTU membership classification.
 *
 * Identifies GMTU membership payments by finding Stripe subscriptions whose
 * items belong to a configured membership product, then reading the paid
 * invoices for those subscriptions.
 *
 * This mirrors how the parent CK Join Flow plugin manages memberships: each
 * membership plan tier has a Stripe Product, and members are subscribed to a
 * Price under that Product. Plan product IDs are stored in WordPress options
 * under the 'ck_join_flow_membership_plan_*' prefix.
 *
 * Returns a list of 'YYYY-MM' month keys (UTC) for months that had at least
 * one qualifying paid invoice.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Fetch the months in which a member made a successful GMTU membership payment.
 *
 * Queries the Stripe Subscriptions and Invoices APIs for all customers
 * matching the email, filtered to subscriptions belonging to configured
 * membership products. Returns deduplicated 'YYYY-MM' month keys (UTC).
 *
 * Fails open: on Stripe API error, returns an error string so the caller
 * can fall through to the parent plugin's default behaviour rather than
 * accidentally lapsing a member.
 *
 * @param string $email Member email address.
 * @return array{month_keys: string[], error: string|null}
 */
function fetch_gmtu_payment_months(string $email): array
{
    try {
        $stripe_key = \CommonKnowledge\JoinBlock\Settings::get('STRIPE_SECRET_KEY');
        if (empty($stripe_key)) {
            return ['month_keys' => [], 'error' => 'Stripe secret key not configured'];
        }

        \Stripe\Stripe::setApiKey($stripe_key);

        $product_ids = get_membership_product_ids();
        if (empty($product_ids)) {
            return ['month_keys' => [], 'error' => 'No membership plans configured'];
        }

        // A single email address may have multiple Stripe customer records.
        $customers = \Stripe\Customer::all(['email' => $email, 'limit' => 10]);

        $timestamps = [];

        foreach ($customers->data as $customer) {
            $has_more = true;
            $params   = ['customer' => $customer->id, 'status' => 'all', 'limit' => 100];

            while ($has_more) {
                $subscriptions = \Stripe\Subscription::all($params);
                $has_more      = $subscriptions->has_more;

                foreach ($subscriptions->data as $subscription) {
                    if ($has_more) {
                        $params['starting_after'] = $subscription->id;
                    }

                    if (!is_gmtu_subscription($subscription, $product_ids)) {
                        continue;
                    }

                    $timestamps = array_merge(
                        $timestamps,
                        fetch_paid_invoice_timestamps($customer->id, $subscription->id)
                    );
                }
            }
        }

        if (empty($timestamps)) {
            return ['month_keys' => [], 'error' => null];
        }

        sort($timestamps);
        $month_keys = array_values(array_unique(
            array_map(fn(int $ts) => gmdate('Y-m', $ts), $timestamps)
        ));

        return ['month_keys' => $month_keys, 'error' => null];

    } catch (\Stripe\Exception\ApiErrorException $e) {
        return ['month_keys' => [], 'error' => $e->getMessage()];
    } catch (\Throwable $e) {
        return ['month_keys' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Retrieve all configured membership product IDs from WordPress options.
 *
 * Reads every option with the 'ck_join_flow_membership_plan_' prefix (stored
 * by the parent plugin when plans are saved) and collects their
 * 'stripe_product_id' values.
 *
 * @return string[] Stripe product IDs for all configured membership plans.
 */
function get_membership_product_ids(): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('ck_join_flow_membership_plan_') . '%'
        ),
        ARRAY_A
    );

    $product_ids = [];
    foreach ($rows as $row) {
        $plan = maybe_unserialize($row['option_value']);
        if (is_array($plan) && !empty($plan['stripe_product_id'])) {
            $product_ids[] = $plan['stripe_product_id'];
        }
    }

    return array_values(array_unique($product_ids));
}

/**
 * Determine whether a Stripe subscription is a GMTU membership subscription.
 *
 * A subscription qualifies if at least one of its line items belongs to a
 * configured membership product.
 *
 * @param \Stripe\Subscription $subscription
 * @param string[]             $product_ids   Configured membership product IDs.
 * @return bool
 */
function is_gmtu_subscription(\Stripe\Subscription $subscription, array $product_ids): bool
{
    foreach ($subscription->items->data as $item) {
        $product = $item->price->product ?? null;
        if (is_string($product) && in_array($product, $product_ids, true)) {
            return true;
        }
        // Price may have an expanded Product object rather than a bare ID.
        if ($product instanceof \Stripe\Product && in_array($product->id, $product_ids, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Fetch timestamps of paid invoices for a given subscription.
 *
 * Uses the Stripe Invoices API, paginating through all paid invoices for the
 * specified customer and subscription. Returns the unix timestamp at which
 * each invoice was paid (status_transitions->paid_at).
 *
 * @param string $customer_id     Stripe customer ID.
 * @param string $subscription_id Stripe subscription ID.
 * @return int[]
 */
function fetch_paid_invoice_timestamps(string $customer_id, string $subscription_id): array
{
    $timestamps = [];
    $has_more   = true;
    $params     = [
        'customer'     => $customer_id,
        'subscription' => $subscription_id,
        'status'       => 'paid',
        'limit'        => 100,
    ];

    while ($has_more) {
        $invoices = \Stripe\Invoice::all($params);
        $has_more = $invoices->has_more;

        foreach ($invoices->data as $invoice) {
            if ($has_more) {
                $params['starting_after'] = $invoice->id;
            }

            $paid_at = $invoice->status_transitions->paid_at ?? null;
            if (is_int($paid_at) && $paid_at > 0) {
                $timestamps[] = $paid_at;
            }
        }
    }

    return $timestamps;
}
