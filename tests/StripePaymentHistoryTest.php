<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\fetch_gmtu_payment_months;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_membership_product_ids;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\is_gmtu_subscription;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\fetch_paid_invoice_timestamps;

/**
 * Tests for StripePaymentHistory.php.
 *
 * All Stripe API calls are replaced by injectable callables so no real HTTP
 * requests are made. The Stripe SDK is available as a dev dependency so real
 * Stripe object types can be constructed via constructFrom() for type safety.
 */
class StripePaymentHistoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a fake Stripe customer list response.
     *
     * @param string[] $ids Customer IDs to include.
     * @return object{data: object[], has_more: bool}
     */
    private function fakeCustomerList(array $ids): object
    {
        $data = array_map(fn($id) => (object)['id' => $id], $ids);
        return (object)['data' => $data, 'has_more' => false];
    }

    /**
     * Build a real \Stripe\Subscription object with a single item.
     *
     * @param string $id         Subscription ID.
     * @param string $product_id The product ID on the price (bare string form).
     * @return \Stripe\Subscription
     */
    private function fakeSubscription(string $id, string $product_id): \Stripe\Subscription
    {
        return \Stripe\Subscription::constructFrom([
            'id'     => $id,
            'object' => 'subscription',
            'items'  => [
                'object'   => 'list',
                'data'     => [
                    [
                        'id'     => 'si_' . $id,
                        'object' => 'subscription_item',
                        'price'  => [
                            'id'     => 'price_test',
                            'object' => 'price',
                            'product' => $product_id,
                        ],
                    ],
                ],
                'has_more' => false,
                'url'      => '/v1/subscription_items',
            ],
        ]);
    }

    /**
     * Build a fake subscription list response.
     *
     * @param \Stripe\Subscription[] $subscriptions
     * @param bool $has_more
     * @return object
     */
    private function fakeSubscriptionList(array $subscriptions, bool $has_more = false): object
    {
        return (object)['data' => $subscriptions, 'has_more' => $has_more];
    }

    /**
     * Build a fake Invoice list response.
     *
     * @param int[] $paid_at_timestamps Unix timestamps for paid invoices.
     * @param bool  $has_more
     * @return object
     */
    private function fakeInvoiceList(array $paid_at_timestamps, bool $has_more = false): object
    {
        $data = array_map(function (int $ts) {
            static $i = 0;
            $i++;
            return (object)[
                'id'                 => "in_$i",
                'status_transitions' => (object)['paid_at' => $ts],
            ];
        }, $paid_at_timestamps);
        return (object)['data' => $data, 'has_more' => $has_more];
    }

    /**
     * Build a fake wpdb that returns the given rows from get_results().
     */
    private function fakeWpdb(array $rows): object
    {
        return new class ($rows) {
            public string $options = 'wp_options';

            public function __construct(private array $rows)
            {
            }

            public function prepare(string $query): string
            {
                return $query;
            }

            public function esc_like(string $str): string
            {
                return $str;
            }

            public function get_results(string $query, $output): array
            {
                return $this->rows;
            }
        };
    }

    /**
     * Unix timestamp for a given 'YYYY-MM-DD' string.
     */
    private function ts(string $date): int
    {
        return (int)strtotime($date . 'T12:00:00Z');
    }

    // -------------------------------------------------------------------------
    // get_membership_product_ids
    // -------------------------------------------------------------------------

    public function test_product_ids_empty_when_no_plans()
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([]);

        $ids = get_membership_product_ids();

        $this->assertSame([], $ids);
    }

    public function test_product_ids_extracted_from_single_plan()
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([
            ['option_value' => serialize(['stripe_product_id' => 'prod_abc'])],
        ]);
        Functions\expect('maybe_unserialize')->andReturnUsing(fn($v) => unserialize($v));

        $ids = get_membership_product_ids();

        $this->assertSame(['prod_abc'], $ids);
    }

    public function test_product_ids_extracted_from_multiple_plans()
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([
            ['option_value' => serialize(['stripe_product_id' => 'prod_1'])],
            ['option_value' => serialize(['stripe_product_id' => 'prod_2'])],
        ]);
        Functions\expect('maybe_unserialize')->andReturnUsing(fn($v) => unserialize($v));

        $ids = get_membership_product_ids();

        $this->assertSame(['prod_1', 'prod_2'], $ids);
    }

    public function test_product_ids_deduplicated()
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([
            ['option_value' => serialize(['stripe_product_id' => 'prod_same'])],
            ['option_value' => serialize(['stripe_product_id' => 'prod_same'])],
        ]);
        Functions\expect('maybe_unserialize')->andReturnUsing(fn($v) => unserialize($v));

        $ids = get_membership_product_ids();

        $this->assertSame(['prod_same'], $ids);
    }

    public function test_product_ids_skips_plans_without_stripe_product_id()
    {
        global $wpdb;
        $wpdb = $this->fakeWpdb([
            ['option_value' => serialize(['label' => 'No Stripe Yet'])],
            ['option_value' => serialize(['stripe_product_id' => 'prod_ok'])],
        ]);
        Functions\expect('maybe_unserialize')->andReturnUsing(fn($v) => unserialize($v));

        $ids = get_membership_product_ids();

        $this->assertSame(['prod_ok'], $ids);
    }

    // -------------------------------------------------------------------------
    // is_gmtu_subscription
    // -------------------------------------------------------------------------

    public function test_is_gmtu_subscription_matches_bare_product_id()
    {
        $sub = $this->fakeSubscription('sub_1', 'prod_gmtu');

        $this->assertTrue(is_gmtu_subscription($sub, ['prod_gmtu']));
    }

    public function test_is_gmtu_subscription_no_match_when_different_product()
    {
        $sub = $this->fakeSubscription('sub_1', 'prod_other');

        $this->assertFalse(is_gmtu_subscription($sub, ['prod_gmtu']));
    }

    public function test_is_gmtu_subscription_no_match_with_empty_product_id_list()
    {
        $sub = $this->fakeSubscription('sub_1', 'prod_gmtu');

        $this->assertFalse(is_gmtu_subscription($sub, []));
    }

    public function test_is_gmtu_subscription_matches_one_of_multiple_product_ids()
    {
        $sub = $this->fakeSubscription('sub_1', 'prod_b');

        $this->assertTrue(is_gmtu_subscription($sub, ['prod_a', 'prod_b', 'prod_c']));
    }

    public function test_is_gmtu_subscription_with_no_items()
    {
        $sub = \Stripe\Subscription::constructFrom([
            'id'     => 'sub_empty',
            'object' => 'subscription',
            'items'  => [
                'object'   => 'list',
                'data'     => [],
                'has_more' => false,
                'url'      => '/v1/subscription_items',
            ],
        ]);

        $this->assertFalse(is_gmtu_subscription($sub, ['prod_gmtu']));
    }

    public function test_is_gmtu_subscription_with_expanded_product_object()
    {
        // When the Product is expanded, price->product is a Product object, not a string.
        $sub = \Stripe\Subscription::constructFrom([
            'id'     => 'sub_expanded',
            'object' => 'subscription',
            'items'  => [
                'object'   => 'list',
                'data'     => [
                    [
                        'id'     => 'si_1',
                        'object' => 'subscription_item',
                        'price'  => [
                            'id'      => 'price_1',
                            'object'  => 'price',
                            'product' => [
                                'id'     => 'prod_gmtu',
                                'object' => 'product',
                                'name'   => 'Membership: Monthly',
                            ],
                        ],
                    ],
                ],
                'has_more' => false,
                'url'      => '/v1/subscription_items',
            ],
        ]);

        $this->assertTrue(is_gmtu_subscription($sub, ['prod_gmtu']));
    }

    public function test_is_gmtu_subscription_matches_first_of_multiple_items()
    {
        $sub = \Stripe\Subscription::constructFrom([
            'id'     => 'sub_multi',
            'object' => 'subscription',
            'items'  => [
                'object'   => 'list',
                'data'     => [
                    [
                        'id'     => 'si_1',
                        'object' => 'subscription_item',
                        'price'  => ['id' => 'price_1', 'object' => 'price', 'product' => 'prod_other'],
                    ],
                    [
                        'id'     => 'si_2',
                        'object' => 'subscription_item',
                        'price'  => ['id' => 'price_2', 'object' => 'price', 'product' => 'prod_gmtu'],
                    ],
                ],
                'has_more' => false,
                'url'      => '/v1/subscription_items',
            ],
        ]);

        $this->assertTrue(is_gmtu_subscription($sub, ['prod_gmtu']));
    }

    // -------------------------------------------------------------------------
    // fetch_paid_invoice_timestamps
    // -------------------------------------------------------------------------

    public function test_invoice_timestamps_returned_from_single_page()
    {
        $ts = $this->ts('2026-01-15');
        $lister = fn($params) => $this->fakeInvoiceList([$ts]);

        $result = fetch_paid_invoice_timestamps('cus_1', 'sub_1', $lister);

        $this->assertSame([$ts], $result);
    }

    public function test_invoice_timestamps_empty_when_no_invoices()
    {
        $lister = fn($params) => $this->fakeInvoiceList([]);

        $result = fetch_paid_invoice_timestamps('cus_1', 'sub_1', $lister);

        $this->assertSame([], $result);
    }

    public function test_invoice_timestamps_skips_null_paid_at()
    {
        $invoiceList = (object)[
            'data'     => [
                (object)['id' => 'in_1', 'status_transitions' => (object)['paid_at' => null]],
                (object)['id' => 'in_2', 'status_transitions' => (object)['paid_at' => 0]],
                (object)['id' => 'in_3', 'status_transitions' => (object)['paid_at' => $this->ts('2026-02-10')]],
            ],
            'has_more' => false,
        ];
        $lister = fn($params) => $invoiceList;

        $result = fetch_paid_invoice_timestamps('cus_1', 'sub_1', $lister);

        $this->assertCount(1, $result);
        $this->assertSame($this->ts('2026-02-10'), $result[0]);
    }

    public function test_invoice_timestamps_paginates_correctly()
    {
        $ts1 = $this->ts('2026-01-15');
        $ts2 = $this->ts('2026-02-15');

        $page1 = (object)[
            'data'     => [(object)['id' => 'in_1', 'status_transitions' => (object)['paid_at' => $ts1]]],
            'has_more' => true,
        ];
        $page2 = (object)[
            'data'     => [(object)['id' => 'in_2', 'status_transitions' => (object)['paid_at' => $ts2]]],
            'has_more' => false,
        ];

        $calls = 0;
        $lister = function ($params) use ($page1, $page2, &$calls) {
            $calls++;
            return $calls === 1 ? $page1 : $page2;
        };

        $result = fetch_paid_invoice_timestamps('cus_1', 'sub_1', $lister);

        $this->assertCount(2, $result);
        $this->assertContains($ts1, $result);
        $this->assertContains($ts2, $result);
        $this->assertSame(2, $calls);
    }

    public function test_invoice_pagination_sets_starting_after()
    {
        $ts1 = $this->ts('2026-01-15');
        $ts2 = $this->ts('2026-02-15');

        $page1 = (object)[
            'data'     => [(object)['id' => 'in_page1_last', 'status_transitions' => (object)['paid_at' => $ts1]]],
            'has_more' => true,
        ];
        $page2 = (object)[
            'data'     => [(object)['id' => 'in_page2', 'status_transitions' => (object)['paid_at' => $ts2]]],
            'has_more' => false,
        ];

        $capturedParams = [];
        $calls = 0;
        $lister = function ($params) use ($page1, $page2, &$calls, &$capturedParams) {
            $capturedParams[] = $params;
            $calls++;
            return $calls === 1 ? $page1 : $page2;
        };

        fetch_paid_invoice_timestamps('cus_1', 'sub_1', $lister);

        // Second call must include starting_after = last invoice ID of page 1
        $this->assertArrayHasKey('starting_after', $capturedParams[1]);
        $this->assertSame('in_page1_last', $capturedParams[1]['starting_after']);
    }

    // -------------------------------------------------------------------------
    // fetch_gmtu_payment_months — error paths
    // -------------------------------------------------------------------------

    public function test_returns_error_when_stripe_key_not_configured()
    {
        unset($_ENV['STRIPE_SECRET_KEY']);

        $result = fetch_gmtu_payment_months('member@example.com');

        $this->assertNull($result['month_keys'] ? null : null); // just checking structure
        $this->assertSame([], $result['month_keys']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Stripe secret key', $result['error']);
    }

    public function test_returns_error_when_no_membership_products_configured()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => [],  // no product IDs
        );

        $this->assertSame([], $result['month_keys']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('No membership plans', $result['error']);
    }

    public function test_returns_empty_month_keys_when_no_customers_found()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $result = fetch_gmtu_payment_months(
            'nobody@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList([]),  // no customers
        );

        $this->assertSame([], $result['month_keys']);
        $this->assertNull($result['error']);
    }

    public function test_returns_error_on_stripe_api_exception()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => throw new \RuntimeException('Connection timeout'),
        );

        $this->assertSame([], $result['month_keys']);
        $this->assertSame('Connection timeout', $result['error']);
    }

    // -------------------------------------------------------------------------
    // fetch_gmtu_payment_months — main logic
    // -------------------------------------------------------------------------

    public function test_returns_empty_when_no_qualifying_subscriptions()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $nonGmtuSub = $this->fakeSubscription('sub_other', 'prod_different');

        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList(['cus_1']),
            fn($p) => $this->fakeSubscriptionList([$nonGmtuSub]),
        );

        $this->assertSame([], $result['month_keys']);
        $this->assertNull($result['error']);
    }

    public function test_returns_month_keys_from_paid_invoices()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $gmtuSub = $this->fakeSubscription('sub_gmtu', 'prod_gmtu');
        $ts      = $this->ts('2026-01-15');

        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList(['cus_1']),
            fn($p) => $this->fakeSubscriptionList([$gmtuSub]),
            fn($p) => $this->fakeInvoiceList([$ts]),
        );

        $this->assertSame(['2026-01'], $result['month_keys']);
        $this->assertNull($result['error']);
    }

    public function test_returns_multiple_month_keys_sorted()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $gmtuSub = $this->fakeSubscription('sub_gmtu', 'prod_gmtu');
        $timestamps = [
            $this->ts('2026-03-10'),
            $this->ts('2026-01-05'),
            $this->ts('2026-02-20'),
        ];

        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList(['cus_1']),
            fn($p) => $this->fakeSubscriptionList([$gmtuSub]),
            fn($p) => $this->fakeInvoiceList($timestamps),
        );

        $this->assertSame(['2026-01', '2026-02', '2026-03'], $result['month_keys']);
    }

    public function test_deduplicates_month_keys_from_multiple_invoices_same_month()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $gmtuSub = $this->fakeSubscription('sub_gmtu', 'prod_gmtu');
        $timestamps = [
            $this->ts('2026-01-01'),
            $this->ts('2026-01-15'),  // same month
            $this->ts('2026-01-31'),  // same month
        ];

        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList(['cus_1']),
            fn($p) => $this->fakeSubscriptionList([$gmtuSub]),
            fn($p) => $this->fakeInvoiceList($timestamps),
        );

        $this->assertSame(['2026-01'], $result['month_keys']);
    }

    public function test_aggregates_invoices_across_multiple_subscriptions()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $sub1 = $this->fakeSubscription('sub_1', 'prod_gmtu');
        $sub2 = $this->fakeSubscription('sub_2', 'prod_gmtu');

        $call = 0;
        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList(['cus_1']),
            fn($p) => $this->fakeSubscriptionList([$sub1, $sub2]),
            function ($p) use (&$call) {
                $call++;
                $ts = $call === 1 ? $this->ts('2026-01-10') : $this->ts('2026-03-10');
                return $this->fakeInvoiceList([$ts]);
            }
        );

        $this->assertSame(['2026-01', '2026-03'], $result['month_keys']);
    }

    public function test_aggregates_invoices_across_multiple_customers()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $sub = $this->fakeSubscription('sub_gmtu', 'prod_gmtu');

        $customerCall = 0;
        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList(['cus_1', 'cus_2']),
            fn($p) => $this->fakeSubscriptionList([$sub]),
            function ($p) use (&$customerCall) {
                $customerCall++;
                $ts = $customerCall === 1 ? $this->ts('2026-01-10') : $this->ts('2026-02-10');
                return $this->fakeInvoiceList([$ts]);
            }
        );

        $this->assertSame(['2026-01', '2026-02'], $result['month_keys']);
    }

    public function test_skips_non_gmtu_subscriptions_but_collects_gmtu_ones()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $gmtuSub  = $this->fakeSubscription('sub_gmtu', 'prod_gmtu');
        $otherSub = $this->fakeSubscription('sub_other', 'prod_other');

        $invoiceCallIds = [];
        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList(['cus_1']),
            fn($p) => $this->fakeSubscriptionList([$gmtuSub, $otherSub]),
            function ($p) use (&$invoiceCallIds) {
                $invoiceCallIds[] = $p['subscription'];
                return $this->fakeInvoiceList([$this->ts('2026-01-10')]);
            }
        );

        // Invoice lister should only be called for the GMTU subscription.
        $this->assertSame(['sub_gmtu'], $invoiceCallIds);
        $this->assertSame(['2026-01'], $result['month_keys']);
    }

    public function test_paginates_subscriptions()
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_fake';

        $sub1 = $this->fakeSubscription('sub_1', 'prod_gmtu');
        $sub2 = $this->fakeSubscription('sub_2', 'prod_gmtu');

        $page1 = (object)['data' => [$sub1], 'has_more' => true];
        $page2 = (object)['data' => [$sub2], 'has_more' => false];

        $subCall = 0;
        $capturedParams = [];
        $invoiceCall = 0;

        $result = fetch_gmtu_payment_months(
            'member@example.com',
            fn() => ['prod_gmtu'],
            fn($p) => $this->fakeCustomerList(['cus_1']),
            function ($p) use ($page1, $page2, &$subCall, &$capturedParams) {
                $capturedParams[] = $p;
                $subCall++;
                return $subCall === 1 ? $page1 : $page2;
            },
            function ($p) use (&$invoiceCall) {
                $invoiceCall++;
                $ts = $invoiceCall === 1 ? $this->ts('2026-01-10') : $this->ts('2026-02-10');
                return $this->fakeInvoiceList([$ts]);
            }
        );

        // Both subscription pages were fetched.
        $this->assertSame(2, $subCall);
        // Second page request used starting_after = first subscription's ID.
        $this->assertSame('sub_1', $capturedParams[1]['starting_after']);
        // Both subscriptions' invoices were collected.
        $this->assertSame(['2026-01', '2026-02'], $result['month_keys']);
    }

    protected function tear_down(): void
    {
        unset($_ENV['STRIPE_SECRET_KEY']);
        parent::tear_down();
    }
}
