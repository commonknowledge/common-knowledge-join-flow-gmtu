<?php
/**
 * Plugin Name:     Common Knowledge Join Flow GMTU Extensions
 * Description:     Common Knowledge join flow plugin GMTU extensions.
 * Version:         1.5.1
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     common-knowledge-join-flow
 * License: GPLv2 or later
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

/*
 * Hook lifecycle / data flow
 * ==========================
 *
 * The parent CK Join Flow plugin fires hooks at each stage of member registration.
 * This plugin hooks into the following, in order:
 *
 * 1. ck_join_flow_postcode_validation (filter, PostcodeValidation.php)
 *    - Fired when postcode is entered/looked up on the form.
 *    - Receives: $response, $postcode, $addresses, $request
 *    - We check the outcode against the branch map. If out of area, return error.
 *
 * 2. ck_join_flow_step_response (filter, PostcodeValidation.php)
 *    - Fired on form step submission.
 *    - Receives: $response, $data
 *    - Second line of defence: blocks submission if postcode is out of area.
 *
 * 3. ck_join_flow_pre_handle_join (filter, BranchAssignment.php)
 *    - Fired before the join is processed.
 *    - Receives: $data (member registration data)
 *    - We look up the postcode outcode, find the branch, and inject it into
 *      $data["branch"] and $data["customFields"]["branch"].
 *
 * 4. ck_join_flow_add_tags (filter, Tagging.php)
 *    - Fired when tagging a member in external services (Mailchimp, Zetkin, etc.)
 *    - Receives: $addTags, $data, $service
 *    - We append the branch name to the tags array.
 *
 * 5. ck_join_flow_success (action, Notifications.php)
 *    - Fired after successful registration.
 *    - Receives: $data
 *    - Priority 10: sends admin notification email.
 *    - Priority 20: sends branch-specific notification email.
 */

// Load required files
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Postcode.php';
require_once __DIR__ . '/src/Branch.php';
require_once __DIR__ . '/src/Member.php';
require_once __DIR__ . '/src/Email.php';
require_once __DIR__ . '/src/PostcodeValidation.php';
require_once __DIR__ . '/src/BranchAssignment.php';
require_once __DIR__ . '/src/Tagging.php';
require_once __DIR__ . '/src/Notifications.php';

// Configuration
$config = [
// Error messages for out-of-area postcodes
    'outOfAreaLookupMessage' => "<p>Sorry, this postcode is outside of Greater Manchester, which the union covers. You won't be able to join the union.</p>",
    'outOfAreaSubmissionMessage' => '<h3>Sorry</h3><p>We're only able to support tenants living in the Greater Manchester area.</p><p>If you're based elsewhere in the UK, there are other local tenant unions that may be able to help, and for urgent housing issues you can also contact <a href="https://www.shelter.org.uk/Shelter">Shelter</a> or <a href="https://www.crisis.org.uk/">Crisis</a>.</p><p>We're sorry we can't assist directly, but we hope you can get the support you need quickly.</p>',

// Success notification settings
    'successNotificationEmails' => ['alex@commonknowledge.coop', 'membership@tenantsunion.org.uk'],
    'successNotificationSubject' => 'New GMTU Member Registration',
    'successNotificationMessage' => 'A new member has successfully registered through the join flow.',
];

// Register all functionality
register_postcode_validation($config);
register_branch_assignment();
register_tagging();
register_notifications($config);
