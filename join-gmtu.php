<?php
/**
 * Plugin Name:     Common Knowledge Join Flow GMTU Extensions
 * Description:     Common Knowledge join flow plugin GMTU extensions.
 * Version:         1.5.12
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
 *      $data["branch"] only. Never customFields: Zetkin rejects branch as a
 *      person field and the whole signup fails. See README, "Zetkin: tagging
 *      only, never a custom field".
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
require_once __DIR__ . '/src/Retag.php';

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

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Bring existing members' branch tags into line with the branch map.
     *
     * New signups are tagged from src/Branch.php as they join, but nothing
     * revisits existing members when a branch is renamed, split or added. This
     * recalculates every member's branch from their postcode and fixes the
     * difference.
     *
     * Previews by default: it writes nothing at all unless --apply is passed.
     * Only branch tags are ever touched, and an old branch tag is only removed
     * once the correct one has been applied.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Actually write the changes. Without it, the command only reports.
     *
     * [--limit=<number>]
     * : Stop after this many members. Worth doing a small pass first.
     *
     * ## EXAMPLES
     *
     *     wp gmtu retag_branches
     *     wp gmtu retag_branches --limit=10
     *     wp gmtu retag_branches --limit=10 --apply
     */
    \WP_CLI::add_command('gmtu retag_branches', function ($args, $assocArgs) {
        $apply = !empty($assocArgs['apply']);
        $limit = isset($assocArgs['limit']) ? (int) $assocArgs['limit'] : null;

        if (!$apply) {
            \WP_CLI::log('PREVIEW. Nothing will be written. Re-run with --apply to make these changes.');
        }

        $result = run_branch_retag($apply, $limit);

        $rows = [];
        foreach ($result['actions'] as $action) {
            if ($action['status'] === 'unchanged') {
                continue;
            }
            $rows[] = [
                'email' => $action['email'],
                'postcode' => $action['postcode'],
                'outcode' => $action['outcode'] ?? '',
                'status' => $action['status'],
                'add' => $action['addTag'] ?? '',
                'remove' => implode(', ', $action['removeTags']),
                'reason' => $action['reason'],
            ];
        }

        if (empty($rows)) {
            \WP_CLI::success('Every member is already on the right branch.');
            return;
        }

        \WP_CLI\Utils\format_items(
            'table',
            $rows,
            ['email', 'postcode', 'outcode', 'status', 'add', 'remove', 'reason']
        );

        $counts = $result['counts'];
        \WP_CLI::log('');
        \WP_CLI::log("Moved to a different branch: {$counts['move']}");
        \WP_CLI::log("Given a branch they did not have: {$counts['add']}");
        \WP_CLI::log("Stale branch tag taken off: {$counts['remove']}");
        \WP_CLI::log("Already correct: {$counts['unchanged']}");
        \WP_CLI::log("Needs a decision from GMTU: {$counts['review']}");
        \WP_CLI::log("Could not be classified: {$counts['skipped']}");

        if ($counts['failed'] > 0) {
            \WP_CLI::warning("Failed part way through: {$counts['failed']}. See the log for details.");
        }

        if ($apply) {
            \WP_CLI::success('Branch tags updated.');
        } else {
            \WP_CLI::log('');
            \WP_CLI::log('Nothing was written. Re-run with --apply to make these changes.');
        }
    });
}
