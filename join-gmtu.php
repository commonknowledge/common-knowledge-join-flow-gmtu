<?php

/**
 * Plugin Name:     Common Knowledge Join Flow GMTU Extensions
 * Description:     Common Knowledge join flow plugin GMTU extensions.
 * Version:         1.1.0
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     common-knowledge-join-flow
 * License: GPLv2 or later
 */

if (! defined('ABSPATH')) exit; // Exit if accessed directly

// Error messages for out-of-area postcodes
$outOfAreaLookupMessage = "<p>Sorry, this postcode is outside of Greater Manchester, which the union covers. You won't be able to join the union.</p>";
$outOfAreaSubmissionMessage = '<h3>Sorry</h3><p>We’re only able to support tenants living in the Greater Manchester area.</p><p>If you’re based elsewhere in the UK, there are other local tenant unions that may be able to help, and for urgent housing issues you can also contact <a href="https://www.shelter.org.uk/Shelter">Shelter</a> or <a href="https://www.crisis.org.uk/">Crisis</a>.</p><p>We’re sorry we can’t assist directly, but we hope you can get the support you need quickly.</p>';

// Success notification settings
$successNotificationEmails = ['alex@commonknowledge.coop', 'membership@tenantsunion.org.uk'];
$successNotificationSubject = 'New GMTU Member Registration';
$successNotificationMessage = 'A new member has successfully registered through the join flow.';


// Branch email addresses for notifications
$branchEmailMap = [
    "South Manchester" => 'south.mcr@tenantsunion.org.uk',
    "Harpurhey" => 'harpurhey@tenantsunion.org.uk',
    "Leve-Longsight" => 'levenshulme-longsight@tenantsunion.org.uk',
    "Moss Side" => 'moss-side@tenantsunion.org.uk',
    "Hulme" => 'hulme@tenantsunion.org.uk',
    "Middleton" => 'middleton@tenantsunion.org.uk',
    "Rochdale" => 'rochdale@tenantsunion.org.uk',
    "Stockport" => null,
];

$branchMap = [
    "M1" => "South Manchester",
    "M2" => "South Manchester",
    "M3" => "South Manchester",
    "M4" => "South Manchester",
    "M5" => null,
    "M6" => null,
    "M7" => null,
    "M8" => "Harpurhey",
    "M9" => "Harpurhey",
    "M11" => "Harpurhey",
    "M12" => "Leve-Longsight",
    "M13" => "Leve-Longsight",
    "M14" => "Moss Side",
    "M15" => "Hulme",
    "M16" => "Moss Side",
    "M17" => null,
    "M18" => "Leve-Longsight",
    "M19" => "Leve-Longsight",
    "M20" => "South Manchester",
    "M21" => "South Manchester",
    "M22" => "South Manchester",
    "M23" => "South Manchester",
    "M24" => "Middleton",
    "M25" => null,
    "M26" => null,
    "M27" => null,
    "M28" => null,
    "M29" => null,
    "M30" => null,
    "M31" => null,
    "M32" => null,
    "M33" => null,
    "M34" => null,
    "M35" => null,
    "M38" => null,
    "M40" => "Harpurhey",
    "M41" => null,
    "M43" => null,
    "M44" => null,
    "M45" => null,
    "M46" => null,
    "M50" => "South Manchester",
    "OL1" => null,
    "OL2" => null,
    "OL3" => null,
    "OL4" => null,
    "OL5" => null,
    "OL6" => null,
    "OL7" => null,
    "OL8" => null,
    "OL9" => null,
    "OL10" => null,
    "OL11" => "Rochdale",
    "OL12" => "Rochdale",
    "OL13" => null,
    "OL14" => null,
    "OL15" => null,
    "OL16" => "Rochdale",
    "SK1" => "Stockport",
    "SK2" => "Stockport",
    "SK3" => "Stockport",
    "SK4" => "Stockport",
    "SK5" => "Stockport",
    "SK6" => "Stockport",
    "SK7" => "Stockport",
    "SK8" => "Stockport",
    "SK9" => null,
    "SK10" => null,
    "SK11" => null,
    "SK12" => null,
    "SK13" => null,
    "SK14" => null,
    "SK15" => null,
    "SK16" => null,
    "SK17" => null,
    "SK22" => null,
    "SK23" => null,
];

/**
 * Get the join block log instance if available.
 *
 * @since 1.1.0
 *
 * @return object|null The log instance or null if not available.
 */
