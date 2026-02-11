<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_notifications;

class NotificationsTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    private function config(): array
    {
        return [
            'successNotificationEmails' => ['admin@example.com'],
            'successNotificationSubject' => 'New Member',
            'successNotificationMessage' => 'A new member has joined.',
        ];
    }

    private function registrationData(): array
    {
        return [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane@example.com',
            'addressPostcode' => 'M15 5AA',
            'branch' => 'Hulme',
            'customFields' => ['branch' => 'Hulme'],
            'membershipPlan' => [
                'amount' => 500,
                'currency' => 'GBP',
                'frequency' => 'month',
            ],
        ];
    }

    /**
     * Capture both notification closures keyed by priority.
     */
    private function captureCallbacks(): array
    {
        $callbacks = [];
        Functions\expect('add_action')
            ->times(2)
            ->andReturnUsing(function ($tag, $cb, $priority) use (&$callbacks) {
                $callbacks["$tag:$priority"] = $cb;
                return true;
            });

        register_notifications($this->config());
        return $callbacks;
    }

    public function test_registers_admin_notification_at_priority_10()
    {
        $callbacks = $this->captureCallbacks();
        $this->assertArrayHasKey('ck_join_flow_success:10', $callbacks);
    }

    public function test_registers_branch_notification_at_priority_20()
    {
        $callbacks = $this->captureCallbacks();
        $this->assertArrayHasKey('ck_join_flow_success:20', $callbacks);
    }

    public function test_admin_notification_sends_email()
    {
        $callbacks = $this->captureCallbacks();
        $adminHandler = $callbacks['ck_join_flow_success:10'];

        $sentTo = null;
        Functions\when('wp_mail')->alias(function ($to) use (&$sentTo) {
            $sentTo = $to;
            return true;
        });

        $adminHandler($this->registrationData());
        $this->assertSame('admin@example.com', $sentTo);
    }

    public function test_branch_notification_sends_to_branch_email()
    {
        $callbacks = $this->captureCallbacks();
        $branchHandler = $callbacks['ck_join_flow_success:20'];

        $sentTo = null;
        Functions\when('wp_mail')->alias(function ($to) use (&$sentTo) {
            $sentTo = $to;
            return true;
        });

        $branchHandler($this->registrationData());
        $this->assertSame('hulme@tenantsunion.org.uk', $sentTo);
    }
}
