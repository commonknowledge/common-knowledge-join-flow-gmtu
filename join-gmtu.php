<?php
/**
 * Plugin Name:     Common Knowledge Join Flow GMTU Extensions
 * Description:     Common Knowledge join flow plugin GMTU extensions.
 * Version:         1.5.0
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     common-knowledge-join-flow
 * License: GPLv2 or later
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

if (! defined('ABSPATH')) exit; // Exit if accessed directly

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

use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_postcode_validation;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_branch_assignment;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_tagging;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\register_notifications;

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