function gmtu_get_log() {
    global $joinBlockLog;
    return !empty($joinBlockLog) ? $joinBlockLog : null;
}

/**
 * Log an info message if logging is available.
 *
 * @since 1.1.0
 *
 * @param string $message The message to log.
 * @return void
 */
function gmtu_log_info($message) {
    $log = gmtu_get_log();
    if ($log) {
        $log->info($message);
    }
}

/**
 * Log a warning message if logging is available.
 *
 * @since 1.1.0
 *
 * @param string $message The message to log.
 * @return void
 */
function gmtu_log_warning($message) {
    $log = gmtu_get_log();
    if ($log) {
        $log->warning($message);
    }
}

/**
 * Get outcode from postcode using postcodes.io API.
 *
 * Uses WordPress transients to cache results for 7 days to avoid expensive API calls.
 *
 * @since 0.1.0
 *
 * @param string $postcode The postcode to lookup.
 * @return string|null The outcode or null if not found/error.
 */
function gmtu_get_postcode_outcode($postcode) {
    if (empty($postcode)) {
        return null;
    }
    
    // Normalize postcode for cache key (uppercase, remove spaces)
    $normalizedPostcode = strtoupper(str_replace(' ', '', trim($postcode)));
    $cacheKey = 'gmtu_postcode_outcode_' . md5($normalizedPostcode);
    
    // Try to get from cache first
    $cachedOutcode = get_transient($cacheKey);
    if ($cachedOutcode !== false) {
        gmtu_log_info("Postcode outcode cache hit for: $postcode -> $cachedOutcode");
        return $cachedOutcode;
    }
    
    // Cache miss - fetch from API
    gmtu_log_info("Postcode outcode cache miss, fetching from API: $postcode");
    
    try {
        $url = "https://api.postcodes.io/postcodes/" . rawurlencode($postcode);
        $postcodesResponse = @file_get_contents($url);
        
        if (empty($postcodesResponse)) {
            return null;
        }
        
        $postcodesData = json_decode($postcodesResponse, true);
        $outcode = $postcodesData["result"]["outcode"] ?? null;
        
        if ($outcode) {
            $outcode = trim($outcode);
            
            // Cache the result for 7 days (604800 seconds)
            // Postcodes don't change, so a long cache is safe
            set_transient($cacheKey, $outcode, 7 * DAY_IN_SECONDS);
            
            gmtu_log_info("Cached postcode outcode: $postcode -> $outcode");
        }
        
        return $outcode;
    } catch (\Exception $e) {
        gmtu_log_warning("Could not get outcode from postcode $postcode: " . $e->getMessage());
        return null;
    }
}

/**
 * Validate postcode is within Manchester coverage area during lookup.
 *
 * Filters the postcode validation response to reject postcodes outside
 * the Greater Manchester coverage area defined in the branch map.
 *
 * @since 0.1.0
 *
 * @param array           $response  Initial response array.
 * @param string          $postcode  The postcode being validated.
 * @param array           $addresses Available addresses from lookup.
 * @param WP_REST_Request $request   The full request object.
 * @return array Modified response array, or error array with 'status' and 'message' keys.
 */
add_filter("ck_join_flow_postcode_validation", function ($response, $postcode, $addresses, $request) use ($branchMap, $outOfAreaLookupMessage) {
    $outcode = gmtu_get_postcode_outcode($postcode);
    
    if (!$outcode) {
        // If we can't determine outcode, allow through
        return $response;
    }
    
    // Check if postcode exists in our branch map
    if (!array_key_exists($outcode, $branchMap)) {
        // Postcode not in our coverage area - return error
        return [
            'status' => 'bad_postcode',
            'message' => $outOfAreaLookupMessage
        ];
    }
    
    return $response; // Allow if valid
}, 10, 4);

/**
 * Block form submission for postcodes outside coverage area.
 *
 * Filters the step response to completely block form progression
 * if the postcode is outside the Greater Manchester coverage area.
 *
 * @since 0.1.0
 *
 * @param array $response Initial response array.
 * @param array $data     Full form submission data.
 * @return array Modified response array, or error array with 'status' and 'message' keys.
 */
add_filter("ck_join_flow_step_response", function ($response, $data) use ($branchMap, $outOfAreaSubmissionMessage) {
    $postcode = $data['addressPostcode'] ?? '';
    $outcode = gmtu_get_postcode_outcode($postcode);
    
    if (!$outcode) {
        // If we can't determine outcode, allow through
        return $response;
    }
    
    // Check if postcode exists in our branch map
    if (!array_key_exists($outcode, $branchMap)) {
        // Postcode not in our coverage area - block submission
        return [
            'status' => 'blocked',
            'message' => $outOfAreaSubmissionMessage
        ];
    }
    
    return $response; // Allow if valid
}, 10, 2);

