<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_postcode_validation;

class PostcodeValidationTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    private function config(): array
    {
        return [
            'outOfAreaLookupMessage' => 'Out of area (lookup)',
            'outOfAreaSubmissionMessage' => 'Out of area (submission)',
        ];
    }

    /**
     * Register validation and capture the closures.
     *
     * @return array Keyed by "hook:priority"
     */
    private function registerValidationAndCaptureHandlers(): array
    {
        $callbacks = [];
        Functions\expect('add_filter')
            ->times(2)
            ->andReturnUsing(function ($tag, $callback, $priority) use (&$callbacks) {
                $callbacks["$tag:$priority"] = $callback;
                return true;
            });

        register_postcode_validation($this->config());
        return $callbacks;
    }

    public function test_registers_postcode_validation_filter()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $this->assertArrayHasKey('ck_join_flow_postcode_validation:10', $callbacks);
    }

    public function test_registers_step_response_filter()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $this->assertArrayHasKey('ck_join_flow_step_response:10', $callbacks);
    }

    public function test_validation_allows_valid_postcode()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $validator = $callbacks['ck_join_flow_postcode_validation:10'];

        Functions\when('get_transient')->justReturn('M14');

        $response = ['status' => 'ok'];
        $result = $validator($response, 'M14 5RQ', [], null);
        $this->assertSame($response, $result);
    }

    public function test_validation_blocks_out_of_area_postcode()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $validator = $callbacks['ck_join_flow_postcode_validation:10'];

        Functions\when('get_transient')->justReturn('SW1A');

        $result = $validator(['status' => 'ok'], 'SW1A 1AA', [], null);
        $this->assertSame('bad_postcode', $result['status']);
        $this->assertSame('Out of area (lookup)', $result['message']);
    }

    public function test_validation_allows_through_when_outcode_null()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $validator = $callbacks['ck_join_flow_postcode_validation:10'];

        // Stub WP functions so get_postcode_outcode returns null (non-200 status)
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);

        $response = ['status' => 'ok'];
        $result = $validator($response, 'INVALID', [], null);
        $this->assertSame($response, $result);
    }

    public function test_step_response_allows_valid_postcode()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $stepFilter = $callbacks['ck_join_flow_step_response:10'];

        Functions\when('get_transient')->justReturn('M1');

        $response = ['status' => 'ok'];
        $result = $stepFilter($response, ['addressPostcode' => 'M1 1AA']);
        $this->assertSame($response, $result);
    }

    public function test_step_response_blocks_out_of_area_postcode()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $stepFilter = $callbacks['ck_join_flow_step_response:10'];

        Functions\when('get_transient')->justReturn('EC1A');

        $result = $stepFilter(['status' => 'ok'], ['addressPostcode' => 'EC1A 1BB']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('Out of area (submission)', $result['message']);
    }

    public function test_validation_allows_known_postcode_with_no_branch()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $validator = $callbacks['ck_join_flow_postcode_validation:10'];

        // BL8 is in the branch map but maps to null (no branch)
        Functions\when('get_transient')->justReturn('BL8');

        $response = ['status' => 'ok'];
        $result = $validator($response, 'BL8 1AA', [], null);
        $this->assertSame($response, $result);
    }

    public function test_step_response_allows_known_postcode_with_no_branch()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $stepFilter = $callbacks['ck_join_flow_step_response:10'];

        // WA14 is in the branch map but maps to null (no branch)
        Functions\when('get_transient')->justReturn('WA14');

        $response = ['status' => 'ok'];
        $result = $stepFilter($response, ['addressPostcode' => 'WA14 1AA']);
        $this->assertSame($response, $result);
    }

    public function test_step_response_allows_through_when_postcode_empty()
    {
        $callbacks = $this->registerValidationAndCaptureHandlers();
        $stepFilter = $callbacks['ck_join_flow_step_response:10'];

        // get_postcode_outcode returns null for empty string, no WP functions called
        $response = ['status' => 'ok'];
        $result = $stepFilter($response, ['addressPostcode' => '']);
        $this->assertSame($response, $result);
    }
}
