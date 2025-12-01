<?php
/**
 * Postcode validation and area coverage checking.
 *
 * Validates that postcodes are within the Greater Manchester coverage area
 * and blocks submissions from outside the coverage area.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_postcode_outcode;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_branch_map;

/**
 * Register postcode validation hooks.
 *
 * @since 1.2.0
 *
 * @param array $config Configuration array with error messages.
 * @return void
 */
function register_postcode_validation($config) {
    $branchMap = get_branch_map();
    
    // Validate postcode during lookup
    add_filter("ck_join_flow_postcode_validation", function ($response, $postcode, $addresses, $request) use ($branchMap, $config) {
        $outcode = get_postcode_outcode($postcode);
        
        if (!$outcode) {
            // If we can't determine outcode, allow through
            return $response;
        }
        
        // Check if postcode exists in our branch map
        if (!array_key_exists($outcode, $branchMap)) {
            // Postcode not in our coverage area - return error
            return [
                'status' => 'bad_postcode',
                'message' => $config['outOfAreaLookupMessage']
            ];
        }
        
        return $response; // Allow if valid
    }, 10, 4);
    
    // Block form submission for out-of-area postcodes
    add_filter("ck_join_flow_step_response", function ($response, $data) use ($branchMap, $config) {
        $postcode = $data['addressPostcode'] ?? '';
        $outcode = get_postcode_outcode($postcode);
        
        if (!$outcode) {
            // If we can't determine outcode, allow through
            return $response;
        }
        
        // Check if postcode exists in our branch map
        if (!array_key_exists($outcode, $branchMap)) {
            // Postcode not in our coverage area - block submission
            return [
                'status' => 'blocked',
                'message' => $config['outOfAreaSubmissionMessage']
            ];
        }
        
        return $response; // Allow if valid
    }, 10, 2);
}

