<?php
/**
 * Success notification functionality.
 *
 * Handles sending notifications when members successfully complete registration,
 * including admin notifications and branch-specific notifications.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Register success notification hooks.
 *
 * @since 1.2.0
 *
 * @param array $config Configuration array with notification settings.
 * @return void
 */
function register_notifications($config) {
    // Send general admin notification
    add_action("ck_join_flow_success", function ($data) use ($config) {
        log_info("=== ck_join_flow_success ACTION (priority 10) START ===");
        log_info("Data keys: " . implode(", ", array_keys($data)));
        log_info("Branch in data['branch']: " . ($data['branch'] ?? "NOT SET"));
        log_info("Branch in data['customFields']['branch']: " . ($data['customFields']['branch'] ?? "NOT SET"));
        log_info("MembershipPlan in data: " . (isset($data['membershipPlan']) ? json_encode($data['membershipPlan']) : "NOT SET"));
        log_info("Full data received: " . json_encode($data));
        
        $memberDetails = get_member_details($data);
        send_admin_notification($memberDetails, $config);
    }, 10, 1);
    
    // Send branch-specific notification
    add_action("ck_join_flow_success", function ($data) use ($config) {
        log_info("=== ck_join_flow_success ACTION (priority 20) START ===");
        log_info("Data keys: " . implode(", ", array_keys($data)));
        log_info("Branch in data['branch']: " . ($data['branch'] ?? "NOT SET"));
        log_info("Branch in data['customFields']['branch']: " . ($data['customFields']['branch'] ?? "NOT SET"));
        log_info("MembershipPlan in data: " . (isset($data['membershipPlan']) ? json_encode($data['membershipPlan']) : "NOT SET"));
        log_info("Full data received: " . json_encode($data));
        
        $memberDetails = get_member_details($data);
        send_branch_notification($memberDetails, $config);
    }, 20, 1);
}

