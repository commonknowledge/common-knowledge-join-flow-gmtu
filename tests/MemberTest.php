<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_member_details;

class MemberTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane@example.com',
            'addressPostcode' => 'M14 5RQ',
            'branch' => 'Moss Side',
            'customFields' => ['branch' => 'Moss Side'],
            'membershipPlan' => [
                'amount' => 500,
                'currency' => 'GBP',
                'frequency' => 'month',
            ],
        ], $overrides);
    }

    public function test_returns_full_name()
    {
        $result = get_member_details($this->baseData());
        $this->assertSame('Jane Smith', $result['name']);
    }

    public function test_returns_trimmed_name_when_last_name_missing()
    {
        $data = $this->baseData();
        unset($data['lastName']);
        $result = get_member_details($data);
        $this->assertSame('Jane', $result['name']);
    }

    public function test_returns_email()
    {
        $result = get_member_details($this->baseData());
        $this->assertSame('jane@example.com', $result['email']);
    }

    public function test_returns_na_when_email_missing()
    {
        $data = $this->baseData();
        unset($data['email']);
        $result = get_member_details($data);
        $this->assertSame('N/A', $result['email']);
    }

    public function test_returns_postcode()
    {
        $result = get_member_details($this->baseData());
        $this->assertSame('M14 5RQ', $result['postcode']);
    }

    public function test_returns_na_when_postcode_missing()
    {
        $data = $this->baseData();
        unset($data['addressPostcode']);
        $result = get_member_details($data);
        $this->assertSame('N/A', $result['postcode']);
    }

    public function test_uses_branch_from_data()
    {
        $result = get_member_details($this->baseData());
        $this->assertSame('Moss Side', $result['branch']);
    }

    public function test_uses_branch_from_custom_fields_fallback()
    {
        $data = $this->baseData();
        unset($data['branch']);
        $result = get_member_details($data);
        $this->assertSame('Moss Side', $result['branch']);
    }

    public function test_recalculates_branch_from_postcode_when_missing()
    {
        $data = $this->baseData([
            'branch' => null,
            'customFields' => [],
        ]);

        // Mock cache hit so get_postcode_outcode returns 'M14' without API call
        Functions\when('get_transient')->justReturn('M14');

        $result = get_member_details($data);
        $this->assertSame('Moss Side', $result['branch']);
    }

    public function test_branch_null_when_postcode_out_of_area()
    {
        $data = $this->baseData([
            'branch' => null,
            'customFields' => [],
            'addressPostcode' => 'SW1A 1AA',
        ]);

        // Outcode SW1A is not in the branch map
        Functions\when('get_transient')->justReturn('SW1A');

        $result = get_member_details($data);
        $this->assertNull($result['branch']);
    }

    public function test_formats_payment_level_with_amount_and_frequency()
    {
        $result = get_member_details($this->baseData());
        $this->assertSame('£5.00 / month', $result['payment_level']);
    }

    public function test_formats_payment_level_with_non_gbp_currency()
    {
        $data = $this->baseData([
            'membershipPlan' => ['amount' => 1000, 'currency' => 'EUR', 'frequency' => 'year'],
        ]);
        $result = get_member_details($data);
        $this->assertSame('EUR10.00 / year', $result['payment_level']);
    }

    public function test_formats_payment_level_without_frequency()
    {
        $data = $this->baseData([
            'membershipPlan' => ['amount' => 300, 'currency' => 'GBP'],
        ]);
        $result = get_member_details($data);
        $this->assertSame('£3.00', $result['payment_level']);
    }

    public function test_uses_plan_name_when_amount_is_zero()
    {
        $data = $this->baseData([
            'membershipPlan' => ['amount' => 0, 'name' => 'Free Tier'],
        ]);
        $result = get_member_details($data);
        $this->assertSame('Free Tier', $result['payment_level']);
    }

    public function test_constructs_plan_from_plan_id()
    {
        $data = $this->baseData();
        unset($data['membershipPlan']);
        $data['planId'] = 'plan_abc';
        $result = get_member_details($data);
        $this->assertSame('plan_abc', $result['payment_level']);
    }

    public function test_payment_level_na_when_no_plan_data()
    {
        $data = $this->baseData();
        unset($data['membershipPlan']);
        $result = get_member_details($data);
        $this->assertSame('N/A', $result['payment_level']);
    }
}
