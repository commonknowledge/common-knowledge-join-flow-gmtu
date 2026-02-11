<?php
/**
 * Email notification functionality.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Build email body with member details.
 *
 * @since 0.1.0
 *
 * @param string $introMessage  Introductory message.
 * @param array  $memberDetails Member details array.
 * @return string Formatted email body.
 */
function build_email_body($introMessage, $memberDetails) {
    $emailBody = $introMessage . "\n\n";
    $emailBody .= "Member Details:\n";
    $emailBody .= "Name: " . $memberDetails['name'] . "\n";
    $emailBody .= "Email: " . $memberDetails['email'] . "\n";
    $emailBody .= "Postcode: " . $memberDetails['postcode'] . "\n";
    $emailBody .= "Branch: " . ($memberDetails['branch'] ?: 'No branch found') . "\n";
    $emailBody .= "Payment Level: " . $memberDetails['payment_level'] . "\n";
    
    return $emailBody;
}

/**
 * Send notification emails to multiple recipients.
 *
 * @since 0.1.0
 *
 * @param array  $recipients Email addresses to send to.
 * @param string $subject    Email subject.
 * @param string $body       Email body.
 * @return void
 */
function send_notification_emails($recipients, $subject, $body) {
    foreach ($recipients as $recipient) {
        $sent = wp_mail($recipient, $subject, $body);
        if (!$sent) {
            log_warning("Failed to send notification email to: $recipient (subject: $subject)");
        }
    }
}

/**
 * Send general admin notification on successful registration.
 *
 * @since 1.2.0
 *
 * @param array $memberDetails Member details array.
 * @param array $config        Configuration array with email settings.
 * @return void
 */
function send_admin_notification($memberDetails, $config) {
    if (empty($config['successNotificationEmails'])) {
        return;
    }
    
    $emailBody = build_email_body($config['successNotificationMessage'], $memberDetails);
    send_notification_emails($config['successNotificationEmails'], $config['successNotificationSubject'], $emailBody);
}

/**
 * Send branch-specific notification on successful registration.
 *
 * @since 1.2.0
 *
 * @param array $memberDetails Member details array.
 * @param array $config        Configuration array with email settings.
 * @return void
 */
function send_branch_notification($memberDetails, $config) {
    $branchEmailMap = get_branch_email_map();
    $memberBranch = $memberDetails['branch'];
    
    // If no branch assigned, nothing to do — admin already notified at priority 10
    if (empty($memberBranch)) {
        log_info("No branch assigned, skipping branch notification (admin already notified)");
        return;
    }
    
    // Check if branch has an email configured
    $branchEmail = $branchEmailMap[$memberBranch] ?? null;
    
    if (empty($branchEmail)) {
        // No email configured for this branch, notify admin
        if (!empty($config['successNotificationEmails'])) {
            $intro = "A new member has joined the {$memberBranch} branch, but no email is configured for this branch.\n\nPlease configure a branch email or contact the branch directly.";
            $emailBody = build_email_body($intro, $memberDetails);
            send_notification_emails($config['successNotificationEmails'], "GMTU Member Registration - No Email for {$memberBranch}", $emailBody);
        }
        return;
    }
    
    // Send notification to branch
    $intro = "A new member has joined your branch!";
    $emailBody = build_email_body($intro, $memberDetails);
    $sent = wp_mail($branchEmail, "New Member Joined {$memberBranch} Branch", $emailBody);
    if (!$sent) {
        log_warning("Failed to send branch notification to: $branchEmail for branch $memberBranch");
    }
}

