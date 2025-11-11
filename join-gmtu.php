<?php

/**
 * Plugin Name:     Common Knowledge Join Flow GMTU Extensions
 * Description:     Common Knowledge join flow plugin GMTU extensions.
 * Version:         0.1.0
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     common-knowledge-join-flow
 * License: GPLv2 or later
 */

if (! defined('ABSPATH')) exit; // Exit if accessed directly

// Error messages for out-of-area postcodes
$outOfAreaLookupMessage = "<p>Sorry, this postcode is outside of Greater Manchester, which the union covers, and you won't be able to join the union.</p>";
$outOfAreaSubmissionMessage = '<h3>Sorry</h3><p>We’re only able to support tenants living in the Greater Manchester area.</p><p>If you’re based elsewhere in the UK, there are other local tenant unions that may be able to help, and for urgent housing issues you can also contact <a href="https://www.shelter.org.uk/Shelter">Shelter</a> or <a href="https://www.crisis.org.uk/">Crisis</a>.</p><p>We’re sorry we can’t assist directly, but we hope you can get the support you need quickly.</p>';

// Success notification settings
$successNotificationEmails = ['hello@commonknowledge.coop', 'info@tenantsunion.org.uk'];
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
 * Get outcode from postcode using postcodes.io API.
 *
 * @since 0.1.0
 *
 * @param string $postcode The postcode to lookup.
 * @return string|null The outcode or null if not found/error.
 */
function gmtu_get_postcode_outcode($postcode) {
    global $joinBlockLog;
    
    if (empty($postcode)) {
        return null;
    }
    
    try {
        $url = "https://api.postcodes.io/postcodes/" . rawurlencode($postcode);
        $postcodesResponse = @file_get_contents($url);
        
        if (empty($postcodesResponse)) {
            return null;
        }
        
        $postcodesData = json_decode($postcodesResponse, true);
        $outcode = $postcodesData["result"]["outcode"] ?? null;
        
        return $outcode ? trim($outcode) : null;
    } catch (\Exception $e) {
        if (!empty($joinBlockLog)) {
            $joinBlockLog->warning("Could not get outcode from postcode $postcode: " . $e->getMessage());
        }
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
        return $data;
    }

    if (empty($data["addressPostcode"])) {
        return $data;
    }

    $outcode = gmtu_get_postcode_outcode($data["addressPostcode"]);
    
    if (!$outcode) {
        return $data;
    }

    $data["branch"] = $branchMap[$outcode] ?? null;

    // Ensure "branch" custom field exists
    $customFields = $data["customFieldsConfig"] ?? [];
    $customField = null;
    foreach ($data["customFieldsConfig"] as $field) {
        if ($field["id"] === "branch") {
            $customField = $field;
            break;
        }
    }
    if (!$customField) {
        $customFields[] = [
            "id" => "branch",
            "field_type" => "text"
        ];
    }
    $data["customFieldsConfig"] = $customFields;

    return $data;
});

/**
 * Get formatted member details from registration data.
 *
 * @since 0.1.0
 *
 * @param array $data Registration data.
 * @return array Member details array with keys: name, email, postcode, branch.
 */
function gmtu_get_member_details($data) {
    return [
        'name' => trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? '')),
        'email' => $data['email'] ?? 'N/A',
        'postcode' => $data['addressPostcode'] ?? 'N/A',
        'branch' => $data['branch'] ?? null,
    ];
}

/**
 * Build email body with member details.
 *
 * @since 0.1.0
 *
 * @param string $introMessage       Introductory message.
 * @param array  $memberDetails      Member details array.
 * @param bool   $includeZetkinLink  Optional. Whether to include Zetkin authorization link. Default true.
 * @return string Formatted email body.
 */
function gmtu_build_email_body($introMessage, $memberDetails, $includeZetkinLink = true) {
    $emailBody = $introMessage . "\n\n";
    $emailBody .= "Member Details:\n";
    $emailBody .= "Name: " . $memberDetails['name'] . "\n";
    $emailBody .= "Email: " . $memberDetails['email'] . "\n";
    $emailBody .= "Postcode: " . $memberDetails['postcode'] . "\n";
    $emailBody .= "Branch: " . ($memberDetails['branch'] ?: 'No branch found') . "\n";
    
    if ($includeZetkinLink) {
        $emailBody .= "\nBefore the new member can be found in Zetkin, they need to be authorised. It takes two seconds.\n";
        $emailBody .= "https://app.zetkin.org/organize/1050/people/incoming\n";
    }
    
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
