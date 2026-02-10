<?php
/**
 * Member details and email functionality.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Get formatted member details from registration data.
 *
 * @since 0.1.0
 *
 * @param array $data Registration data.
 * @return array Member details array with keys: name, email, postcode, branch, payment_level.
 */
function get_member_details($data) {
    $branchMap = get_branch_map();
    
    log_info("=== get_member_details FUNCTION START ===");
    log_info("Data keys: " . implode(", ", array_keys($data)));
    log_info("Checking branch in multiple locations:");
    log_info("  - data['branch']: " . ($data['branch'] ?? "NOT SET"));
    log_info("  - data['customFields']['branch']: " . ($data['customFields']['branch'] ?? "NOT SET"));
    log_info("Checking membershipPlan:");
    log_info("  - data['membershipPlan'] exists: " . (isset($data['membershipPlan']) ? "YES" : "NO"));
    if (isset($data['membershipPlan'])) {
        log_info("  - data['membershipPlan']: " . json_encode($data['membershipPlan']));
    }
    log_info("Available plan data: planId=" . ($data['planId'] ?? "NOT SET") . ", membership=" . ($data['membership'] ?? "NOT SET"));
    log_info("Full data structure: " . json_encode($data));
    
    // Try to get branch from multiple possible locations
    $branch = $data['branch'] ?? $data['customFields']['branch'] ?? null;
    
    // If branch not found, recalculate from postcode
    if (empty($branch) && !empty($data['addressPostcode'])) {
        log_info("Branch not found in data, recalculating from postcode: " . $data['addressPostcode']);
        $postcode = $data['addressPostcode'];
        $outcode = get_postcode_outcode($postcode);
        
        if ($outcode && isset($branchMap[$outcode])) {
            $branch = $branchMap[$outcode];
            log_info("Recalculated branch from postcode: $branch (outcode: $outcode)");
        } else {
            log_warning("Could not recalculate branch from postcode: $postcode (outcode: " . ($outcode ?? "NULL") . ")");
        }
    }
    
    // Try to get or construct membershipPlan
    $membershipPlan = $data['membershipPlan'] ?? null;
    
    // If membershipPlan is not available, try to construct it from available data
    if (empty($membershipPlan)) {
        // Check if we have planId or membership to work with
        $planId = $data['planId'] ?? $data['membership'] ?? null;
        if (!empty($planId)) {
            log_info("Constructing membershipPlan from planId/membership: $planId");
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
        
        log_info("Payment level calculated: $paymentLevel");
    } else {
        // Fallback: try to show planId or membership
        $fallbackPlan = $data['planId'] ?? $data['membership'] ?? null;
        if ($fallbackPlan) {
            $paymentLevel = 'Plan: ' . $fallbackPlan;
        }
        log_warning("No membershipPlan found in data, using fallback: $paymentLevel");
    }
    
    log_info("Final branch value: " . ($branch ?? "NULL"));
    log_info("Final payment level: $paymentLevel");
    log_info("=== get_member_details FUNCTION END ===");
    
    return [
        'name' => trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? '')),
        'email' => $data['email'] ?? 'N/A',
        'postcode' => $data['addressPostcode'] ?? 'N/A',
        'branch' => $branch,
        'payment_level' => $paymentLevel,
    ];
}