/**
 * Populate "branch" custom field from postcode.
 *
 * Filters the join data before processing to automatically assign
 * a branch based on the member's postcode outcode.
 *
 * @since 0.1.0
 *
 * @param array $data Join form data.
 * @return array Modified join form data with branch assignment.
 */
add_filter("ck_join_flow_pre_handle_join", function ($data) use ($branchMap) {
    if (!empty($data["branch"])) {
        // Don't overwrite explicitly set branch
        gmtu_log_info("Branch already set, returning early: " . $data["branch"]);
        return $data;
    }
    
    if (empty($data["addressPostcode"])) {
        return $data;
    }
    
    $postcode = $data["addressPostcode"];
    $outcode = gmtu_get_postcode_outcode($postcode);
    
    if (!$outcode) {
        gmtu_log_warning("Could not determine outcode from postcode: $postcode");
        return $data;
    }
    
    $branch = $branchMap[$outcode] ?? null;
    $data["branch"] = $branch;
    
    if ($branch) {
        gmtu_log_info("Assigned branch '$branch' for postcode $postcode (outcode: $outcode)");
    } else if (array_key_exists($outcode, $branchMap)) {
        gmtu_log_info("Outcode $outcode in branch map but no branch assigned (null value) for postcode $postcode");
    } else {
        gmtu_log_warning("Outcode $outcode not found in branch map for postcode $postcode");
    }
    
    // Ensure "branch" custom field exists in config
    $customFields = $data["customFieldsConfig"] ?? [];
    $customFieldExists = false;
    foreach ($customFields as $field) {
        if ($field["id"] === "branch") {
            $customFieldExists = true;
            break;
        }
    }
    if (!$customFieldExists) {
        $customFields[] = [
            "id" => "branch",
            "field_type" => "text"
        ];
    }
    $data["customFieldsConfig"] = $customFields;
    
    // Also set the branch value in the custom fields data
    if (!isset($data["customFields"])) {
        $data["customFields"] = [];
    }
    $data["customFields"]["branch"] = $branch;

    gmtu_log_info("=== ck_join_flow_pre_handle_join FILTER END ===");
    gmtu_log_info("Branch set to: " . ($branch ?? "NULL"));
    gmtu_log_info("data['branch']: " . ($data["branch"] ?? "NOT SET"));
    gmtu_log_info("data['customFields']['branch']: " . ($data["customFields"]["branch"] ?? "NOT SET"));
    gmtu_log_info("MembershipPlan still present: " . (isset($data["membershipPlan"]) ? "YES - " . json_encode($data["membershipPlan"]) : "NO"));
    gmtu_log_info("Full outgoing data: " . json_encode($data));
    
    return $data;
});

/**
 * Add branch as a tag when tagging members.
 *
 * Filters the tags being added to services (Mailchimp, Action Network)
 * to include the member's assigned branch as a tag.
 *
 * @since 0.1.0
 *
 * @param array  $addTags Array of tag names to add.
 * @param array  $data    Complete member data.
 * @param string $service Service name ('mailchimp' or 'action_network').
 * @return array Modified tags array with branch added.
 */
add_filter('ck_join_flow_add_tags', function ($addTags, $data, $service) {
    gmtu_log_info("=== ck_join_flow_add_tags FILTER for $service ===");
    gmtu_log_info("Data keys: " . implode(", ", array_keys($data)));
    gmtu_log_info("Branch in data['branch']: " . ($data['branch'] ?? "NOT SET"));
    gmtu_log_info("Branch in data['customFields']['branch']: " . ($data['customFields']['branch'] ?? "NOT SET"));
    gmtu_log_info("Full data structure: " . json_encode($data));
    
    $branch = $data['branch'] ?? null;
    $memberEmail = $data['email'] ?? 'unknown';
    
    if (!empty($branch)) {
        $addTags[] = $branch;
        gmtu_log_info("Added branch tag '$branch' to $service for member $memberEmail");
    } else {
        gmtu_log_warning("No branch found for member $memberEmail when tagging in $service");
    }
    
    return $addTags;
}, 10, 3);

