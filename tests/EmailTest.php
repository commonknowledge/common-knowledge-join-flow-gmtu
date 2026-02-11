<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Brain\Monkey\Functions;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\build_email_body;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\send_notification_emails;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\send_admin_notification;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\send_branch_notification;

class EmailTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->mockLogger();
    }

    private function memberDetails(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'postcode' => 'M14 5RQ',
            'branch' => 'Moss Side',
            'payment_level' => '£5.00 / month',
        ], $overrides);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'successNotificationEmails' => ['admin@example.com'],
            'successNotificationSubject' => 'New Member',
            'successNotificationMessage' => 'A new member has joined.',
        ], $overrides);
    }

    // build_email_body tests

    public function test_build_email_body_contains_intro()
    {
        $body = build_email_body('Welcome!', $this->memberDetails());
        $this->assertStringContainsString('Welcome!', $body);
    }

    public function test_build_email_body_contains_name()
    {
        $body = build_email_body('Hi', $this->memberDetails());
        $this->assertStringContainsString('Name: Jane Smith', $body);
    }

    public function test_build_email_body_contains_email()
    {
        $body = build_email_body('Hi', $this->memberDetails());
        $this->assertStringContainsString('Email: jane@example.com', $body);
    }

    public function test_build_email_body_contains_postcode()
    {
        $body = build_email_body('Hi', $this->memberDetails());
        $this->assertStringContainsString('Postcode: M14 5RQ', $body);
    }

    public function test_build_email_body_contains_branch()
    {
        $body = build_email_body('Hi', $this->memberDetails());
        $this->assertStringContainsString('Branch: Moss Side', $body);
    }

    public function test_build_email_body_shows_no_branch_when_null()
    {
        $body = build_email_body('Hi', $this->memberDetails(['branch' => null]));
        $this->assertStringContainsString('Branch: No branch found', $body);
    }

    public function test_build_email_body_shows_no_branch_when_empty()
    {
        $body = build_email_body('Hi', $this->memberDetails(['branch' => '']));
        $this->assertStringContainsString('Branch: No branch found', $body);
    }

    public function test_build_email_body_contains_payment_level()
    {
        $body = build_email_body('Hi', $this->memberDetails());
        $this->assertStringContainsString('Payment Level: £5.00 / month', $body);
    }

    public function test_build_email_body_contains_member_details_header()
    {
        $body = build_email_body('Hi', $this->memberDetails());
        $this->assertStringContainsString('Member Details:', $body);
    }

    // send_notification_emails tests

    public function test_send_emails_to_all_recipients()
    {
        $sentTo = [];
        Functions\when('wp_mail')->alias(function ($to) use (&$sentTo) {
            $sentTo[] = $to;
            return true;
        });

        send_notification_emails(['a@b.com', 'c@d.com'], 'Subject', 'Body');
        $this->assertSame(['a@b.com', 'c@d.com'], $sentTo);
    }

    public function test_send_emails_passes_correct_arguments()
    {
        $capturedArgs = [];
        Functions\when('wp_mail')->alias(function ($to, $subject, $body) use (&$capturedArgs) {
            $capturedArgs = ['to' => $to, 'subject' => $subject, 'body' => $body];
            return true;
        });

        send_notification_emails(['a@b.com'], 'Test Subject', 'Test Body');
        $this->assertSame('a@b.com', $capturedArgs['to']);
        $this->assertSame('Test Subject', $capturedArgs['subject']);
        $this->assertSame('Test Body', $capturedArgs['body']);
    }

    public function test_send_emails_logs_warning_on_failure()
    {
        $warningLogged = false;
        $logger = $this->mockLogger();
        $logger->shouldReceive('warning')->andReturnUsing(function ($msg) use (&$warningLogged) {
            if (str_contains($msg, 'Failed to send')) {
                $warningLogged = true;
            }
        });

        Functions\when('wp_mail')->justReturn(false);
        send_notification_emails(['fail@b.com'], 'Subject', 'Body');

        $this->assertTrue($warningLogged);
    }

    public function test_send_emails_handles_empty_recipients()
    {
        $mailCalled = false;
        Functions\when('wp_mail')->alias(function () use (&$mailCalled) {
            $mailCalled = true;
            return true;
        });

        send_notification_emails([], 'Subject', 'Body');
        $this->assertFalse($mailCalled);
    }

    // send_admin_notification tests

    public function test_admin_notification_sends_email()
    {
        $sentTo = null;
        Functions\when('wp_mail')->alias(function ($to) use (&$sentTo) {
            $sentTo = $to;
            return true;
        });

        send_admin_notification($this->memberDetails(), $this->config());
        $this->assertSame('admin@example.com', $sentTo);
    }

    public function test_admin_notification_skips_when_no_emails()
    {
        $mailCalled = false;
        Functions\when('wp_mail')->alias(function () use (&$mailCalled) {
            $mailCalled = true;
            return true;
        });

        send_admin_notification(
            $this->memberDetails(),
            $this->config(['successNotificationEmails' => []])
        );
        $this->assertFalse($mailCalled);
    }

    // send_branch_notification tests

    public function test_branch_notification_sends_to_branch_email()
    {
        $sentTo = null;
        Functions\when('wp_mail')->alias(function ($to) use (&$sentTo) {
            $sentTo = $to;
            return true;
        });

        send_branch_notification($this->memberDetails(), $this->config());
        $this->assertSame('moss-side@tenantsunion.org.uk', $sentTo);
    }

    public function test_branch_notification_notifies_admin_when_no_branch()
    {
        $sentSubject = null;
        Functions\when('wp_mail')->alias(function ($to, $subject) use (&$sentSubject) {
            $sentSubject = $subject;
            return true;
        });

        send_branch_notification(
            $this->memberDetails(['branch' => null]),
            $this->config()
        );
        $this->assertSame('GMTU Member Registration - No Branch Assigned', $sentSubject);
    }

    public function test_branch_notification_does_nothing_when_no_branch_and_no_admin()
    {
        $mailCalled = false;
        Functions\when('wp_mail')->alias(function () use (&$mailCalled) {
            $mailCalled = true;
            return true;
        });

        send_branch_notification(
            $this->memberDetails(['branch' => null]),
            $this->config(['successNotificationEmails' => []])
        );
        $this->assertFalse($mailCalled);
    }

    public function test_branch_notification_notifies_admin_when_branch_has_no_email()
    {
        $sentSubject = null;
        Functions\when('wp_mail')->alias(function ($to, $subject) use (&$sentSubject) {
            $sentSubject = $subject;
            return true;
        });

        send_branch_notification(
            $this->memberDetails(['branch' => 'Stockport']),
            $this->config()
        );
        $this->assertStringContainsString('No Email for Stockport', $sentSubject);
    }

    public function test_branch_notification_logs_warning_on_mail_failure()
    {
        $warningLogged = false;
        $logger = $this->mockLogger();
        $logger->shouldReceive('warning')->andReturnUsing(function ($msg) use (&$warningLogged) {
            if (str_contains($msg, 'Failed to send branch notification')) {
                $warningLogged = true;
            }
        });

        Functions\when('wp_mail')->justReturn(false);
        send_branch_notification(
            $this->memberDetails(['branch' => 'Hulme']),
            $this->config()
        );

        $this->assertTrue($warningLogged);
    }
}
