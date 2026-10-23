<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_income_guidance;

class IncomeGuidanceTest extends TestCase
{
    public function test_switches_on_the_parent_plugins_income_guidance()
    {
        $callback = null;
        Functions\expect('add_filter')
            ->once()
            ->with('ck_join_flow_income_guidance_enabled', \Mockery::type('callable'))
            ->andReturnUsing(function ($tag, $cb) use (&$callback) {
                $callback = $cb;
                return true;
            });

        register_income_guidance();

        $this->assertTrue($callback(false));
    }
}
