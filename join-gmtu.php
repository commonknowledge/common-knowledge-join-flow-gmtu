<?php
/**
 * Plugin Name:     Common Knowledge Join Flow GMTU Extensions
 * Description:     Common Knowledge join flow plugin GMTU extensions.
 * Version:         1.5.7
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
 * 5. ck_join_flow_success (action, LapsingOverride.php, priority 5)
 *    - Fired after successful registration.
 *    - Receives: $data
 *    - Clears the sticky-lapsed flag so a rejoining member regains Good standing.
 *
 * 6. ck_join_flow_success (action, Notifications.php, priority 10)
 *    - Sends admin notification email.
 *
 * 7. ck_join_flow_success (action, Notifications.php, priority 20)
 *    - Sends branch-specific notification email.
 *
 * 8. ck_join_flow_should_lapse_member (filter, LapsingOverride.php)
 *    - Fired when Stripe signals a member should be lapsed.
 *    - Receives: $should_lapse (bool), $email, $context
 *    - Returns true only when GMTU standing is Lapsed (7+ missed months).
 *    - Suppresses lapse for Good / Early Arrears / Lapsing standing.
 *
 * 9. ck_join_flow_should_unlapse_member (filter, LapsingOverride.php)
 *    - Fired when Stripe signals a member should be unlapsed.
 *    - Receives: $should_unlapse (bool), $email, $context
 *    - Returns true only when standing is Good and sticky-lapsed flag is not set.
 *    - Suppresses unlapse for sticky-lapsed members (must rejoin explicitly).
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
require_once __DIR__ . '/src/MembershipStanding.php';
require_once __DIR__ . '/src/LapsedStore.php';
require_once __DIR__ . '/src/StripePaymentHistory.php';
require_once __DIR__ . '/src/LapsingOverride.php';

// Configuration
$config = [
// Error messages for out-of-area postcodes
    'outOfAreaLookupMessage' => '<p>Membership is only available for people living within the Greater Manchester area. You can still support the union by becoming <a href="https://tenantsunion.org.uk/donate/">a regular donor or by making a one-off donation.</a></p>',
    'outOfAreaSubmissionMessage' => '<h3>Sorry</h3><p>Membership is only available for people living within the Greater Manchester area.</p><p>You can still support the union by becoming <a href="https://tenantsunion.org.uk/donate/">a regular donor or by making a one-off donation</a>.</p><p>If you\'re based elsewhere in the UK, there are other local tenant unions that may be able to help, and for urgent housing issues you can also contact <a href="https://www.shelter.org.uk/Shelter">Shelter</a> or <a href="https://www.crisis.org.uk/">Crisis</a>.</p>',

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
register_lapsing_override();
