<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_branch_assignment;

class BranchAssignmentTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    /**
     * Register branch assignment and capture the closure.
     */
    private function registerBranchAssignmentAndCaptureHandler(): callable
    {
        $callback = null;
        Functions\expect('add_filter')
            ->once()
            ->andReturnUsing(function ($tag, $cb) use (&$callback) {
                $callback = $cb;
                return true;
            });

        register_branch_assignment();
        return $callback;
    }

    public function test_registers_pre_handle_join_filter()
    {
        $callbacks = [];
        Functions\expect('add_filter')
            ->once()
            ->with('ck_join_flow_pre_handle_join', \Mockery::type('callable'))
            ->andReturnUsing(function ($tag, $cb) use (&$callbacks) {
                $callbacks[$tag] = $cb;
                return true;
            });

        register_branch_assignment();
        $this->assertArrayHasKey('ck_join_flow_pre_handle_join', $callbacks);
    }

    public function test_does_not_overwrite_existing_branch()
    {
        $handler = $this->registerBranchAssignmentAndCaptureHandler();

        $data = ['branch' => 'Hulme', 'addressPostcode' => 'M14 5RQ'];
        $result = $handler($data);
        $this->assertSame('Hulme', $result['branch']);
    }

    public function test_assigns_branch_from_postcode()
    {
        $handler = $this->registerBranchAssignmentAndCaptureHandler();

        Functions\when('get_transient')->justReturn('M14');

        $data = ['addressPostcode' => 'M14 5RQ'];
        $result = $handler($data);
        $this->assertSame('Moss Side', $result['branch']);
    }

    public function test_sets_null_branch_for_null_mapping()
    {
        $handler = $this->registerBranchAssignmentAndCaptureHandler();

        Functions\when('get_transient')->justReturn('M5');

        $data = ['addressPostcode' => 'M5 3AA'];
        $result = $handler($data);
        $this->assertNull($result['branch']);
    }

    public function test_assigns_null_branch_for_known_no_branch_postcode()
    {
        $handler = $this->registerBranchAssignmentAndCaptureHandler();

        // BL8 is in the branch map but deliberately has no branch
        Functions\when('get_transient')->justReturn('BL8');

        $data = ['addressPostcode' => 'BL8 1AA'];
        $result = $handler($data);
        $this->assertNull($result['branch']);
    }

    public function test_returns_data_unchanged_when_no_postcode()
    {
        $handler = $this->registerBranchAssignmentAndCaptureHandler();

        $data = ['firstName' => 'Jane'];
        $result = $handler($data);
        $this->assertSame($data, $result);
    }

    /*
     * Regression tests for the Zetkin "invalid parameter" bug (fixed in PR #9).
     *
     * Prior versions of this filter injected `branch` into both
     * `customFieldsConfig` and `customFields`. The core join-block plugin
     * forwards `customFields` as direct fields on Zetkin's People API call —
     * Zetkin rejects `branch` as an unrecognised person field and the entire
     * signup request fails. Because the Zetkin call failed, the downstream
     * `ck_join_flow_add_tags` filter (which correctly adds branch as a tag)
     * never ran, so new members ended up with no branch assigned at all.
     *
     * The two tests below pin the fix: branch information must flow to Zetkin
     * only via the tag filter, never as a custom person field. If someone
     * reintroduces a `customFields['branch']` or `customFieldsConfig` entry
     * for branch, these tests will fail loudly.
     */
    public function test_does_not_add_branch_to_custom_fields_config()
    {
        $handler = $this->registerBranchAssignmentAndCaptureHandler();

        Functions\when('get_transient')->justReturn('M14');

        $data = ['addressPostcode' => 'M14 5RQ'];
        $result = $handler($data);

        $branchField = array_filter($result['customFieldsConfig'] ?? [], function ($f) {
            return $f['id'] === 'branch';
        });
        $this->assertCount(0, $branchField, 'branch must not be sent as a custom field — Zetkin rejects it as an invalid person field');
    }

    public function test_does_not_set_branch_in_custom_fields()
    {
        $handler = $this->registerBranchAssignmentAndCaptureHandler();

        Functions\when('get_transient')->justReturn('M14');

        $data = ['addressPostcode' => 'M14 5RQ'];
        $result = $handler($data);

        $this->assertArrayNotHasKey('branch', $result['customFields'] ?? [], 'branch must not be sent as a custom field — Zetkin rejects it as an invalid person field');
    }
}