/**
 * Get formatted member details from registration data.
 *
 * @since 0.1.0
 *
 * @param array $data Registration data.
 * @return array Member details array with keys: name, email, postcode, branch, payment_level.
 */
function gmtu_get_member_details($data) {
    global $branchMap;
    
    gmtu_log_info("=== gmtu_get_member_details FUNCTION START ===");
    gmtu_log_info("Data keys: " . implode(", ", array_keys($data)));
    gmtu_log_info("Checking branch in multiple locations:");
    gmtu_log_info("  - data['branch']: " . ($data['branch'] ?? "NOT SET"));
    gmtu_log_info("  - data['customFields']['branch']: " . ($data['customFields']['branch'] ?? "NOT SET"));
    gmtu_log_info("Checking membershipPlan:");
    gmtu_log_info("  - data['membershipPlan'] exists: " . (isset($data['membershipPlan']) ? "YES" : "NO"));
    if (isset($data['membershipPlan'])) {
        gmtu_log_info("  - data['membershipPlan']: " . json_encode($data['membershipPlan']));
    }
    gmtu_log_info("Available plan data: planId=" . ($data['planId'] ?? "NOT SET") . ", membership=" . ($data['membership'] ?? "NOT SET"));
    gmtu_log_info("Full data structure: " . json_encode($data));
    
    // Try to get branch from multiple possible locations
    $branch = $data['branch'] ?? $data['customFields']['branch'] ?? null;
    
    // If branch not found, recalculate from postcode
    if (empty($branch) && !empty($data['addressPostcode'])) {
        gmtu_log_info("Branch not found in data, recalculating from postcode: " . $data['addressPostcode']);
        $postcode = $data['addressPostcode'];
        $outcode = gmtu_get_postcode_outcode($postcode);
        
        if ($outcode && isset($branchMap[$outcode])) {
            $branch = $branchMap[$outcode];
            gmtu_log_info("Recalculated branch from postcode: $branch (outcode: $outcode)");
        } else {
            gmtu_log_warning("Could not recalculate branch from postcode: $postcode (outcode: " . ($outcode ?? "NULL") . ")");
        }
    }
    
    // Try to get or construct membershipPlan
    $membershipPlan = $data['membershipPlan'] ?? null;
    
    // If membershipPlan is not available, try to construct it from available data
    if (empty($membershipPlan)) {
        // Check if we have planId or membership to work with
        $planId = $data['planId'] ?? $data['membership'] ?? null;
        if (!empty($planId)) {
            gmtu_log_info("Constructing membershipPlan from planId/membership: $planId");
            // Create a basic plan structure - the actual amount/frequency might need to be looked up
            // For now, we'll just note the plan ID
            $membershipPlan = [
                'id' => $planId,
                'name' => $planId,
            ];
        }
    }
    
    // Format payment level
    $paymentLevel = 'N/A';

    if (!empty($membershipPlan)) {
        $plan = $membershipPlan;
        $amount = $plan['amount'] ?? 0;
        $currency = $plan['currency'] ?? 'GBP';
        $frequency = $plan['frequency'] ?? '';
        
        // If we have amount, format it
        if ($amount > 0) {
            $currencySymbol = $currency === 'GBP' ? '£' : $currency;
            $paymentLevel = $currencySymbol . number_format($amount / 100, 2);
            
            if ($frequency) {
                $paymentLevel .= ' / ' . $frequency;
            }
        } else {
            // Just show the plan name if we don't have amount
            $paymentLevel = $plan['name'] ?? $plan['id'] ?? 'Plan: ' . ($data['planId'] ?? $data['membership'] ?? 'Unknown');
        }
        
        gmtu_log_info("Payment level calculated: $paymentLevel");
    } else {
        // Fallback: try to show planId or membership
        $fallbackPlan = $data['planId'] ?? $data['membership'] ?? null;
        if ($fallbackPlan) {
            $paymentLevel = 'Plan: ' . $fallbackPlan;
        }
        gmtu_log_warning("No membershipPlan found in data, using fallback: $paymentLevel");
    }
    
    gmtu_log_info("Final branch value: " . ($branch ?? "NULL"));
    gmtu_log_info("Final payment level: $paymentLevel");
    gmtu_log_info("=== gmtu_get_member_details FUNCTION END ===");
    
    return [
        'name' => trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? '')),
        'email' => $data['email'] ?? 'N/A',
        'postcode' => $data['addressPostcode'] ?? 'N/A',
        'branch' => $branch,
        'payment_level' => $paymentLevel,
    ];
}

