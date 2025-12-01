<?php
/**
 * Member tagging functionality.
 *
 * Adds branch information as tags when members are tagged in external services
 * like Mailchimp or Action Network.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

use function CommonKnowledge\JoinBlock\Organisation\GMTU\log_info;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\log_warning;

/**
 * Register tagging hooks.
 *
 * @since 1.2.0
 *
 * @return void
 */
function register_tagging() {
    // Add branch as a tag when tagging members
    add_filter('ck_join_flow_add_tags', function ($addTags, $data, $service) {
        log_info("=== ck_join_flow_add_tags FILTER for $service ===");
        log_info("Data keys: " . implode(", ", array_keys($data)));
        log_info("Branch in data['branch']: " . ($data['branch'] ?? "NOT SET"));
        log_info("Branch in data['customFields']['branch']: " . ($data['customFields']['branch'] ?? "NOT SET"));
        log_info("Full data structure: " . json_encode($data));
        
        $branch = $data['branch'] ?? null;
        $memberEmail = $data['email'] ?? 'unknown';
        
        if (!empty($branch)) {
            $addTags[] = $branch;
            log_info("Added branch tag '$branch' to $service for member $memberEmail");
        } else {
            log_warning("No branch found for member $memberEmail when tagging in $service");
        }
        
        return $addTags;
    }, 10, 3);
}

