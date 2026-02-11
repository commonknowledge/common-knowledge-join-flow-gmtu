<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_tagging;

class TaggingTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    private function registerTaggingAndCaptureHandler(): callable
    {
        $callback = null;
        Functions\expect('add_filter')
            ->once()
            ->andReturnUsing(function ($tag, $cb) use (&$callback) {
                $callback = $cb;
                return true;
            });

        register_tagging();
        return $callback;
    }

    public function test_registers_add_tags_filter()
    {
        $callbacks = [];
        Functions\expect('add_filter')
            ->once()
            ->with('ck_join_flow_add_tags', \Mockery::type('callable'), 10, 3)
            ->andReturnUsing(function ($tag, $cb) use (&$callbacks) {
                $callbacks[$tag] = $cb;
                return true;
            });

        register_tagging();
        $this->assertArrayHasKey('ck_join_flow_add_tags', $callbacks);
    }

    public function test_appends_branch_to_tags()
    {
        $handler = $this->registerTaggingAndCaptureHandler();

        $data = ['branch' => 'Hulme', 'email' => 'j@e.com', 'customFields' => ['branch' => 'Hulme']];
        $result = $handler(['existing-tag'], $data, 'mailchimp');
        $this->assertSame(['existing-tag', 'Hulme'], $result);
    }

    public function test_does_not_add_tag_when_branch_empty()
    {
        $handler = $this->registerTaggingAndCaptureHandler();

        $data = ['email' => 'j@e.com', 'customFields' => []];
        $result = $handler(['existing'], $data, 'mailchimp');
        $this->assertSame(['existing'], $result);
    }

    public function test_does_not_add_tag_when_branch_null()
    {
        $handler = $this->registerTaggingAndCaptureHandler();

        $data = ['branch' => null, 'email' => 'j@e.com', 'customFields' => ['branch' => null]];
        $result = $handler([], $data, 'zetkin');
        $this->assertSame([], $result);
    }

    public function test_logs_warning_when_no_branch()
    {
        $warningLogged = false;
        $logger = $this->mockLogger();
        $logger->shouldReceive('warning')->andReturnUsing(function ($msg) use (&$warningLogged) {
            if (str_contains($msg, 'No branch found')) {
                $warningLogged = true;
            }
        });

        $handler = $this->registerTaggingAndCaptureHandler();

        $data = ['email' => 'test@e.com', 'customFields' => []];
        $handler([], $data, 'mailchimp');
        $this->assertTrue($warningLogged);
    }

    public function test_logs_info_when_branch_added()
    {
        $infoLogged = false;
        $logger = $this->mockLogger();
        $logger->shouldReceive('info')->andReturnUsing(function ($msg) use (&$infoLogged) {
            if (str_contains($msg, "Added branch tag 'Hulme'")) {
                $infoLogged = true;
            }
        });

        $handler = $this->registerTaggingAndCaptureHandler();

        $data = ['branch' => 'Hulme', 'email' => 'j@e.com', 'customFields' => ['branch' => 'Hulme']];
        $handler([], $data, 'mailchimp');
        $this->assertTrue($infoLogged);
    }

    public function test_works_with_different_services()
    {
        $handler = $this->registerTaggingAndCaptureHandler();

        $data = ['branch' => 'Rochdale', 'email' => 'j@e.com', 'customFields' => ['branch' => 'Rochdale']];
        $result = $handler([], $data, 'zetkin');
        $this->assertSame(['Rochdale'], $result);
    }
}
