<?php
/**
 * Branch assignment functionality.
 *
 * Automatically assigns branches to members based on their postcode
 * and ensures branch data is properly stored in custom fields.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Register branch assignment hooks.
 *
 * @since 1.2.0
 *
 * @return void
 */
function register_branch_assignment() {
    $branchMap = get_branch_map();
    
    // Assign branch based on postcode
    add_filter("ck_join_flow_pre_handle_join", function ($data) use ($branchMap) {
        if (!empty($data["branch"])) {
            // Don't overwrite explicitly set branch
            log_info("Branch already set, returning early: " . $data["branch"]);
            return $data;
        }
        
        if (empty($data["addressPostcode"])) {
            return $data;
        }
        
        $postcode = $data["addressPostcode"];
        $outcode = get_postcode_outcode($postcode);
        
        if (!$outcode) {
            log_warning("Could not determine outcode from postcode: $postcode");
            return $data;
        }
        
        $branch = $branchMap[$outcode] ?? null;
        $data["branch"] = $branch;
        
        if ($branch) {
            log_info("Assigned branch '$branch' for postcode $postcode (outcode: $outcode)");
        } else if (array_key_exists($outcode, $branchMap)) {
            log_info("Outcode $outcode in branch map but no branch assigned (null value) for postcode $postcode");
        } else {
            log_warning("Outcode $outcode not found in branch map for postcode $postcode");
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

        log_info("=== ck_join_flow_pre_handle_join FILTER END ===");
        log_info("Branch set to: " . ($branch ?? "NULL"));
        log_info("data['branch']: " . ($data["branch"] ?? "NOT SET"));
        log_info("data['customFields']['branch']: " . ($data["customFields"]["branch"] ?? "NOT SET"));
        log_info("MembershipPlan still present: " . (isset($data["membershipPlan"]) ? "YES - " . json_encode($data["membershipPlan"]) : "NO"));
        log_info("Full outgoing data: " . json_encode($data));
        
        return $data;
    });
}