/**
 * Build email body with member details.
 *
 * @since 0.1.0
 *
 * @param string $introMessage  Introductory message.
 * @param array  $memberDetails Member details array.
 * @return string Formatted email body.
 */
function gmtu_build_email_body($introMessage, $memberDetails) {
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
function gmtu_send_notification_emails($recipients, $subject, $body) {
    foreach ($recipients as $recipient) {
        wp_mail($recipient, $subject, $body);
    }
}

/**
 * Send general notification email on successful registration.
 *
 * Fires when a member successfully completes registration,
 * sending a notification to the configured admin email addresses.
 *
 * @since 0.1.0
 *
 * @param array $data Registration data including member details.
 */
add_action("ck_join_flow_success", function ($data) use ($successNotificationEmails, $successNotificationSubject, $successNotificationMessage) {
    global $joinBlockLog;
    
    if (!empty($joinBlockLog)) {
        $joinBlockLog->info("=== ck_join_flow_success ACTION (priority 10) START ===");
        $joinBlockLog->info("Data keys: " . implode(", ", array_keys($data)));
        $joinBlockLog->info("Branch in data['branch']: " . ($data['branch'] ?? "NOT SET"));
        $joinBlockLog->info("Branch in data['customFields']['branch']: " . ($data['customFields']['branch'] ?? "NOT SET"));
        $joinBlockLog->info("MembershipPlan in data: " . (isset($data['membershipPlan']) ? json_encode($data['membershipPlan']) : "NOT SET"));
        $joinBlockLog->info("Full data received: " . json_encode($data));
    }
    
    if (empty($successNotificationEmails)) {
        return;
    }
    
    $memberDetails = gmtu_get_member_details($data);
    $emailBody = gmtu_build_email_body($successNotificationMessage, $memberDetails);
    
    gmtu_send_notification_emails($successNotificationEmails, $successNotificationSubject, $emailBody);
}, 10, 1);

/**
 * Send branch-specific notification email.
 *
 * Fires when a member successfully completes registration,
 * sending a notification to the assigned branch or alerting
 * admins if no branch email is configured.
 *
 * @since 0.1.0
 *
 * @param array $data Registration data including member details.
 */
add_action("ck_join_flow_success", function ($data) use ($branchEmailMap, $successNotificationEmails) {
    global $joinBlockLog;
    
    if (!empty($joinBlockLog)) {
        $joinBlockLog->info("=== ck_join_flow_success ACTION (priority 20) START ===");
        $joinBlockLog->info("Data keys: " . implode(", ", array_keys($data)));
        $joinBlockLog->info("Branch in data['branch']: " . ($data['branch'] ?? "NOT SET"));
        $joinBlockLog->info("Branch in data['customFields']['branch']: " . ($data['customFields']['branch'] ?? "NOT SET"));
        $joinBlockLog->info("MembershipPlan in data: " . (isset($data['membershipPlan']) ? json_encode($data['membershipPlan']) : "NOT SET"));
        $joinBlockLog->info("Full data received: " . json_encode($data));
    }
    
    $memberDetails = gmtu_get_member_details($data);
    $memberBranch = $memberDetails['branch'];
    
    // If no branch assigned, notify admin
    if (empty($memberBranch)) {
        if (!empty($successNotificationEmails)) {
            $intro = "A new member has joined but no branch was assigned.\n\nPlease review and assign a branch manually.";
            $emailBody = gmtu_build_email_body($intro, $memberDetails);
            gmtu_send_notification_emails($successNotificationEmails, 'GMTU Member Registration - No Branch Assigned', $emailBody);
        }
        return;
    }
    
    // Check if branch has an email configured
    $branchEmail = $branchEmailMap[$memberBranch] ?? null;
    
    if (empty($branchEmail)) {
        // No email configured for this branch, notify admin
        if (!empty($successNotificationEmails)) {
            $intro = "A new member has joined the {$memberBranch} branch, but no email is configured for this branch.\n\nPlease configure a branch email or contact the branch directly.";
            $emailBody = gmtu_build_email_body($intro, $memberDetails);
            gmtu_send_notification_emails($successNotificationEmails, "GMTU Member Registration - No Email for {$memberBranch}", $emailBody);
        }
        return;
    }
    
    // Send notification to branch
    $intro = "A new member has joined your branch!";
    $emailBody = gmtu_build_email_body($intro, $memberDetails);
    wp_mail($branchEmail, "New Member Joined {$memberBranch} Branch", $emailBody);
}, 20, 1);
