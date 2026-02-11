<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_postcode_outcode;

class PostcodeTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    /**
     * Stub the full postcode API chain for a cache miss.
     */
    private function stubApiResponse(string $jsonBody): void
    {
        $fakeResponse = ['body' => $jsonBody];

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn($fakeResponse);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($jsonBody);
        Functions\when('set_transient')->justReturn(true);
    }

    public function test_returns_null_for_empty_postcode()
    {
        $this->assertNull(get_postcode_outcode(''));
    }

    public function test_returns_null_for_null_postcode()
    {
        $this->assertNull(get_postcode_outcode(null));
    }

    public function test_returns_cached_outcode_on_transient_hit()
    {
        Functions\when('get_transient')->justReturn('M1');

        $this->assertSame('M1', get_postcode_outcode('M1 1AA'));
    }

    public function test_normalizes_postcode_for_cache_key()
    {
        // Whitespace/case differences should resolve to same cache key
        Functions\when('get_transient')->justReturn('M1');

        $this->assertSame('M1', get_postcode_outcode('  m1 1aa  '));
    }

    public function test_fetches_from_api_on_cache_miss()
    {
        $this->stubApiResponse('{"result":{"outcode":"M14"}}');

        $this->assertSame('M14', get_postcode_outcode('M14 5RQ'));
    }

    public function test_caches_result_in_transient()
    {
        $cachedKey = null;
        $cachedValue = null;
        $cachedExpiry = null;

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"result":{"outcode":"M14"}}');
        Functions\when('set_transient')->alias(function ($key, $value, $expiry) use (&$cachedKey, &$cachedValue, &$cachedExpiry) {
            $cachedKey = $key;
            $cachedValue = $value;
            $cachedExpiry = $expiry;
            return true;
        });

        get_postcode_outcode('M14 5RQ');

        $this->assertSame('M14', $cachedValue);
        $this->assertSame(7 * DAY_IN_SECONDS, $cachedExpiry);
        $this->assertStringContainsString('gmtu_postcode_outcode_', $cachedKey);
    }

    public function test_returns_null_on_wp_error()
    {
        $wpError = \Mockery::mock();
        $wpError->shouldReceive('get_error_message')->andReturn('Connection timed out');

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn($wpError);
        Functions\when('is_wp_error')->justReturn(true);

        $this->assertNull(get_postcode_outcode('M1 1AA'));
    }

    public function test_returns_null_on_non_200_status()
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);

        $this->assertNull(get_postcode_outcode('INVALID'));
    }

    public function test_returns_null_when_response_missing_outcode()
    {
        $this->stubApiResponse('{"result":{}}');

        $this->assertNull(get_postcode_outcode('M1 1AA'));
    }

    public function test_trims_outcode_from_api_response()
    {
        $this->stubApiResponse('{"result":{"outcode":" M14 "}}');

        $this->assertSame('M14', get_postcode_outcode('M14 5RQ'));
    }

    public function test_sends_normalized_postcode_to_api()
    {
        $requestedUrl = null;

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->alias(function ($url) use (&$requestedUrl) {
            $requestedUrl = $url;
            return [];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"result":{"outcode":"M14"}}');
        Functions\when('set_transient')->justReturn(true);

        get_postcode_outcode('  m14 5rq  ');

        $this->assertSame('https://api.postcodes.io/postcodes/M145RQ', $requestedUrl);
    }

    public function test_does_not_cache_when_outcode_missing()
    {
        $setCalled = false;

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"result":{}}');
        Functions\when('set_transient')->alias(function () use (&$setCalled) {
            $setCalled = true;
        });

        get_postcode_outcode('M1 1AA');

        $this->assertFalse($setCalled);
    }
}
